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
            delete_transient( 'svgml_json_cache_' . $map_id );

            echo '<div class="notice notice-success is-dismissible"><p>Statuskleuren opgeslagen!</p></div>';
        }
    }

    // ── Huidige waarden ophalen ──────────────────────────────────────────────
    $status_field      = get_post_meta( $map_id, '_svgml_status_field', true ) ?: '';
    $status_colors     = get_post_meta( $map_id, '_svgml_status_colors', true ) ?: [];
    $status_hex_colors = get_post_meta( $map_id, '_svgml_status_hex_colors', true ) ?: [];
    $status_opacity    = get_post_meta( $map_id, '_svgml_status_opacity', true ) ?: [];
    $field_names       = svgml_get_json_field_names( $map_id );

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
                    Selecteer het JSON-veld dat de beschikbaarheidsstatus van elk object bevat.
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
                                Voorbeeld: <code>rental_status</code>, <code>status</code>, <code>availability</code>.
                                Dit veld moet in elk JSON-object voorkomen.
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
                            <th>Statuswaarde (exact uit JSON)</th>
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

            <!-- ─── UITLEG ─────────────────────────────────────────────── -->
            <div class="svgml-section">
                <h2>Hoe werkt dit?</h2>
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
