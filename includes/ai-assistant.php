<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AI Assistant page for SVG Map Lite
 * Generates a copy-able AI prompt and imports AI-generated config JSON.
 */

function svgml_render_ai_assistant_page( $map_id ) {

    // ── NUKE HANDLER ─────────────────────────────────────────────────────────
    if ( isset( $_POST['svgml_nuke_data'] ) && isset( $_POST['svgml_ai_import_nonce'] )
         && wp_verify_nonce( $_POST['svgml_ai_import_nonce'], 'svgml_save_ai_import' ) ) {
        delete_post_meta( $map_id, '_svgml_id_mapping' );
        delete_post_meta( $map_id, '_svgml_manual_data' );
        delete_transient( 'svgml_json_cache_' . $map_id );
        delete_transient( 'svgml_html_'       . $map_id );
        echo '<div class="notice notice-warning is-dismissible"><p>Alle koppeling- en AI-data voor deze kaart zijn gewist.</p></div>';
    }

    // ── IMPORT HANDLER ───────────────────────────────────────────────────────
    if ( isset( $_POST['svgml_ai_import_nonce'] ) && ! isset( $_POST['svgml_nuke_data'] ) ) {
        if ( ! wp_verify_nonce( $_POST['svgml_ai_import_nonce'], 'svgml_save_ai_import' ) ) {
            echo '<div class="notice notice-error"><p>Beveiligingsfout. Probeer opnieuw.</p></div>';
        } else {
            $raw_json = wp_unslash( $_POST['svgml_ai_json'] ?? '' );
            // Strip Markdown code fences that AI sometimes wraps output in
            $raw_json = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw_json ) );
            $raw_json = preg_replace( '/\s*```$/',           '', trim( $raw_json ) );
            $raw_json = trim( $raw_json );
            $data     = json_decode( $raw_json, true );

            if ( ! is_array( $data ) ) {
                echo '<div class="notice notice-error"><p>Ongeldige JSON. Controleer de output van de AI en probeer opnieuw.</p></div>';
            } else {

                // ── styles ───────────────────────────────────────────────
                if ( isset( $data['styles'] ) && is_array( $data['styles'] ) ) {
                    $s = $data['styles'];
                    $hex_keys = [
                        'panel_bg_color', 'panel_text_color', 'panel_border_color',
                        'filter_bg_color', 'filter_text_color', 'slider_accent_color',
                    ];
                    foreach ( $hex_keys as $k ) {
                        if ( isset( $s[ $k ] ) ) {
                            update_post_meta( $map_id, '_svgml_' . $k, sanitize_hex_color( $s[ $k ] ) ?: '' );
                        }
                    }
                    if ( isset( $s['panel_border_radius'] ) ) {
                        update_post_meta( $map_id, '_svgml_panel_border_radius', (string) max( 0, min( 50, intval( $s['panel_border_radius'] ) ) ) );
                    }
                    if ( isset( $s['panel_border_width'] ) ) {
                        update_post_meta( $map_id, '_svgml_panel_border_width', (string) max( 0, min( 20, intval( $s['panel_border_width'] ) ) ) );
                    }
                    if ( isset( $s['custom_css'] ) ) {
                        $css = str_replace( '</style', '', wp_unslash( $s['custom_css'] ) );
                        update_post_meta( $map_id, '_svgml_custom_css', $css );
                    }
                }

                // ── panel_config ─────────────────────────────────────────
                if ( isset( $data['panel_config'] ) && is_array( $data['panel_config'] ) ) {
                    $valid_types  = [ 'thumbnail', 'heading', 'badge', 'price', 'text', 'html', 'link', 'divider' ];
                    $valid_widths = [ 25, 33, 50, 75, 100 ];
                    $blocks = [];
                    foreach ( $data['panel_config'] as $b ) {
                        if ( ! is_array( $b ) ) continue;
                        $type  = sanitize_text_field( $b['type']  ?? 'text' );
                        $field = sanitize_text_field( $b['field'] ?? '' );
                        $label = sanitize_text_field( $b['label'] ?? '' );
                        $width = intval( $b['width'] ?? 100 );
                        $html  = ! empty( $b['html'] );
                        if ( ! in_array( $type,  $valid_types  ) ) $type  = 'text';
                        if ( ! in_array( $width, $valid_widths ) ) $width = 100;
                        $blocks[] = [
                            'field' => $field,
                            'type'  => $type,
                            'label' => $label,
                            'width' => $width,
                            'html'  => $html,
                        ];
                    }
                    update_post_meta( $map_id, '_svgml_panel_blocks', $blocks );
                }

                // ── filters ──────────────────────────────────────────────
                if ( isset( $data['filters'] ) && is_array( $data['filters'] ) ) {
                    $valid_filter_types = [ 'range', 'dropdown', 'search', 'buttons' ];
                    $filters = [];
                    foreach ( $data['filters'] as $f ) {
                        if ( ! is_array( $f ) ) continue;
                        $field = sanitize_text_field( $f['field'] ?? '' );
                        $type  = sanitize_text_field( $f['type']  ?? 'dropdown' );
                        $label = sanitize_text_field( $f['label'] ?? '' );
                        if ( empty( $field ) ) continue;
                        if ( ! in_array( $type, $valid_filter_types ) ) $type = 'dropdown';
                        $fd = [ 'field' => $field, 'type' => $type, 'label' => $label ];
                        if ( 'buttons' === $type ) {
                            $fd['button_source']        = sanitize_text_field( $f['button_source']        ?? 'auto' );
                            $fd['button_show_count']    = sanitize_text_field( $f['button_show_count']    ?? '0' );
                            $fd['button_custom_values'] = sanitize_text_field( $f['button_custom_values'] ?? '' );
                        }
                        $filters[] = $fd;
                    }
                    update_post_meta( $map_id, '_svgml_filter_fields', $filters );
                }

                // ── manual_data ───────────────────────────────────────────
                if ( isset( $data['manual_data'] ) && is_array( $data['manual_data'] ) ) {
                    $existing = get_post_meta( $map_id, '_svgml_manual_data', true ) ?: [];
                    foreach ( $data['manual_data'] as $poly_id => $fields ) {
                        $clean_id = sanitize_text_field( $poly_id );
                        if ( ! $clean_id || ! is_array( $fields ) ) continue;
                        $clean_fields = [];
                        foreach ( $fields as $fk => $fv ) {
                            $clean_fields[ sanitize_key( $fk ) ] = sanitize_text_field( $fv );
                        }
                        $existing[ $clean_id ] = $clean_fields;
                    }
                    update_post_meta( $map_id, '_svgml_manual_data', $existing );
                }

                // ── cache bust ────────────────────────────────────────────
                delete_transient( 'svgml_json_cache_' . $map_id );
                delete_transient( 'svgml_html_'       . $map_id );

                // ── success notice ────────────────────────────────────────
                $ai_message = isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : '';
                $notice     = 'AI configuratie geïmporteerd!';
                if ( $ai_message ) {
                    $notice .= ' <strong>Tip van de AI:</strong> ' . esc_html( $ai_message );
                }
                echo '<div class="notice notice-success is-dismissible"><p>' . $notice . '</p></div>';
            }
        }
    }

    // ── SAMPLE DATA FOR PROMPT (real data preferred, hardcoded fallback) ─────
    $map_mode  = get_post_meta( $map_id, '_svgml_map_mode', true ) ?: 'json';
    $real_data = '';

    if ( 'manual' === $map_mode ) {
        $manual_data = get_post_meta( $map_id, '_svgml_manual_data', true ) ?: [];
        if ( ! empty( $manual_data ) ) {
            $first = reset( $manual_data );
            if ( ! is_array( $first ) ) { $first = $manual_data; }
            $real_data = wp_json_encode( $first, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        }
    } else {
        if ( function_exists( 'svgml_get_json_data' ) ) {
            $json_data = svgml_get_json_data( $map_id );
            if ( is_array( $json_data ) && ! empty( $json_data ) ) {
                $first = reset( $json_data );
                if ( ! is_array( $first ) ) { $first = $json_data; }
                $real_data = wp_json_encode( $first, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
            }
        }
    }

    $sample_json = $real_data ?: <<<'JSON'
{
  "id": 2940,
  "name": "Unit 1",
  "description": "Voorbeeld",
  "sales_status": "Beschikbaar",
  "full_sales_price": "€ 320.000"
}
JSON;

    // ── BUILD PROMPT STRING ───────────────────────────────────────────────────
    $schema = '{
  "styles": {
    "panel_bg_color": "#ffffff",
    "panel_text_color": "#333333",
    "panel_border_radius": 8,
    "panel_border_color": "#cccccc",
    "panel_border_width": 1,
    "filter_bg_color": "#f5f5f5",
    "filter_text_color": "#333333",
    "slider_accent_color": "#2a9d8f",
    "custom_css": ""
  },
  "panel_config": [
    { "field": "name", "type": "heading", "label": "Naam", "width": 100, "html": false },
    { "field": "description", "type": "text", "label": "Omschrijving", "width": 100, "html": false }
  ],
  "filters": [
    { "field": "status", "type": "dropdown", "label": "Status" }
  ],
  "manual_data": {},
  "message": "Optionele tip voor de gebruiker, bijv. over een logo of kleurenschema."
}';

    $prompt = "Je bent een expert UI/UX AI. Bekijk de bijgevoegde screenshot van een interactieve kaart.\n\n"
            . "Genereer een JSON-configuratie voor de stijl (kleuren, CSS, transparantie), paneelindeling (op basis van de datastructuur) en filters. Laat het 'manual_data' object leeg ({}). Geen uitleg, geen markdown — enkel de JSON.\n\n"
            . "Hier is de EXACTE beschikbare datastructuur per vlak:\n" . $sample_json . "\n"
            . "BELANGRIJK: Koppel je configuratie aan de juiste keys uit dit object. "
            . "Gebruik bijv. full_sales_price voor de prijs, name voor de titel, en sales_status voor de status-badge.\n\n"
            . "OPACITY: Geef de polygonen in de CSS een transparantie (bijv. fill-opacity: 0.5 of rgba) "
            . "zodat de onderliggende kaart en gebouwen zichtbaar blijven.\n\n"
            . "KLEUREN: Let heel goed op de kleuren. Stijl de actieve filterknoppen (zoals 'Alles') en de "
            . "getallen in de range-slider in de exacte kleuren van de screenshot. "
            . "Zorg dat statussen de juiste kleur krijgen (bijv. Beschikbaar = oranje/groen).\n\n"
            . "CTA KNOP: Vergeet niet de opvallende actieknop onderaan het paneel "
            . "(bijv. 'Selecteer deze unit') toe te voegen via een 'html' of 'link' block in de panel_config.\n\n"
            . "Gebruik de custom_css sleutel om lettertypes/diktes te matchen met de screenshot "
            . "EN genereer hierin de CSS om de polygonen en badges de juiste kleur te geven op basis van de sales_status "
            . "(kijk goed naar de kleuren op de screenshot voor Beschikbaar, Verkocht, etc.).\n\n"
            . "Geldige block types voor panel_config: thumbnail, heading, badge, price, text, html, link, divider\n"
            . "Geldige filter types: range, dropdown, search, buttons\n"
            . "Geldige widths voor panel blokken: 25, 33, 50, 75, 100\n\n"
            . "Vereist schema:\n" . $schema;

    ?>
    <div class="wrap svgml-admin-wrap">
        <h1><span class="dashicons dashicons-superhero-alt"></span> AI Assistent</h1>

        <style>
        .svgml-ai-container { display:flex; gap:20px; align-items:stretch; }
        .svgml-ai-container .svgml-card { flex:1; margin-top:0; }
        @media (max-width:900px) { .svgml-ai-container { flex-direction:column; } }
        </style>

        <div class="svgml-ai-container">
        <!-- Step 1: Export / copy prompt -->
        <div class="svgml-card">
            <h2 style="margin-top:0;">Stap 1 — Kopieer de AI-prompt</h2>
            <p>Stuur deze prompt samen met een <strong>screenshot van je kaart</strong> naar een AI (bijv. ChatGPT of Claude). De AI genereert een JSON-configuratie die je in stap 2 kunt importeren.</p>
            <textarea id="svgml-ai-prompt" readonly rows="16"
                      style="width:100%;font-family:monospace;font-size:12px;background:#f6f7f7;border:1px solid #c3c4c7;padding:10px;resize:vertical;"
            ><?php echo esc_textarea( $prompt ); ?></textarea>
            <br>
            <button type="button" id="svgml-copy-btn" class="button button-secondary" style="margin-top:8px;"
                    onclick="svgmlCopyPrompt();">
                📋 Kopieer prompt
            </button>
            <script>
            function svgmlCopyPrompt() {
                var textarea = document.getElementById('svgml-ai-prompt');
                var btn      = document.getElementById('svgml-copy-btn');
                var original = btn.innerText;

                textarea.select();
                textarea.setSelectionRange(0, 99999);

                function showSuccess() {
                    btn.innerText = 'Gekopieerd! ✔';
                    setTimeout(function() { btn.innerText = original; }, 2000);
                }

                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(textarea.value).then(showSuccess);
                } else {
                    try {
                        if (document.execCommand('copy')) showSuccess();
                    } catch (err) {}
                }
            }
            </script>
        </div>

        <!-- Step 2: Import AI output -->
        <div class="svgml-card">
            <h2 style="margin-top:0;">Stap 2 — Plak de AI-output en importeer</h2>
            <p>Plak de volledige JSON-output van de AI hieronder. De configuratie wordt direct opgeslagen in je kaart.</p>
            <form method="post" action="">
                <?php wp_nonce_field( 'svgml_save_ai_import', 'svgml_ai_import_nonce' ); ?>
                <textarea name="svgml_ai_json" rows="16"
                          style="width:100%;font-family:monospace;font-size:12px;border:1px solid #c3c4c7;padding:10px;resize:vertical;"
                          placeholder='{ "styles": {}, "panel_config": [], "filters": [], "manual_data": {}, "message": "" }'></textarea>
                <br><br>
                <input type="submit" class="button button-primary button-large" value="🚀 Importeer Configuratie">
                &nbsp;
                <button type="submit" name="svgml_nuke_data" class="button"
                        style="color:#b32d2e;border-color:#b32d2e;"
                        onclick="return confirm('Alle koppeling- en AI-data wissen? Dit kan niet ongedaan worden gemaakt.');">
                    🗑 Nuke Data
                </button>
            </form>
        </div>
        </div><!-- .svgml-ai-container -->
    </div>
    <?php
}
