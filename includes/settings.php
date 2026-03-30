<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Settings page for SVG Map Lite
 * Extracted from svg-map-lite.php with multi-map support
 */

function svgml_render_settings_page( $map_id ) {

    // Process the form if it has been submitted (POST request)
    if ( isset( $_POST['svgml_settings_nonce'] ) ) {

        // Verify the nonce to prevent CSRF (Cross-Site Request Forgery)
        if ( ! wp_verify_nonce( $_POST['svgml_settings_nonce'], 'svgml_save_settings' ) ) {
            echo '<div class="notice notice-error"><p>Beveiligingsfout. Vernieuw de pagina en probeer opnieuw.</p></div>';
        } else {
            // esc_url_raw() removes dangerous characters from a URL but preserves
            // special characters that are valid in URLs (& % ? etc.)
            $json_url = esc_url_raw( trim( $_POST['svgml_json_url'] ?? '' ) );
            update_post_meta( $map_id, '_svgml_json_url', $json_url );

            // sanitize_text_field() removes HTML tags, extra whitespace, and dangerous characters
            $id_field = sanitize_text_field( $_POST['svgml_json_id_field'] ?? 'id' );
            if ( empty( $id_field ) ) {
                $id_field = 'id'; // Default value if empty
            }
            update_post_meta( $map_id, '_svgml_json_id_field', $id_field );

            // JSON Array Key: manual override for nested JSON structures.
            // E.g. if objects are in { "spaces": [...] }, enter 'spaces' here.
            // Leave empty = plugin tries to find array automatically.
            $array_key = sanitize_key( $_POST['svgml_json_array_key'] ?? '' );
            update_post_meta( $map_id, '_svgml_json_array_key', $array_key );
            // Clear cache so new array key takes effect immediately
            delete_transient( 'svgml_json_cache_' . $map_id );

            // ── Source type: 'svg' or 'image' (image + polygons) ─────────────
            // Determines which mode the plugin uses: an SVG file or an
            // image with drawn polygons. Default = 'svg'.
            $source_type = sanitize_text_field( $_POST['svgml_source_type'] ?? 'svg' );
            if ( ! in_array( $source_type, [ 'svg', 'image' ], true ) ) {
                $source_type = 'svg';
            }
            update_post_meta( $map_id, '_svgml_source_type', $source_type );

            // If an SVG attachment ID also came through the hidden field
            $attachment_id = intval( $_POST['svgml_svg_attachment_id'] ?? 0 );
            if ( $attachment_id ) {
                update_post_meta( $map_id, '_svgml_svg_attachment_id', $attachment_id );
            }

            // ── ALWAYS save image attachment ID first ─────────────────────────
            // This field is set by JavaScript when the user picks an image
            // from the media library. We save it regardless of the layers
            // system, because the JS upload handler sets this hidden field
            // directly, and the layers JSON might not yet contain it.
            $image_attachment_id = intval( $_POST['svgml_image_attachment_id'] ?? 0 );
            if ( $image_attachment_id ) {
                update_post_meta( $map_id, '_svgml_image_attachment_id', $image_attachment_id );
            }

            // ── MULTI-LAYER SYSTEM ──────────────────────────────────────────────
            // Process the layers JSON data. This hidden field is always present
            // in the form, so we check if it actually contains layer data.
            $raw_layers_str = stripslashes( $_POST['svgml_layers_json'] ?? '[]' );
            $raw_layers     = json_decode( $raw_layers_str, true );
            $clean_layers   = [];
            $all_poly_ids   = []; // For svgml_svg_ids compatibility

            if ( is_array( $raw_layers ) && ! empty( $raw_layers ) ) {
                foreach ( $raw_layers as $layer ) {
                    // Validate layer data
                    $layer_name   = sanitize_text_field( $layer['name'] ?? '' );
                    $layer_img_id = intval( $layer['image_attachment_id'] ?? 0 );
                    $layer_stroke_color = sanitize_hex_color( $layer['stroke_color'] ?? '#2a9d8f' ) ?: '#2a9d8f';
                    $layer_stroke_width = max( 0.5, min( 10, floatval( $layer['stroke_width'] ?? 1 ) ) );
                    $raw_polygons = $layer['polygons'] ?? [];

                    // Give empty layers a default name
                    if ( empty( $layer_name ) ) $layer_name = 'Laag ' . ( count($clean_layers) + 1 );

                    $clean_polygons = [];
                    if ( is_array( $raw_polygons ) ) {
                        foreach ( $raw_polygons as $poly ) {
                            $poly_id = sanitize_text_field( $poly['id'] ?? '' );
                            $points  = $poly['points'] ?? [];
                            if ( empty( $poly_id ) || ! is_array( $points ) || count( $points ) < 3 ) continue;
                            $clean_points = [];
                            foreach ( $points as $pt ) {
                                $x = floatval( $pt['x'] ?? 0 );
                                $y = floatval( $pt['y'] ?? 0 );
                                $clean_points[] = [ 'x' => round( $x, 6 ), 'y' => round( $y, 6 ) ];
                            }
                            $clean_polygons[] = [ 'id' => $poly_id, 'points' => $clean_points ];
                            $all_poly_ids[] = $poly_id;
                        }
                    }

                    $clean_layers[] = [
                        'name'                => $layer_name,
                        'image_attachment_id' => $layer_img_id,
                        'polygons'            => $clean_polygons,
                        'stroke_color'        => $layer_stroke_color,
                        'stroke_width'        => (string) $layer_stroke_width,
                    ];
                }
            }

            // If layers JSON was empty but we DO have an image, create a
            // default single layer so the image persists after save.
            // This handles the case where the user just uploaded an image
            // but hasn't drawn any polygons yet.
            if ( empty( $clean_layers ) && $image_attachment_id ) {
                $stroke_color = sanitize_hex_color( $_POST['svgml_poly_stroke_color'] ?? '' ) ?: '#2a9d8f';
                $stroke_width = max( 0.5, min( 10, floatval( $_POST['svgml_poly_stroke_width'] ?? 1 ) ) );
                $clean_layers = [ [
                    'name'                => 'Laag 1',
                    'image_attachment_id' => $image_attachment_id,
                    'polygons'            => [],
                    'stroke_color'        => $stroke_color,
                    'stroke_width'        => (string) $stroke_width,
                ] ];
            }

            update_post_meta( $map_id, '_svgml_layers', $clean_layers );

            // Also save single-layer options for backward compatibility
            if ( ! empty( $clean_layers ) ) {
                $first = $clean_layers[0];
                update_post_meta( $map_id, '_svgml_image_attachment_id', $first['image_attachment_id'] );
                update_post_meta( $map_id, '_svgml_polygons', $first['polygons'] );
                update_post_meta( $map_id, '_svgml_poly_stroke_color', $first['stroke_color'] );
                update_post_meta( $map_id, '_svgml_poly_stroke_width', $first['stroke_width'] );
            }

            // All polygon IDs from all layers for Region Mapping
            if ( 'image' === $source_type && ! empty( $all_poly_ids ) ) {
                update_post_meta( $map_id, '_svgml_svg_ids', array_values( array_unique( $all_poly_ids ) ) );
            }

            // ── Also process standalone polygon JSON (legacy single-layer) ──
            // This handles the svgml_poly_stroke_* hidden fields which are
            // always present and may contain updated values from the editor.
            if ( isset( $_POST['svgml_poly_stroke_color'] ) ) {
                $stroke_color = sanitize_hex_color( $_POST['svgml_poly_stroke_color'] );
                if ( $stroke_color ) {
                    update_post_meta( $map_id, '_svgml_poly_stroke_color', $stroke_color );
                }
            }
            if ( isset( $_POST['svgml_poly_stroke_width'] ) ) {
                $stroke_width = floatval( $_POST['svgml_poly_stroke_width'] );
                $stroke_width = max( 0.5, min( 10, $stroke_width ) );
                update_post_meta( $map_id, '_svgml_poly_stroke_width', (string) $stroke_width );
            }

            // ── Layer Switcher Style ─────────────────────────────────────────────
            if ( isset( $_POST['svgml_layer_switcher'] ) ) {
                $switcher = sanitize_text_field( $_POST['svgml_layer_switcher'] );
                if ( ! in_array( $switcher, [ 'buttons', 'dropdown', 'custom' ] ) ) $switcher = 'buttons';
                update_post_meta( $map_id, '_svgml_layer_switcher', $switcher );
            }

            echo '<div class="notice notice-success is-dismissible"><p>Instellingen opgeslagen!</p></div>';
        }
    }

    // Get current saved values for display in the form
    $svg_attachment_id   = get_post_meta( $map_id, '_svgml_svg_attachment_id', true ) ?: '';
    $svg_url             = $svg_attachment_id ? wp_get_attachment_url( $svg_attachment_id ) : '';
    $svg_ids             = get_post_meta( $map_id, '_svgml_svg_ids', true ) ?: [];
    $json_url            = get_post_meta( $map_id, '_svgml_json_url', true ) ?: '';
    $json_id_field       = get_post_meta( $map_id, '_svgml_json_id_field', true ) ?: 'id';
    $json_array_key      = get_post_meta( $map_id, '_svgml_json_array_key', true ) ?: '';

    // ── Polygon-mode variables ──────────────────────────────────────────────
    $source_type         = get_post_meta( $map_id, '_svgml_source_type', true ) ?: 'svg';
    $image_attachment_id = get_post_meta( $map_id, '_svgml_image_attachment_id', true ) ?: '';
    $image_url           = $image_attachment_id ? wp_get_attachment_url( $image_attachment_id ) : '';
    $polygons            = get_post_meta( $map_id, '_svgml_polygons', true ) ?: [];
    $poly_stroke_color   = get_post_meta( $map_id, '_svgml_poly_stroke_color', true ) ?: '#2a9d8f';
    $poly_stroke_width   = get_post_meta( $map_id, '_svgml_poly_stroke_width', true ) ?: '1';
    $layers              = get_post_meta( $map_id, '_svgml_layers', true ) ?: [];
    $layer_switcher      = get_post_meta( $map_id, '_svgml_layer_switcher', true ) ?: 'buttons';

    ?>
    <div class="wrap svgml-admin-wrap">
        <h1>
            <span class="dashicons dashicons-location-alt"></span>
            SVG Map Lite – Instellingen
        </h1>

        <form method="post" action="">
            <?php
            // wp_nonce_field() generates a hidden input field with the nonce value.
            // The first parameter is the action name, the second is the name of the input field.
            wp_nonce_field( 'svgml_save_settings', 'svgml_settings_nonce' );
            ?>

            <!-- ─── SECTION 1: MAP SOURCE ─────────────────────────────────── -->
            <div class="svgml-section">
                <h2>1. Map Source</h2>
                <p class="svgml-description">
                    Choose how your map is built: upload an <strong>SVG</strong> with built-in region IDs,
                    or upload a regular <strong>image</strong> (JPG/PNG/WebP) and draw areas on it yourself.
                </p>

                <!-- ── Source type choice ─────────────────────────────────────── -->
                <table class="form-table">
                    <tr>
                        <th>Source Type</th>
                        <td>
                            <fieldset>
                                <label style="display:inline-flex; align-items:center; gap:6px; margin-right:20px; cursor:pointer;">
                                    <input type="radio" name="svgml_source_type" value="svg"
                                        <?php checked( $source_type, 'svg' ); ?>>
                                    <span class="dashicons dashicons-media-code" style="color:var(--svgml-red,#2a9d8f)"></span>
                                    SVG File
                                </label>
                                <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                    <input type="radio" name="svgml_source_type" value="image"
                                        <?php checked( $source_type, 'image' ); ?>>
                                    <span class="dashicons dashicons-format-image" style="color:var(--svgml-red,#2a9d8f)"></span>
                                    Image + Polygons
                                </label>
                            </fieldset>
                            <p class="description">
                                Bij <em>SVG</em> leest de plugin automatisch de ID-attributen uit het bestand.<br>
                                Bij <em>Afbeelding + Polygonen</em> teken je zelf interactieve vlakken over een foto
                                en geef je elk vlak een ID.
                            </p>
                        </td>
                    </tr>
                </table>

                <!-- ── SVG UPLOAD (zichtbaar als source_type = 'svg') ────────── -->
                <div id="svgml-source-svg" class="svgml-source-panel" <?php if ( 'svg' !== $source_type ) echo 'style="display:none"'; ?>>
                    <h3 style="margin:0 0 8px">SVG Bestand</h3>
                    <p class="svgml-description" style="margin-top:0">
                        Upload een SVG waarvan de vlakken/regio's een <code>id</code>-attribuut hebben.
                        Bijv. <code>&lt;path id="amsterdam" .../&gt;</code>
                    </p>
                    <table class="form-table" style="margin-top:0">
                        <tr>
                            <th><label>SVG Bestand</label></th>
                            <td>
                                <!-- Verborgen input voor het WordPress Media attachment-ID -->
                                <input type="hidden"
                                       id="svgml_svg_attachment_id"
                                       name="svgml_svg_attachment_id"
                                       value="<?php echo esc_attr( $svg_attachment_id ); ?>">

                                <!-- Preview van de huidig geselecteerde SVG -->
                                <div id="svgml-svg-preview" class="svgml-svg-preview">
                                    <?php if ( $svg_url ) : ?>
                                        <img src="<?php echo esc_url( $svg_url ); ?>" alt="Huidig geselecteerde SVG">
                                    <?php else : ?>
                                        <div class="svgml-no-svg">
                                            <span class="dashicons dashicons-format-image"></span>
                                            <p>Nog geen SVG geselecteerd</p>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="svgml-upload-buttons">
                                    <button type="button" id="svgml-upload-btn" class="button button-secondary">
                                        <span class="dashicons dashicons-upload"></span>
                                        <?php echo $svg_url ? 'SVG wijzigen' : 'SVG selecteren'; ?>
                                    </button>
                                    <?php if ( $svg_url ) : ?>
                                        <button type="button" id="svgml-remove-svg" class="button button-link-delete">
                                            SVG verwijderen
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <div id="svgml-ids-status" class="svgml-ids-status">
                                    <?php if ( ! empty( $svg_ids ) ) : ?>
                                        <div class="svgml-status-box svgml-status-success">
                                            <strong>✓ <?php echo count( $svg_ids ); ?> regio's gevonden:</strong>
                                            <code><?php echo esc_html( implode( ', ', $svg_ids ) ); ?></code>
                                            <br>
                                            <a href="<?php echo admin_url( 'admin.php?page=svgml-mapping&map_id=' . $map_id ); ?>">
                                                → Ga naar Regio Koppeling
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ── AFBEELDING + POLYGON EDITOR (zichtbaar als source_type = 'image') ── -->
                <div id="svgml-source-image" class="svgml-source-panel" <?php if ( 'image' !== $source_type ) echo 'style="display:none"'; ?>>
                    <h3 style="margin:0 0 8px">Afbeelding + Polygonen</h3>
                    <p class="svgml-description" style="margin-top:0">
                        Upload een afbeelding (JPG, PNG of WebP) en teken interactieve polygonen.
                        Geef elk vlak een uniek ID dat je later koppelt aan de JSON feed.
                    </p>

                    <!-- Lagen-tabs: worden dynamisch gevuld door polygon-editor.js -->
                    <div id="svgml-layer-tabs-container"></div>
                    <!-- Waarschuwing bij dubbele polygon-IDs over lagen -->
                    <div id="svgml-duplicate-warning" class="svgml-duplicate-warning" style="display:none"></div>

                    <table class="form-table" style="margin-top:0">
                        <tr>
                            <th><label>Achtergrondafbeelding</label></th>
                            <td>
                                <!-- Verborgen input voor het afbeelding attachment-ID -->
                                <input type="hidden"
                                       id="svgml_image_attachment_id"
                                       name="svgml_image_attachment_id"
                                       value="<?php echo esc_attr( $image_attachment_id ); ?>">

                                <div class="svgml-upload-buttons" style="margin-bottom:12px">
                                    <button type="button" id="svgml-upload-image-btn" class="button button-secondary">
                                        <span class="dashicons dashicons-format-image"></span>
                                        <?php echo $image_url ? 'Afbeelding wijzigen' : 'Afbeelding selecteren'; ?>
                                    </button>
                                    <?php if ( $image_url ) : ?>
                                        <button type="button" id="svgml-remove-image" class="button button-link-delete">
                                            Afbeelding verwijderen
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <!-- ── Polygon Editor Canvas ───────────────────────────────── -->
                    <div id="svgml-polygon-editor-wrap" class="svgml-polygon-editor-wrap" <?php if ( ! $image_url ) echo 'style="display:none"'; ?>>
                        <div class="svgml-polygon-editor-columns">
                            <div class="svgml-polygon-editor-sidebar">
                                <!-- Toolbar boven het canvas -->
                                <div class="svgml-polygon-toolbar">
                                    <!-- Teken-gereedschappen -->
                                    <button type="button" id="svgml-poly-draw" class="button button-primary" title="Nieuw vlak tekenen">
                                        <span class="dashicons dashicons-edit"></span> Teken
                                    </button>
                                    <button type="button" id="svgml-poly-edit" class="button button-secondary" title="Bewerk punten van een vlak – versleep of verwijder punten">
                                        <span class="dashicons dashicons-move"></span> Bewerk punten
                                    </button>
                                    <button type="button" id="svgml-poly-done" class="button button-secondary" style="display:none" title="Voltooi het huidige vlak">
                                        ✓ Klaar
                                    </button>
                                    <button type="button" id="svgml-poly-cancel" class="button button-secondary" style="display:none" title="Annuleer huidige tekening">
                                        ✕ Annuleer
                                    </button>
                                    <button type="button" id="svgml-poly-delete" class="button svgml-btn-coral" title="Verwijder geselecteerd vlak">
                                        <span class="dashicons dashicons-trash"></span> Verwijder
                                    </button>

                                    <span class="svgml-toolbar-sep"></span>

                                    <!-- Zoom-gereedschappen -->
                                    <button type="button" id="svgml-zoom-in" class="button button-small" title="Inzoomen (scroll-wiel of +)">
                                        <span class="dashicons dashicons-plus-alt2"></span>
                                    </button>
                                    <span class="svgml-zoom-level" id="svgml-zoom-level">100%</span>
                                    <button type="button" id="svgml-zoom-out" class="button button-small" title="Uitzoomen (scroll-wiel of -)">
                                        <span class="dashicons dashicons-minus"></span>
                                    </button>
                                    <button type="button" id="svgml-zoom-reset" class="button button-small" title="Reset zoom naar 100%">
                                        1:1
                                    </button>

                                    <span class="svgml-toolbar-sep"></span>

                                    <!-- Snap toggle -->
                                    <label class="svgml-snap-toggle" title="Magnetisch vastklikken aan punten van naastgelegen polygonen">
                                        <input type="checkbox" id="svgml-snap-toggle" checked>
                                        🧲 Snap
                                    </label>

                                    <span class="svgml-toolbar-sep"></span>

                                    <!-- Lijnstijl-instellingen -->
                                    <span class="svgml-stroke-controls">
                                        <label title="Lijnkleur van polygonen">
                                            Lijn
                                            <input type="color" id="svgml-stroke-color" value="<?php echo esc_attr( $poly_stroke_color ); ?>">
                                        </label>
                                        <label title="Lijndikte in pixels">
                                            Dikte
                                            <input type="number" id="svgml-stroke-width" value="<?php echo esc_attr( $poly_stroke_width ); ?>" min="0.5" max="10" step="0.5">
                                        </label>
                                    </span>

                                    <!-- Status tekst (rechts uitgelijnd) -->
                                    <span class="svgml-poly-status" id="svgml-poly-status"></span>
                                </div>

                                <!-- Vlakkenlijst: overzicht van alle getekende polygonen -->
                                <div class="svgml-polygon-list-wrap">
                                    <h4 style="margin:14px 0 6px">Getekende vlakken</h4>
                                    <table class="wp-list-table widefat fixed striped" id="svgml-polygon-list">
                                        <thead>
                                            <tr>
                                                <th style="width:35%">ID</th>
                                                <th style="width:35%">Punten</th>
                                                <th style="width:30%">Acties</th>
                                            </tr>
                                        </thead>
                                        <tbody id="svgml-polygon-tbody">
                                            <!-- Wordt gevuld door JavaScript -->
                                        </tbody>
                                    </table>
                                    <p class="description" style="margin-top:8px">
                                        <strong>Tips:</strong> Klik op een vlak om het te selecteren.
                                        Gebruik <em>Bewerk punten</em> om hoekpunten te verslepen of te verwijderen
                                        (<span class="svgml-kbd">Backspace</span> op een geselecteerd punt).
                                        Zoom met <span class="svgml-kbd">Ctrl</span>+<span class="svgml-kbd">scroll</span>
                                        of de knoppen. Sleep met de <em>rechtermuisknop</em> om te pannen bij ingezoomde afbeelding.
                                        🧲 Snap klikt punten vast aan naastgelegen vlakken.
                                        Klik op een <em>rand</em> van een polygon in bewerk-modus om een nieuw punt in te voegen.
                                    </p>
                                </div>
                            </div>

                            <div class="svgml-polygon-editor-main">
                                <div id="svgml-polygon-canvas-wrap" style="position:relative; border:1px solid var(--svgml-border,#dce5e3); border-radius:6px; overflow:hidden; background:#f5f5f5;">
                                    <canvas id="svgml-polygon-canvas"></canvas>
                                    <div class="svgml-polygon-controls-overlay">
                                        <strong>Controls</strong>
                                        <ul>
                                            <li>Click to place points</li>
                                            <li>Done or continue clicking to finish</li>
                                            <li>Ctrl + scroll to zoom</li>
                                            <li>Right-drag to pan</li>
                                            <li>Backspace deletes a selected point</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Knop om een verdieping/viewpoint toe te voegen -->
                        <div style="margin-top:14px; padding-top:14px; border-top:1px solid var(--svgml-border, #dce5e3);">
                            <button type="button" id="svgml-add-layer" class="button button-secondary">
                                <span class="dashicons dashicons-plus-alt2"></span>
                                Voeg verdieping of viewpoint toe
                            </button>
                            <span style="color:var(--svgml-muted, #7a8a94); font-size:12px; margin-left:8px;">
                                Voeg een extra afbeelding toe voor een andere verdieping of perspectief.
                            </span>
                        </div>

                        <!-- Verborgen veld: JSON met alle polygon-data (legacy single-layer) -->
                        <input type="hidden"
                               id="svgml_polygons_json"
                               name="svgml_polygons_json"
                               value="<?php echo esc_attr( wp_json_encode( $polygons ) ); ?>">

                        <!-- Verborgen veld: JSON met multi-layer data (nieuw) -->
                        <input type="hidden"
                               id="svgml_layers_json"
                               name="svgml_layers_json"
                               value="<?php echo esc_attr( wp_json_encode( $layers ) ); ?>">

                        <!-- Verborgen velden: lijnstijl (worden bijgewerkt door JS bij wijziging) -->
                        <input type="hidden"
                               id="svgml_poly_stroke_color"
                               name="svgml_poly_stroke_color"
                               value="<?php echo esc_attr( get_post_meta( $map_id, '_svgml_poly_stroke_color', true ) ?: '#2a9d8f' ); ?>">
                        <input type="hidden"
                               id="svgml_poly_stroke_width"
                               name="svgml_poly_stroke_width"
                               value="<?php echo esc_attr( get_post_meta( $map_id, '_svgml_poly_stroke_width', true ) ?: '1' ); ?>">

                        <!-- ── Layer Switcher Stijl Selector ────────────────────────── -->
                        <div style="margin-top:16px; padding-top:16px; border-top:1px solid var(--svgml-border);">
                            <h4 style="margin:0 0 10px; color:var(--svgml-dark)">Layer Switcher Stijl (frontend)</h4>
                            <p style="color:var(--svgml-muted); font-size:12px; margin:0 0 8px">
                                Kies hoe gebruikers kunnen schakelen tussen lagen:
                            </p>
                            <div class="svgml-layer-switcher-options">
                                <label>
                                    <input type="radio" name="svgml_layer_switcher" value="buttons"
                                           <?php checked( $layer_switcher, 'buttons' ); ?>>
                                    Knoppen
                                </label>
                                <label>
                                    <input type="radio" name="svgml_layer_switcher" value="dropdown"
                                           <?php checked( $layer_switcher, 'dropdown' ); ?>>
                                    Dropdown
                                </label>
                                <label>
                                    <input type="radio" name="svgml_layer_switcher" value="custom"
                                           <?php checked( $layer_switcher, 'custom' ); ?>>
                                    Custom (via CSS/JS)
                                </label>
                            </div>
                        </div>

                        <!-- Polygon-IDs status (zoals SVG-IDs status) -->
                        <div id="svgml-polygon-ids-status" class="svgml-ids-status" style="margin-top:12px">
                            <?php if ( ! empty( $polygons ) ) :
                                $poly_ids = array_map( function( $p ) { return $p['id'] ?? ''; }, $polygons );
                                $poly_ids = array_filter( $poly_ids );
                            ?>
                                <div class="svgml-status-box svgml-status-success">
                                    <strong>✓ <?php echo count( $poly_ids ); ?> vlakken getekend:</strong>
                                    <code><?php echo esc_html( implode( ', ', $poly_ids ) ); ?></code>
                                    <br>
                                    <a href="<?php echo admin_url( 'admin.php?page=svgml-mapping&map_id=' . $map_id ); ?>">
                                        → Ga naar Regio Koppeling
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── SECTIE 2: JSON FEED ────────────────────────────────── -->
            <div class="svgml-section">
                <h2>2. JSON Feed</h2>
                <p class="svgml-description">
                    Geef de URL op van de externe JSON feed die de object-data bevat.
                </p>

                <table class="form-table">
                    <tr>
                        <th><label for="svgml_json_url">JSON Feed URL</label></th>
                        <td>
                            <input type="url"
                                   id="svgml_json_url"
                                   name="svgml_json_url"
                                   value="<?php echo esc_url( $json_url ); ?>"
                                   class="regular-text"
                                   placeholder="https://example.com/api/objects.json">
                            <p class="description">
                                De plugin haalt deze URL op bij het laden van de shortcode-pagina
                                en slaat het resultaat 5 minuten op in de cache.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="svgml_json_id_field">ID-veld naam</label></th>
                        <td>
                            <input type="text"
                                   id="svgml_json_id_field"
                                   name="svgml_json_id_field"
                                   value="<?php echo esc_attr( $json_id_field ); ?>"
                                   class="small-text"
                                   placeholder="id">
                            <p class="description">
                                Welk veld in elk JSON-object is het unieke ID?<br>
                                Voorbeeld JSON: <code>{"id": 42, "naam": "Amsterdam"}</code>
                                → vul hier <code>id</code> in.<br>
                                Dit ID koppel je daarna aan een SVG-regio via het menu
                                <em>Regio Koppeling</em>.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="svgml_json_array_key">Object-array sleutel</label></th>
                        <td>
                            <input type="text"
                                   id="svgml_json_array_key"
                                   name="svgml_json_array_key"
                                   value="<?php echo esc_attr( $json_array_key ); ?>"
                                   class="small-text"
                                   placeholder="(automatisch)">
                            <p class="description">
                                <strong>Laat leeg</strong> voor de meeste feeds — de plugin vindt de objecten dan zelf.<br>
                                Vul alleen iets in als je JSON er zo uitziet:<br>
                                <code>{ "id": 7906, "naam": "Gebouw", "spaces": [ { "id": 1 }, { "id": 2 } ] }</code><br>
                                In dat geval zijn de koppelbare objecten de <em>units in de array</em>, niet het gebouw zelf.
                                Vul dan de sleutelnaam in: <code>spaces</code>.<br>
                                Let op: de ID's in die sub-array zijn dan de ID's die je koppelt aan SVG-regio's.
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button( 'Instellingen opslaan' ); ?>
        </form>

        <!-- ─── SHORTCODE INFO ─────────────────────────────────────────── -->
        <div class="svgml-section svgml-shortcode-info">
            <h2>Shortcode</h2>
            <p>Gebruik de volgende shortcode om de SVG-kaart op een pagina of post te plaatsen:</p>
            <code class="svgml-shortcode-display">[svg_map id="<?php echo $map_id; ?>"]</code>
            <p class="description">
                Zorg dat stap 1 en 2 volledig zijn ingesteld, en dat je in
                <em>Regio Koppeling</em> de SVG-ID's aan JSON-objecten hebt gekoppeld.
            </p>
        </div>
    </div>

    <?php
    // ── FIX: Override stale svgmlAdmin.layers with freshly-saved data ───────
    // wp_localize_script runs during admin_enqueue_scripts, which fires BEFORE
    // this render function processes the POST save. So after a save, the
    // localized svgmlAdmin.layers still contains the OLD data. We fix this by
    // injecting a footer script that overwrites the layers with the current
    // (post-save) values. Using wp_add_inline_script ensures it runs right
    // after svgml-polygon-editor loads (when svgmlAdmin already exists).
    $fresh_layers   = get_post_meta( $map_id, '_svgml_layers', true ) ?: [];
    $fresh_switcher = get_post_meta( $map_id, '_svgml_layer_switcher', true ) ?: 'buttons';
    $fresh_json_key = get_post_meta( $map_id, '_svgml_json_array_key', true ) ?: '';

    $override_js = 'if(typeof svgmlAdmin!=="undefined"){'
        . 'svgmlAdmin.layers='       . wp_json_encode( $fresh_layers ) . ';'
        . 'svgmlAdmin.layerSwitcher=' . wp_json_encode( $fresh_switcher ) . ';'
        . 'svgmlAdmin.jsonArrayKey='  . wp_json_encode( $fresh_json_key ) . ';'
        . '}';

    // Attach BEFORE polygon-editor so it runs before the editor reads svgmlAdmin.layers.
    // 'before' means this inline script is output immediately before the script tag.
    wp_add_inline_script( 'svgml-polygon-editor', $override_js, 'before' );
}
