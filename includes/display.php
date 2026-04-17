<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Display & Status Colors page for SVG Map Lite
 * Extracted from svg-map-lite.php with multi-map support
 */

function svgml_render_display_page( $map_id ) {

    // ── Formulier verwerken ──────────────────────────────────────────────────
    if ( isset( $_POST['svgml_display_nonce'] ) ) {

        if ( ! wp_verify_nonce( $_POST['svgml_display_nonce'], 'svgml_save_display' ) ) {
            echo '<div class="notice notice-error"><p>Beveiligingsfout. Probeer opnieuw.</p></div>';
        } else {

            // Status veld opslaan (welk JSON-veld bevat de status)
            $status_field = sanitize_text_field( $_POST['svgml_status_field'] ?? '' );
            update_post_meta( $map_id, '_svgml_status_field', $status_field );

            // Status kleuren opslaan.
            // svgml_status_value[]   = JSON-waarden (bijv. 'Beschikbaar')
            // svgml_status_hex[]     = hex-kleurcodes (bijv. '#2e9e3c')
            // svgml_status_opacity[] = opacity percentage 10–100 (bijv. 80)
            // De CSS-klasse wordt automatisch afgeleid van de waarde.
            $raw_vals          = $_POST['svgml_status_value']   ?? [];
            $raw_hexes         = $_POST['svgml_status_hex']     ?? [];
            $raw_opacities     = $_POST['svgml_status_opacity'] ?? [];
            $status_colors     = [];
            $status_hex_colors = [];
            $status_opacity    = [];

            foreach ( $raw_vals as $j => $val ) {
                $clean_val     = sanitize_text_field( $val );
                $clean_hex     = sanitize_hex_color( $raw_hexes[ $j ] ?? '' );
                // Opacity: getal 10–100, default 100
                $clean_opacity = max( 10, min( 100, intval( $raw_opacities[ $j ] ?? 100 ) ) );
                if ( empty( $clean_val ) ) continue;

                // CSS-klasse automatisch afleiden: lowercase, spaties → koppeltekens
                $auto_class = sanitize_html_class(
                    strtolower( str_replace( [ ' ', '/', '\\', '_' ], '-', $clean_val ) )
                );
                $status_colors[ $clean_val ] = $auto_class;
                if ( $clean_hex ) {
                    $status_hex_colors[ $clean_val ] = $clean_hex;
                }
                $status_opacity[ $clean_val ] = $clean_opacity;
            }

            update_post_meta( $map_id, '_svgml_status_colors',     $status_colors );
            update_post_meta( $map_id, '_svgml_status_hex_colors', $status_hex_colors );
            update_post_meta( $map_id, '_svgml_status_opacity',    $status_opacity );

            // ── Layer Switcher Style ─────────────────────────────────────────
            if ( isset( $_POST['svgml_layer_switcher'] ) ) {
                $switcher = sanitize_text_field( $_POST['svgml_layer_switcher'] );
                if ( ! in_array( $switcher, [ 'buttons', 'dropdown', 'custom' ] ) ) $switcher = 'buttons';
                update_post_meta( $map_id, '_svgml_layer_switcher', $switcher );
            }

            // ── Panel & Filter Styling ───────────────────────────────────────────────
            $panel_bg_color       = sanitize_hex_color( $_POST['svgml_panel_bg_color']    ?? '' ) ?: '';
            $panel_text_color     = sanitize_hex_color( $_POST['svgml_panel_text_color']  ?? '' ) ?: '';
            $panel_border_radius  = max( 0, min( 50, intval( $_POST['svgml_panel_border_radius'] ?? 8 ) ) );
            $filter_bg_color      = sanitize_hex_color( $_POST['svgml_filter_bg_color']   ?? '' ) ?: '';
            $filter_text_color    = sanitize_hex_color( $_POST['svgml_filter_text_color'] ?? '' ) ?: '';
            update_post_meta( $map_id, '_svgml_panel_bg_color',      $panel_bg_color );
            update_post_meta( $map_id, '_svgml_panel_text_color',     $panel_text_color );
            update_post_meta( $map_id, '_svgml_panel_border_radius',  (string) $panel_border_radius );
            update_post_meta( $map_id, '_svgml_filter_bg_color',      $filter_bg_color );
            update_post_meta( $map_id, '_svgml_filter_text_color',    $filter_text_color );
            $panel_border_color  = sanitize_hex_color( $_POST['svgml_panel_border_color']  ?? '' ) ?: '';
            $panel_border_width  = max( 0, min( 20, intval( $_POST['svgml_panel_border_width']  ?? 0 ) ) );
            $slider_accent_color = sanitize_hex_color( $_POST['svgml_slider_accent_color'] ?? '' ) ?: '';
            update_post_meta( $map_id, '_svgml_panel_border_color',  $panel_border_color );
            update_post_meta( $map_id, '_svgml_panel_border_width',  (string) $panel_border_width );
            update_post_meta( $map_id, '_svgml_slider_accent_color', $slider_accent_color );

            // ── Input Field Styling ──────────────────────────────────────────────
            $input_bg_color     = sanitize_hex_color( $_POST['svgml_input_bg_color']     ?? '' ) ?: '';
            $input_text_color   = sanitize_hex_color( $_POST['svgml_input_text_color']   ?? '' ) ?: '';
            $input_border_color = sanitize_hex_color( $_POST['svgml_input_border_color'] ?? '' ) ?: '';
            $input_focus_color  = sanitize_hex_color( $_POST['svgml_input_focus_color']  ?? '' ) ?: '';
            update_post_meta( $map_id, '_svgml_input_bg_color',     $input_bg_color );
            update_post_meta( $map_id, '_svgml_input_text_color',   $input_text_color );
            update_post_meta( $map_id, '_svgml_input_border_color', $input_border_color );
            update_post_meta( $map_id, '_svgml_input_focus_color',  $input_focus_color );

            // ── Polygon Stroke ───────────────────────────────────────────────────
            $poly_stroke_color = sanitize_hex_color( $_POST['svgml_poly_stroke_color'] ?? '' ) ?: '#2a9d8f';
            $poly_stroke_width = max( 0, min( 10, intval( $_POST['svgml_poly_stroke_width'] ?? 1 ) ) );
            update_post_meta( $map_id, '_svgml_poly_stroke_color', $poly_stroke_color );
            update_post_meta( $map_id, '_svgml_poly_stroke_width', (string) $poly_stroke_width );
            $poly_stroke_width_hover = max( 0, min( 20, floatval( $_POST['svgml_poly_stroke_width_hover'] ?? 3 ) ) );
            update_post_meta( $map_id, '_svgml_poly_stroke_width_hover', (string) $poly_stroke_width_hover );

            // ── Highlight (hover/active fill) ────────────────────────────────────
            $highlight_color   = sanitize_hex_color( $_POST['svgml_highlight_color'] ?? '' ) ?: '#2a9d8f';
            $highlight_opacity = round( max( 0.0, min( 1.0, floatval( $_POST['svgml_highlight_opacity'] ?? 0.7 ) ) ), 2 );
            update_post_meta( $map_id, '_svgml_highlight_color',   $highlight_color );
            update_post_meta( $map_id, '_svgml_highlight_opacity', (string) $highlight_opacity );

            delete_transient( 'svgml_json_cache_' . $map_id );
            delete_transient( 'svgml_html_'       . $map_id );

            echo '<div class="notice notice-success is-dismissible"><p>Statuskleuren opgeslagen!</p></div>';
        }
    }

    // ── Huidige waarden ophalen ──────────────────────────────────────────────
    $status_field      = get_post_meta( $map_id, '_svgml_status_field', true ) ?: '';
    $status_colors     = get_post_meta( $map_id, '_svgml_status_colors', true ) ?: [];
    $status_hex_colors = get_post_meta( $map_id, '_svgml_status_hex_colors', true ) ?: [];
    $status_opacity    = get_post_meta( $map_id, '_svgml_status_opacity', true ) ?: [];
    $field_names       = svgml_get_json_field_names( $map_id );
    $map_mode          = get_post_meta( $map_id, '_svgml_map_mode', true ) ?: 'json';
    $layer_switcher    = get_post_meta( $map_id, '_svgml_layer_switcher', true ) ?: 'buttons';
    $panel_bg_color      = get_post_meta( $map_id, '_svgml_panel_bg_color',      true ) ?: '#ffffff';
    $panel_text_color    = get_post_meta( $map_id, '_svgml_panel_text_color',     true ) ?: '#333333';
    $panel_border_radius = get_post_meta( $map_id, '_svgml_panel_border_radius',  true );
    $panel_border_radius = ( '' === $panel_border_radius ) ? 8 : intval( $panel_border_radius );
    $filter_bg_color     = get_post_meta( $map_id, '_svgml_filter_bg_color',      true ) ?: '#f5f5f5';
    $filter_text_color   = get_post_meta( $map_id, '_svgml_filter_text_color',    true ) ?: '#333333';
    $panel_border_color  = get_post_meta( $map_id, '_svgml_panel_border_color',  true ) ?: '#cccccc';
    $panel_border_width  = get_post_meta( $map_id, '_svgml_panel_border_width',  true );
    $panel_border_width  = ( '' === $panel_border_width ) ? 0 : intval( $panel_border_width );
    $slider_accent_color = get_post_meta( $map_id, '_svgml_slider_accent_color', true ) ?: '#2a9d8f';
    $input_bg_color      = get_post_meta( $map_id, '_svgml_input_bg_color',     true ) ?: '#ffffff';
    $input_text_color    = get_post_meta( $map_id, '_svgml_input_text_color',   true ) ?: '#333333';
    $input_border_color  = get_post_meta( $map_id, '_svgml_input_border_color', true ) ?: '#cccccc';
    $input_focus_color   = get_post_meta( $map_id, '_svgml_input_focus_color',  true ) ?: '#2a9d8f';
    $poly_stroke_color         = get_post_meta( $map_id, '_svgml_poly_stroke_color',        true ) ?: '#2a9d8f';
    $poly_stroke_width_v       = get_post_meta( $map_id, '_svgml_poly_stroke_width',        true );
    $poly_stroke_width_v       = ( '' === $poly_stroke_width_v ) ? 1 : intval( $poly_stroke_width_v );
    $poly_stroke_width_hover_v = get_post_meta( $map_id, '_svgml_poly_stroke_width_hover',  true );
    $poly_stroke_width_hover_v = ( '' === $poly_stroke_width_hover_v ) ? 3 : floatval( $poly_stroke_width_hover_v );
    $highlight_color_v   = get_post_meta( $map_id, '_svgml_highlight_color',   true ) ?: '#2a9d8f';
    $highlight_opacity_v = get_post_meta( $map_id, '_svgml_highlight_opacity', true );
    $highlight_opacity_v = ( '' === $highlight_opacity_v ) ? 0.7 : floatval( $highlight_opacity_v );

    // Standaard vastgoed-statussen als er nog niets is ingesteld
    if ( empty( $status_colors ) ) {
        $status_colors     = [ 'Beschikbaar' => 'available', 'Onder Optie' => 'onder-optie', 'Verkocht' => 'verkocht' ];
        $status_hex_colors = [ 'Beschikbaar' => '#2e9e3c',   'Onder Optie' => '#f0a500',     'Verkocht' => '#cc0000' ];
    }

    ?>
    <div class="wrap svgml-admin-wrap">
        <h1><span class="dashicons dashicons-art"></span> SVG Map Lite – Weergave &amp; Statuskleuren</h1>

        <p class="svgml-description">
            Bepaal hier welk JSON-veld de beschikbaarheidsstatus bevat en welke kleur bij elke statuswaarde hoort.
            De kleuren worden bij het laden van de pagina direct op de SVG-regio's toegepast,
            zodat bezoekers in één oogopslag zien wat beschikbaar is.
        </p>

        <form method="post" action="">
            <?php wp_nonce_field( 'svgml_save_display', 'svgml_display_nonce' ); ?>

            <!-- ─── STATUSVELD SELECTIE ───────────────────────────────── -->
            <div class="svgml-section">
                <h2>Welk veld bevat de status?</h2>
                <p class="svgml-description">
                    <?php echo ( 'manual' === $map_mode )
                        ? 'Selecteer het veld dat de beschikbaarheidsstatus bevat.'
                        : 'Selecteer het JSON-veld dat de beschikbaarheidsstatus van elk object bevat.'; ?>
                </p>
                <table class="form-table">
                    <tr>
                        <th><label for="svgml_status_field">Status-veld</label></th>
                        <td>
                            <select id="svgml_status_field" name="svgml_status_field" style="min-width:220px">
                                <option value="">— niet ingesteld —</option>
                                <?php foreach ( $field_names as $fn ) : ?>
                                    <option value="<?php echo esc_attr( $fn ); ?>" <?php selected( $status_field, $fn ); ?>>
                                        <?php echo esc_html( $fn ); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if ( $status_field && ! in_array( $status_field, $field_names ) ) : ?>
                                    <option value="<?php echo esc_attr( $status_field ); ?>" selected>
                                        <?php echo esc_html( $status_field ); ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                            <p class="description">
                                <?php if ( 'manual' === $map_mode ) : ?>
                                    Voorbeeld: <code>Status</code>, <code>Beschikbaarheid</code>.
                                <?php else : ?>
                                    Voorbeeld: <code>rental_status</code>, <code>status</code>, <code>availability</code>.
                                    Dit veld moet in elk JSON-object voorkomen.
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- ─── STATUSKLEUREN ──────────────────────────────────────── -->
            <div class="svgml-section">
                <h2>Statuskleuren</h2>
                <p class="svgml-description">
                    Koppel elke statuswaarde aan een kleur. De waarde moet
                    <strong>exact</strong> overeenkomen met de tekst in je JSON-feed (inclusief hoofdletters en spaties).
                    De kleur wordt gebruikt voor de <strong>SVG-regio fill</strong> én de
                    <strong>badge</strong> in het info-panel.
                </p>

                <table class="wp-list-table widefat fixed striped" id="svgml-status-table">
                    <thead>
                        <tr>
                            <th><?php echo ( 'manual' === $map_mode ) ? 'Statuswaarde (exact ingevuld)' : 'Statuswaarde (exact uit JSON)'; ?></th>
                            <th style="width:90px">Kleur</th>
                            <th style="width:130px">Opacity %</th>
                            <th style="width:200px">Preview</th>
                            <th style="width:50px">✕</th>
                        </tr>
                    </thead>
                    <tbody id="svgml-status-tbody">
                        <?php foreach ( $status_colors as $sv => $sc ) :
                            $sh  = $status_hex_colors[ $sv ] ?? '#888888';
                            $sop = $status_opacity[ $sv ] ?? 100;
                        ?>
                        <tr class="svgml-status-row">
                            <td>
                                <input type="text" name="svgml_status_value[]"
                                       value="<?php echo esc_attr( $sv ); ?>"
                                       placeholder="Bijv. Beschikbaar"
                                       class="regular-text svgml-status-val-input">
                            </td>
                            <td>
                                <input type="color" name="svgml_status_hex[]"
                                       value="<?php echo esc_attr( $sh ); ?>"
                                       class="svgml-color-input">
                            </td>
                            <td>
                                <!-- Opacity slider: 10–100%, default 100 -->
                                <div style="display:flex; align-items:center; gap:6px;">
                                    <input type="range" name="svgml_status_opacity[]"
                                           value="<?php echo esc_attr( $sop ); ?>"
                                           min="10" max="100" step="5"
                                           class="svgml-opacity-slider"
                                           style="flex:1; min-width:60px">
                                    <span class="svgml-opacity-val" style="min-width:32px; font-size:12px; color:#555"><?php echo esc_html( $sop ); ?>%</span>
                                </div>
                            </td>
                            <td>
                                <!-- Badge preview (panel) -->
                                <span class="svgml-status-preview"
                                      style="background-color:<?php echo esc_attr( $sh ); ?>1a; color:<?php echo esc_attr( $sh ); ?>; border:1px solid <?php echo esc_attr( $sh ); ?>">
                                    <?php echo esc_html( $sv ?: 'Status' ); ?>
                                </span>
                                <!-- Kleurcirkel preview (SVG regio fill + opacity) -->
                                <span class="svgml-region-color-dot"
                                      style="display:inline-block; width:18px; height:18px; border-radius:50%; background:<?php echo esc_attr( $sh ); ?>; opacity:<?php echo esc_attr( $sop / 100 ); ?>; vertical-align:middle; margin-left:8px; border:1px solid rgba(0,0,0,0.12);"
                                      title="SVG regio fill"></span>
                            </td>
                            <td>
                                <button type="button" class="button svgml-remove-status" title="Verwijder">✕</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="padding:18px 24px 10px;">
                    <button type="button" class="button button-secondary" id="svgml-add-status">
                        + Statuswaarde toevoegen
                    </button>
                </div>
                <p class="description" style="padding:4px 24px 18px; margin:0;">
                    Voeg extra statussen toe als je meer dan 3 waarden hebt (bijv. apart <em>Verkocht</em>
                    en <em>Verhuurd</em>). De CSS-klasse wordt automatisch gegenereerd.
                </p>
            </div>

            <!-- ─── PANEL & FILTER STYLING ──────────────────────────────── -->
            <div class="svgml-section">
                <h2>Paneel & Filter Styling</h2>
                <div style="display:flex; gap:32px; align-items:center; padding:0 24px 0 0;">

                    <!-- Left: settings table -->
                    <div style="flex:1; min-width:280px;">
                        <table class="form-table" style="margin:0;">
                            <tr>
                                <th>Paneel achtergrond</th>
                                <td>
                                    <input type="color" name="svgml_panel_bg_color"
                                           value="<?php echo esc_attr( $panel_bg_color ); ?>" class="svgml-color-input">
                                </td>
                            </tr>
                            <tr>
                                <th>Paneel tekstkleur</th>
                                <td>
                                    <input type="color" name="svgml_panel_text_color"
                                           value="<?php echo esc_attr( $panel_text_color ); ?>" class="svgml-color-input">
                                </td>
                            </tr>
                            <tr>
                                <th>Paneel hoekradius (px)</th>
                                <td>
                                    <input type="number" name="svgml_panel_border_radius"
                                           value="<?php echo esc_attr( $panel_border_radius ); ?>"
                                           min="0" max="50" step="1" class="small-text">
                                    <p class="description">Standaard: 8px</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Filterbalk achtergrond</th>
                                <td>
                                    <input type="color" name="svgml_filter_bg_color"
                                           value="<?php echo esc_attr( $filter_bg_color ); ?>" class="svgml-color-input">
                                </td>
                            </tr>
                            <tr>
                                <th>Filterbalk tekstkleur</th>
                                <td>
                                    <input type="color" name="svgml_filter_text_color"
                                           value="<?php echo esc_attr( $filter_text_color ); ?>" class="svgml-color-input">
                                </td>
                            </tr>
                            <tr>
                                <th>Paneel randkleur</th>
                                <td>
                                    <input type="color" name="svgml_panel_border_color"
                                           value="<?php echo esc_attr( $panel_border_color ); ?>" class="svgml-color-input">
                                </td>
                            </tr>
                            <tr>
                                <th>Paneel randbreedte (px)</th>
                                <td>
                                    <input type="number" name="svgml_panel_border_width"
                                           value="<?php echo esc_attr( $panel_border_width ); ?>"
                                           min="0" max="20" step="1" class="small-text">
                                    <p class="description">0 = geen rand</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Slider accentkleur</th>
                                <td>
                                    <input type="color" name="svgml_slider_accent_color"
                                           value="<?php echo esc_attr( $slider_accent_color ); ?>" class="svgml-color-input">
                                </td>
                            </tr>
                            <tr>
                                <th colspan="2" style="padding-top:18px; font-size:13px; font-weight:600; color:#1d2327; border-top:1px solid #ddd;">Invoerveld styling</th>
                            </tr>
                            <tr>
                                <th>Input achtergrondkleur</th>
                                <td>
                                    <input type="color" name="svgml_input_bg_color"
                                           value="<?php echo esc_attr( $input_bg_color ); ?>" class="svgml-color-input">
                                </td>
                            </tr>
                            <tr>
                                <th>Input tekstkleur</th>
                                <td>
                                    <input type="color" name="svgml_input_text_color"
                                           value="<?php echo esc_attr( $input_text_color ); ?>" class="svgml-color-input">
                                </td>
                            </tr>
                            <tr>
                                <th>Input randkleur</th>
                                <td>
                                    <input type="color" name="svgml_input_border_color"
                                           value="<?php echo esc_attr( $input_border_color ); ?>" class="svgml-color-input">
                                </td>
                            </tr>
                            <tr>
                                <th>Input focus kleur</th>
                                <td>
                                    <input type="color" name="svgml_input_focus_color"
                                           value="<?php echo esc_attr( $input_focus_color ); ?>" class="svgml-color-input">
                                    <p class="description">Randkleur bij focus/klik op het invoerveld</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Right: live preview -->
                    <div style="flex:1; min-width:240px;">
                        <p style="font-size:12px; text-transform:uppercase; letter-spacing:.05em; color:#888; margin:0 0 10px;">Live preview</p>

                        <!-- Filter bar mockup -->
                        <div id="svgml-preview-filter-bar" style="padding:10px 14px; border-radius:6px; margin-bottom:14px; display:flex; gap:8px; flex-wrap:wrap; background-color:<?php echo esc_attr( $filter_bg_color ); ?>; color:<?php echo esc_attr( $filter_text_color ); ?>;">
                            <span style="background:rgba(0,0,0,.08); padding:4px 12px; border-radius:20px; font-size:13px; color:<?php echo esc_attr( $filter_text_color ); ?>;">Type ▾</span>
                            <span style="background:rgba(0,0,0,.08); padding:4px 12px; border-radius:20px; font-size:13px; color:<?php echo esc_attr( $filter_text_color ); ?>;">Status ▾</span>
                            <div style="width:100%; margin-top:6px; padding:4px 2px;">
                                <div style="position:relative; height:4px; border-radius:2px; background:rgba(0,0,0,.15);">
                                    <div id="svgml-preview-slider-connect" style="position:absolute; left:20%; right:30%; top:0; bottom:0; border-radius:2px; background-color:<?php echo esc_attr( $slider_accent_color ); ?>;"></div>
                                    <div id="svgml-preview-slider-handle" style="position:absolute; left:20%; top:50%; transform:translate(-50%,-50%); width:14px; height:14px; border-radius:50%; background:#fff; border:2px solid <?php echo esc_attr( $slider_accent_color ); ?>; box-shadow:0 1px 4px rgba(0,0,0,.2);"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Input field mockup -->
                        <div style="padding:10px 14px 12px; background-color:<?php echo esc_attr( $filter_bg_color ); ?>; margin-bottom:14px; border-radius:6px;" id="svgml-preview-input-bar">
                            <label class="svgml-filter-label" style="display:block; margin-bottom:5px; font-size:12px; color:<?php echo esc_attr( $filter_text_color ); ?>;">Zoeken</label>
                            <input type="text" class="svgml-filter-input-single" id="svgml-preview-input"
                                   placeholder="Typ een waarde..."
                                   style="width:100%; padding:6px 10px; border-radius:4px; font-size:13px; box-sizing:border-box; background-color:<?php echo esc_attr( $input_bg_color ); ?>; color:<?php echo esc_attr( $input_text_color ); ?>; border:1px solid <?php echo esc_attr( $input_border_color ); ?>; outline:none;" readonly>
                        </div>

                        <!-- Panel mockup -->
                        <div id="svgml-preview-panel" style="padding:20px; border-radius:<?php echo esc_attr( $panel_border_radius ); ?>px; background-color:<?php echo esc_attr( $panel_bg_color ); ?>; color:<?php echo esc_attr( $panel_text_color ); ?>; box-shadow:0 2px 12px rgba(0,0,0,.12); position:relative;">
                            <div style="font-weight:600; font-size:15px; margin-bottom:10px; border-bottom:1px solid rgba(0,0,0,.08); padding-bottom:8px;">Regio naam</div>
                            <div style="font-size:13px; line-height:1.8; opacity:.75;">Veld 1: Waarde A<br>Veld 2: Waarde B<br>Status: Beschikbaar</div>
                        </div>
                    </div>
                </div>

                <script>
                (function($) {
                    function svgml_updatePreview() {
                        var panelBg          = $('[name="svgml_panel_bg_color"]').val();
                        var panelText        = $('[name="svgml_panel_text_color"]').val();
                        var panelRadius      = parseInt( $('[name="svgml_panel_border_radius"]').val(), 10 ) || 0;
                        var panelBorderColor = $('[name="svgml_panel_border_color"]').val();
                        var panelBorderWidth = parseInt( $('[name="svgml_panel_border_width"]').val(), 10 ) || 0;
                        var filterBg         = $('[name="svgml_filter_bg_color"]').val();
                        var filterText       = $('[name="svgml_filter_text_color"]').val();
                        var sliderAccent     = $('[name="svgml_slider_accent_color"]').val();
                        var inputBg          = $('[name="svgml_input_bg_color"]').val();
                        var inputText        = $('[name="svgml_input_text_color"]').val();
                        var inputBorder      = $('[name="svgml_input_border_color"]').val();

                        $('#svgml-preview-panel').css({
                            'background-color': panelBg,
                            'color':            panelText,
                            'border-radius':    panelRadius + 'px',
                            'border':           panelBorderWidth > 0 ? panelBorderWidth + 'px solid ' + panelBorderColor : 'none'
                        });
                        $('#svgml-preview-filter-bar').css({
                            'background-color': filterBg,
                            'color':            filterText
                        });
                        $('#svgml-preview-filter-bar span').css('color', filterText);
                        $('#svgml-preview-slider-connect').css('background-color', sliderAccent);
                        $('#svgml-preview-slider-handle').css('border-color', sliderAccent);
                        $('#svgml-preview-input-bar').css('background-color', filterBg);
                        $('#svgml-preview-input').css({
                            'background-color': inputBg,
                            'color':            inputText,
                            'border':           '1px solid ' + inputBorder
                        });
                    }
                    $(document).ready(function() {
                        $('[name^="svgml_panel_"], [name^="svgml_filter_"], [name^="svgml_slider_"], [name^="svgml_input_"], [name^="svgml_poly_"]').on('input change', svgml_updatePreview);
                    });
                })(jQuery);
                </script>
            </div>

            <!-- ─── POLYGOON WEERGAVE ──────────────────────────────────── -->
            <div class="svgml-section">
                <h2>Polygoon Weergave</h2>
                <p class="svgml-description">
                    Stel de lijnkleur en lijndikte in van de SVG-regio's (polygoongrenzen).
                </p>
                <table class="form-table">
                    <tr>
                        <th><label for="svgml_poly_stroke_color">Lijnkleur</label></th>
                        <td>
                            <input type="color" id="svgml_poly_stroke_color" name="svgml_poly_stroke_color"
                                   value="<?php echo esc_attr( $poly_stroke_color ); ?>" class="svgml-color-input">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="svgml_poly_stroke_width">Lijndikte</label></th>
                        <td>
                            <input type="number" id="svgml_poly_stroke_width" name="svgml_poly_stroke_width"
                                   value="<?php echo esc_attr( $poly_stroke_width_v ); ?>"
                                   min="0" max="10" step="1" class="small-text">
                            <p class="description">Standaard: 1px (schermgrootte)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="svgml_poly_stroke_width_hover">Hover/Active Lijndikte</label></th>
                        <td>
                            <input type="number" id="svgml_poly_stroke_width_hover" name="svgml_poly_stroke_width_hover"
                                   value="<?php echo esc_attr( $poly_stroke_width_hover_v ); ?>"
                                   min="0" max="20" step="0.5" class="small-text">
                            <p class="description">Lijndikte bij hover en actieve selectie. Standaard: 3px</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="svgml_highlight_color">Highlight Kleur</label></th>
                        <td>
                            <input type="color" id="svgml_highlight_color" name="svgml_highlight_color"
                                   value="<?php echo esc_attr( $highlight_color_v ); ?>" class="svgml-color-input">
                            <p class="description">Opvulkleur bij hover en actieve selectie.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="svgml_highlight_opacity">Highlight Helderheid</label></th>
                        <td>
                            <input type="range" id="svgml_highlight_opacity" name="svgml_highlight_opacity"
                                   value="<?php echo esc_attr( $highlight_opacity_v ); ?>"
                                   min="0" max="1" step="0.05" style="width:200px; vertical-align:middle;">
                            <span id="svgml_highlight_opacity_val"><?php echo esc_html( $highlight_opacity_v ); ?></span>
                            <p class="description">Doorzichtigheid van de opvulkleur (0 = onzichtbaar, 1 = volledig dekkend). Standaard: 0.7</p>
                            <script>
                            (function() {
                                var r = document.getElementById('svgml_highlight_opacity');
                                var s = document.getElementById('svgml_highlight_opacity_val');
                                if (r && s) r.addEventListener('input', function() { s.textContent = this.value; });
                            })();
                            </script>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- ─── LAYER SWITCHER ──────────────────────────────────────── -->
            <div class="svgml-section">
                <h2>Layer Switcher Stijl</h2>
                <p class="svgml-description">
                    Kies hoe bezoekers kunnen schakelen tussen lagen op de kaart.
                </p>
                <table class="form-table">
                    <tr>
                        <th>Weergave stijl</th>
                        <td>
                            <div class="svgml-layer-switcher-options">
                                <label style="display:inline-flex; align-items:center; gap:6px; margin-right:16px; cursor:pointer;">
                                    <input type="radio" name="svgml_layer_switcher" value="buttons"
                                           <?php checked( $layer_switcher, 'buttons' ); ?>>
                                    Knoppen
                                </label>
                                <label style="display:inline-flex; align-items:center; gap:6px; margin-right:16px; cursor:pointer;">
                                    <input type="radio" name="svgml_layer_switcher" value="dropdown"
                                           <?php checked( $layer_switcher, 'dropdown' ); ?>>
                                    Dropdown
                                </label>
                                <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                    <input type="radio" name="svgml_layer_switcher" value="custom"
                                           <?php checked( $layer_switcher, 'custom' ); ?>>
                                    Custom (via CSS/JS)
                                </label>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- ─── UITLEG ─────────────────────────────────────────────── -->
            <div class="svgml-section">
                <h2>Hoe werkt dit?</h2>
                <?php if ( 'manual' === $map_mode ) : ?>
                <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:18px; padding:20px 24px;">
                    <div style="background:#f9f9f9; border-radius:10px; padding:16px 18px;">
                        <strong style="font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#888">Stap 1 — Data per Vlak</strong>
                        <p style="margin:8px 0 0; font-size:13px; color:#333; line-height:1.5">
                            Vul per regio de statuswaarde in via het tabblad <strong>Data per Vlak</strong>.
                            Gebruik dezelfde waarden die je hieronder als statussen instelt, bijv. <code>Beschikbaar</code>.
                        </p>
                    </div>
                    <div style="background:#f9f9f9; border-radius:10px; padding:16px 18px;">
                        <strong style="font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#888">Stap 2 — Kleuren instellen</strong>
                        <p style="margin:8px 0 0; font-size:13px; color:#333; line-height:1.5">
                            Kies hierboven welke kleur bij <code>Beschikbaar</code> hoort (groen),
                            en welke bij <code>Onder Optie</code> (oranje) en <code>Verkocht</code> (rood).
                        </p>
                    </div>
                    <div style="background:#f9f9f9; border-radius:10px; padding:16px 18px;">
                        <strong style="font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#888">Stap 3 — Op de kaart</strong>
                        <p style="margin:8px 0 0; font-size:13px; color:#333; line-height:1.5">
                            De kaart kleurt elke regio automatisch op basis van de waarde die je handmatig hebt ingevoerd.
                            Filters dimmen regio's die niet voldoen.
                        </p>
                    </div>
                </div>
                <?php else : ?>
                <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:18px; padding:20px 24px;">
                    <div style="background:#f9f9f9; border-radius:10px; padding:16px 18px;">
                        <strong style="font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#888">Stap 1 — JSON-feed</strong>
                        <p style="margin:8px 0 0; font-size:13px; color:#333; line-height:1.5">
                            Elk object in je JSON-feed heeft een veld zoals <code>rental_status</code>
                            met een waarde, bijv. <code>"Beschikbaar"</code>.
                        </p>
                    </div>
                    <div style="background:#f9f9f9; border-radius:10px; padding:16px 18px;">
                        <strong style="font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#888">Stap 2 — Kleuren instellen</strong>
                        <p style="margin:8px 0 0; font-size:13px; color:#333; line-height:1.5">
                            Je kiest hierboven welke kleur bij <code>"Beschikbaar"</code> hoort (groen),
                            en welke bij <code>"Onder Optie"</code> (oranje) en <code>"Verkocht"</code> (rood).
                        </p>
                    </div>
                    <div style="background:#f9f9f9; border-radius:10px; padding:16px 18px;">
                        <strong style="font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#888">Stap 3 — Op de kaart</strong>
                        <p style="margin:8px 0 0; font-size:13px; color:#333; line-height:1.5">
                            De kaart laadt en elke SVG-regio krijgt direct de juiste kleur op basis van
                            de status in de JSON. Filters dimmen regio's die niet voldoen.
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php submit_button( 'Statuskleuren opslaan' ); ?>
        </form>

        <!-- ── Verborgen rij-template voor JavaScript ─────────────────── -->
        <template id="svgml-status-row-template">
            <tr class="svgml-status-row">
                <td>
                    <input type="text" name="svgml_status_value[]"
                           placeholder="Bijv. Beschikbaar"
                           class="regular-text svgml-status-val-input">
                </td>
                <td>
                    <input type="color" name="svgml_status_hex[]"
                           value="#888888" class="svgml-color-input">
                </td>
                <td>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <input type="range" name="svgml_status_opacity[]"
                               value="100" min="10" max="100" step="5"
                               class="svgml-opacity-slider" style="flex:1;min-width:60px">
                        <span class="svgml-opacity-val" style="min-width:32px;font-size:12px;color:#555">100%</span>
                    </div>
                </td>
                <td>
                    <span class="svgml-status-preview" style="background:#8888881a;color:#888888;border:1px solid #888888">Status</span>
                    <span class="svgml-region-color-dot" style="display:inline-block;width:18px;height:18px;border-radius:50%;background:#888888;vertical-align:middle;margin-left:8px;border:1px solid rgba(0,0,0,0.12)"></span>
                </td>
                <td>
                    <button type="button" class="button svgml-remove-status">✕</button>
                </td>
            </tr>
        </template>
    </div>
    <?php
}
