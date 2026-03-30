<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function svgml_render_styles_page( $map_id ) {

    // ── Formulier verwerken ──────────────────────────────────────────────────
    if ( isset( $_POST['svgml_styles_nonce'] ) ) {

        if ( ! wp_verify_nonce( $_POST['svgml_styles_nonce'], 'svgml_save_styles' ) ) {
            echo '<div class="notice notice-error"><p>Beveiligingsfout. Probeer opnieuw.</p></div>';
        } else {
            // Custom CSS opslaan. We gebruiken wp_kses_post() NIET voor CSS –
            // dat is voor HTML. We slaan de ruwe CSS op en strippen HTML-tags
            // bij het uitvoeren (in svgml_output_custom_css()).
            // Hier gebruiken we sanitize_textarea_field() om veilig te opslaan.
            $custom_css = $_POST['svgml_custom_css'] ?? '';
            // Verwijder eventuele </style> tags om style-injection te voorkomen
            $custom_css = str_replace( '</style', '', $custom_css );
            $custom_css = wp_unslash( $custom_css ); // WordPress escapet backslashes, zet terug
            update_post_meta( $map_id, '_svgml_custom_css', $custom_css );

            delete_transient( 'svgml_json_cache_' . $map_id );

            echo '<div class="notice notice-success is-dismissible"><p>CSS opgeslagen!</p></div>';
        }
    }

    $custom_css = get_post_meta( $map_id, '_svgml_custom_css', true ) ?: '';

    $status_colors = get_post_meta( $map_id, '_svgml_status_colors', true );
    if ( ! is_array( $status_colors ) ) $status_colors = [];

    ?>
    <div class="wrap svgml-admin-wrap">
        <h1><span class="dashicons dashicons-art"></span> SVG Map Lite – Stijlen & CSS</h1>

        <div style="display:grid; grid-template-columns:1fr 340px; gap:24px; align-items:flex-start;">

            <!-- ── LINKS: CSS EDITOR ───────────────────────────────────── -->
            <div>
                <form method="post" action="">
                    <?php wp_nonce_field( 'svgml_save_styles', 'svgml_styles_nonce' ); ?>

                    <div class="svgml-section">
                        <h2>Custom CSS</h2>
                        <p class="svgml-description">
                            Voer hieronder CSS in om de kaart en het panel op te maken.
                            Gebruik de klassen-referentie rechts als hulp.
                            Dit CSS wordt geladen op alle pagina's met een SVG Map Lite shortcode.
                        </p>

                        <!-- CodeMirror wordt door WordPress automatisch op dit textarea gekoppeld -->
                        <textarea id="svgml_custom_css"
                                  name="svgml_custom_css"
                                  class="large-text code"
                                  rows="22"><?php echo esc_textarea( $custom_css ); ?></textarea>
                    </div>

                    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <?php submit_button( 'CSS opslaan', 'primary', 'submit', false ); ?>
                        <button type="button" id="svgml-reset-css" class="button button-secondary">
                            ↩ Herstel standaard CSS
                        </button>
                    </div>
                    <p class="description" style="margin-top:8px">
                        De <strong>standaard CSS</strong> bevat alle basisstijlen voor het panel.
                        Herstel om een schone beginstand te krijgen en pas daarna aan naar wens.
                    </p>
                </form>
            </div>

            <!-- ── RECHTS: KLASSEN REFERENTIE ─────────────────────────── -->
            <div>
                <div class="svgml-section svgml-css-reference">
                    <h2>CSS Klassen Referentie</h2>

                    <h3>Container</h3>
                    <dl class="svgml-class-list">
                        <dt><code>.svgml-wrap</code></dt>
                        <dd>Buitenste wrapper (kaart + filters)</dd>
                        <dt><code>.svgml-container</code></dt>
                        <dd>Flexbox container (kaart + panel naast elkaar)</dd>
                        <dt><code>.svgml-map-wrap</code></dt>
                        <dd>Wrapper om de SVG kaart</dd>
                        <dt><code>.svgml-svg</code></dt>
                        <dd>Het SVG-element zelf</dd>
                    </dl>

                    <h3>SVG Regio's</h3>
                    <dl class="svgml-class-list">
                        <dt><code>.svgml-region-active</code></dt>
                        <dd>Geselecteerde regio</dd>
                        <dt><code>.svgml-region-excluded</code></dt>
                        <dd>Uitgesloten regio (geen klik)</dd>
                        <dt><code>.svgml-region-dimmed</code></dt>
                        <dd>Gedempt door filter</dd>
                        <?php foreach ( $status_colors as $sv => $sc ) : ?>
                        <dt><code>.svgml-status-<?php echo esc_html( $sc ); ?></code></dt>
                        <dd>Status: <?php echo esc_html( $sv ); ?></dd>
                        <?php endforeach; ?>
                    </dl>

                    <h3>Filters</h3>
                    <dl class="svgml-class-list">
                        <dt><code>.svgml-filters-bar</code></dt>
                        <dd>Filterbalk container</dd>
                        <dt><code>.svgml-filter-item</code></dt>
                        <dd>Enkel filter (label + control)</dd>
                        <dt><code>.svgml-filter-label</code></dt>
                        <dd>Filter label tekst</dd>
                        <dt><code>.svgml-filter-select</code></dt>
                        <dd>Dropdown filter select</dd>
                        <dt><code>.svgml-range-slider</code></dt>
                        <dd>noUiSlider container</dd>
                        <dt><code>.svgml-filter-reset</code></dt>
                        <dd>Reset knop</dd>
                    </dl>

                    <h3>Panel</h3>
                    <dl class="svgml-class-list">
                        <dt><code>.svgml-panel</code></dt>
                        <dd>Info-panel container</dd>
                        <dt><code>.svgml-panel-title</code></dt>
                        <dd>Paneltitel</dd>
                        <dt><code>.svgml-panel-content</code></dt>
                        <dd>Panel inhoud wrapper</dd>
                        <dt><code>.svgml-panel-close</code></dt>
                        <dd>Sluit-knop</dd>
                    </dl>

                    <h3>Panel Blokken</h3>
                    <dl class="svgml-class-list">
                        <dt><code>.svgml-block</code></dt>
                        <dd>Elk panel blok</dd>
                        <dt><code>.svgml-block-thumbnail</code></dt>
                        <dd>Thumbnail afbeelding wrapper</dd>
                        <dt><code>.svgml-block-thumbnail img</code></dt>
                        <dd>Thumbnail &lt;img&gt;</dd>
                        <dt><code>.svgml-block-heading</code></dt>
                        <dd>Koptekst blok</dd>
                        <dt><code>.svgml-block-badge</code></dt>
                        <dd>Badge blok</dd>
                        <dt><code>.svgml-block-price</code></dt>
                        <dd>Prijs blok</dd>
                        <dt><code>.svgml-block-text</code></dt>
                        <dd>Tekst blok</dd>
                        <dt><code>.svgml-block-link</code></dt>
                        <dd>Link blok</dd>
                        <dt><code>.svgml-block-divider</code></dt>
                        <dd>Scheidingslijn</dd>
                        <dt><code>.svgml-block-label</code></dt>
                        <dd>Label (veldnaam) boven blok</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
    <?php
}
