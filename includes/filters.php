<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function svgml_render_filters_page( $map_id ) {

    // ── Shortcode notice ─────────────────────────────────────────────────────
    echo '<div class="notice notice-info inline" style="margin:12px 0 18px;">'
       . '<p>'
       . '<strong>Filters plaatsen:</strong> Gebruik de shortcode '
       . '<code>[svg_map_filters id="' . absint( $map_id ) . '"]</code> '
       . 'om de filterbalk op je pagina te plaatsen. '
       . 'De filterbalk wordt <em>niet</em> automatisch met de kaart meegeleverd.'
       . '</p>'
       . '</div>';

    // ── Formulier verwerken ──────────────────────────────────────────────────
    if ( isset( $_POST['svgml_filters_nonce'] ) ) {

        if ( ! wp_verify_nonce( $_POST['svgml_filters_nonce'], 'svgml_save_filters' ) ) {
            echo '<div class="notice notice-error"><p>Beveiligingsfout. Probeer opnieuw.</p></div>';
        } else {
            // Filters komen binnen als parallelle arrays
            $raw_fields  = $_POST['svgml_filter_field']  ?? [];
            $raw_types   = $_POST['svgml_filter_type']   ?? [];
            $raw_labels  = $_POST['svgml_filter_label']  ?? [];

            // Nieuwe arraylists voor buttons-filter opties
            $raw_button_sources       = $_POST['svgml_filter_button_source']       ?? [];
            $raw_button_show_counts   = $_POST['svgml_filter_button_show_count']   ?? [];
            $raw_button_custom_values = $_POST['svgml_filter_button_custom_values'] ?? [];
            $raw_input_modes          = $_POST['svgml_filter_input_mode']           ?? [];
            $raw_prefixes             = $_POST['svgml_filter_prefix']               ?? [];
            $raw_suffixes             = $_POST['svgml_filter_suffix']               ?? [];
            $raw_placeholders         = $_POST['svgml_filter_placeholder']          ?? [];

            $filters = [];
            foreach ( $raw_fields as $i => $field ) {
                $clean_field = sanitize_text_field( $field );
                $clean_type  = sanitize_text_field( $raw_types[ $i ] ?? 'dropdown' );
                $clean_label = sanitize_text_field( $raw_labels[ $i ] ?? '' );

                if ( empty( $clean_field ) ) continue;

                // Geldige filtertypen: range (noUiSlider), dropdown, search (autocomplete), of buttons
                if ( ! in_array( $clean_type, [ 'range', 'dropdown', 'search', 'buttons', 'input' ] ) ) {
                    $clean_type = 'dropdown';
                }

                $filter_data = [
                    'field' => $clean_field,
                    'type'  => $clean_type,
                    'label' => $clean_label,
                ];

                // Voeg buttons-specifieke opties toe (alleen relevant als type=buttons)
                if ( 'buttons' === $clean_type ) {
                    $filter_data['button_source']        = sanitize_text_field( $raw_button_sources[ $i ] ?? 'auto' );
                    $filter_data['button_show_count']    = sanitize_text_field( $raw_button_show_counts[ $i ] ?? '0' );
                    $filter_data['button_custom_values'] = sanitize_text_field( $raw_button_custom_values[ $i ] ?? '' );
                }

                // Voeg input-specifieke opties toe (alleen relevant als type=search of input)
                if ( in_array( $clean_type, [ 'search', 'input' ], true ) ) {
                    $raw_mode = sanitize_text_field( $raw_input_modes[ $i ] ?? 'single' );
                    $filter_data['input_mode'] = in_array( $raw_mode, [ 'single', 'minmax' ], true ) ? $raw_mode : 'single';
                }

                // Prefix / suffix (voor range slider labels)
                $filter_data['prefix']      = sanitize_text_field( $raw_prefixes[ $i ]      ?? '' );
                $filter_data['suffix']      = sanitize_text_field( $raw_suffixes[ $i ]      ?? '' );
                $filter_data['placeholder'] = sanitize_text_field( $raw_placeholders[ $i ]  ?? 'Alles' );

                $filters[] = $filter_data;
            }

            update_post_meta( $map_id, '_svgml_filter_fields', $filters );

            // ── Filter match/dim kleuren opslaan ─────────────────────────────
            // sanitize_hex_color() valideert het formaat (#rrggbb of #rgb) en
            // geeft null terug bij een ongeldige waarde.
            $match_color = sanitize_hex_color( $_POST['svgml_filter_match_color'] ?? '' );
            $dim_color   = sanitize_hex_color( $_POST['svgml_filter_dim_color']   ?? '' );
            update_post_meta( $map_id, '_svgml_filter_match_color', $match_color ?? '' );
            update_post_meta( $map_id, '_svgml_filter_dim_color',   $dim_color   ?? '' );

            delete_transient( 'svgml_json_cache_' . $map_id );
            delete_transient( 'svgml_html_'       . $map_id );

            echo '<div class="notice notice-success is-dismissible"><p>Filters opgeslagen!</p></div>';
        }
    }

    // ── Huidige waarden ophalen ──────────────────────────────────────────────
    $filter_fields      = get_post_meta( $map_id, '_svgml_filter_fields', true );
    if ( ! is_array( $filter_fields ) ) $filter_fields = [];

    $filter_match_color = get_post_meta( $map_id, '_svgml_filter_match_color', true ) ?: '';
    $filter_dim_color   = get_post_meta( $map_id, '_svgml_filter_dim_color', true ) ?: '';
    $field_names        = svgml_get_json_field_names( $map_id ); // Auto-detect uit JSON

    $map_mode     = get_post_meta( $map_id, '_svgml_map_mode', true ) ?: 'json';
    $panel_blocks = ( 'manual' === $map_mode )
        ? ( get_post_meta( $map_id, '_svgml_panel_blocks', true ) ?: [] )
        : [];

    $filter_field_options = [];
    if ( 'manual' === $map_mode ) {
        foreach ( $panel_blocks as $i => $pb ) {
            if ( ( $pb['type'] ?? '' ) === 'divider' ) continue;
            $filter_field_options[] = [
                'value' => 'manual_field_' . $i,
                'label' => ! empty( $pb['label'] ) ? $pb['label'] : ( $pb['type'] ?? 'Veld ' . $i ),
            ];
        }
    } else {
        foreach ( $field_names as $fn ) {
            $filter_field_options[] = [ 'value' => $fn, 'label' => $fn ];
        }
    }

    ?>
    <div class="wrap svgml-admin-wrap">
        <h1><span class="dashicons dashicons-filter"></span> SVG Map Lite – Filters</h1>

        <p class="svgml-description">
            Configureer de filterbalk die boven de SVG-kaart verschijnt.
            <?php echo ( 'manual' === $map_mode )
                ? 'Kies voor elk filter een veld en een filtertype.'
                : 'Kies voor elk filter een JSON-veld en een filtertype (keuzelijst of schuifregelaar).'; ?>
        </p>

        <form method="post" action="">
            <?php wp_nonce_field( 'svgml_save_filters', 'svgml_filters_nonce' ); ?>

            <div class="svgml-section">
                <h2>Filter velden</h2>

                <table class="wp-list-table widefat fixed striped" id="svgml-filters-table">
                    <thead>
                        <tr>
                            <th style="width:30px"></th>
                            <th style="width:25%"><?php echo ( 'manual' === $map_mode ) ? 'Paneel Veld' : 'JSON Veld'; ?></th>
                            <th style="width:20%">Type</th>
                            <th style="width:25%">Label</th>
                            <th style="width:20%">Opties</th>
                            <th style="width:60px">Verwijder</th>
                        </tr>
                    </thead>
                    <tbody id="svgml-filters-tbody">
                        <?php foreach ( $filter_fields as $filter ) :
                            $ff         = $filter['field'] ?? '';
                            $ft         = $filter['type']  ?? 'dropdown';
                            $fl         = $filter['label'] ?? '';
                            $fb_src     = $filter['button_source'] ?? 'auto';
                            $fb_cnt     = $filter['button_show_count'] ?? '0';
                            $fb_val     = $filter['button_custom_values'] ?? '';
                            $fi_mode    = $filter['input_mode'] ?? 'single';
                            $f_prefix       = $filter['prefix']       ?? '';
                            $f_suffix       = $filter['suffix']       ?? '';
                            $f_placeholder  = $filter['placeholder']  ?? 'Alles';
                        ?>
                        <tr class="svgml-filter-row">
                            <td class="svgml-drag-handle" style="cursor:grab; text-align:center; color:#999; width:30px;">
                                <span class="dashicons dashicons-sort"></span>
                            </td>
                            <td>
                                <select name="svgml_filter_field[]" class="svgml-filter-field-select">
                                    <option value="">— kies veld —</option>
                                    <?php foreach ( $filter_field_options as $fo ) : ?>
                                        <option value="<?php echo esc_attr( $fo['value'] ); ?>"
                                            <?php selected( $ff, $fo['value'] ); ?>>
                                            <?php echo esc_html( $fo['label'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <?php if ( ! in_array( $ff, array_column( $filter_field_options, 'value' ), true ) && ! empty( $ff ) ) : ?>
                                        <option value="<?php echo esc_attr( $ff ); ?>" selected>
                                            <?php echo esc_html( $ff ); ?>
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </td>
                            <td>
                                <select name="svgml_filter_type[]" class="svgml-filter-type-select">
                                    <option value="dropdown" <?php selected( $ft, 'dropdown' ); ?>>Keuzelijst (dropdown)</option>
                                    <option value="range"    <?php selected( $ft, 'range' ); ?>>Schuifregelaar (range)</option>
                                    <option value="search"   <?php selected( $ft, 'search' ); ?>>Zoekveld (autocomplete)</option>
                                    <option value="input"    <?php selected( $ft, 'input' ); ?>>Invoerveld (input)</option>
                                    <option value="buttons"  <?php selected( $ft, 'buttons' ); ?>>Knoppen (buttons)</option>
                                </select>
                            </td>
                            <td>
                                <input type="text" name="svgml_filter_label[]"
                                       value="<?php echo esc_attr( $fl ); ?>"
                                       placeholder="Bijv. Type, Prijs, Oppervlak"
                                       class="regular-text">
                            </td>
                            <td>
                                <!-- Hidden velden: buttons-opties -->
                                <input type="hidden" name="svgml_filter_button_source[]"
                                       value="<?php echo esc_attr( $fb_src ); ?>"
                                       class="svgml-button-source-val">
                                <input type="hidden" name="svgml_filter_button_show_count[]"
                                       value="<?php echo esc_attr( $fb_cnt ); ?>"
                                       class="svgml-button-show-count-val">
                                <input type="hidden" name="svgml_filter_button_custom_values[]"
                                       value="<?php echo esc_attr( $fb_val ); ?>"
                                       class="svgml-button-custom-values-val">

                                <!-- Hidden veld: input-mode -->
                                <input type="hidden" name="svgml_filter_input_mode[]"
                                       value="<?php echo esc_attr( $fi_mode ); ?>"
                                       class="svgml-input-mode-val">

                                <!-- Configuratie-paneel: input-modus (zichtbaar als type=input) -->
                                <div class="svgml-input-options" style="<?php echo 'input' !== $ft ? 'display:none;' : ''; ?>">
                                    <label style="display:block; margin-bottom:4px;">
                                        <input type="radio" class="svgml-input-mode-radio"
                                               value="single" <?php checked( $fi_mode, 'single' ); ?>>
                                        1 Veld (Tekst / Exact)
                                    </label>
                                    <label style="display:block; margin-bottom:4px;">
                                        <input type="radio" class="svgml-input-mode-radio"
                                               value="minmax" <?php checked( $fi_mode, 'minmax' ); ?>>
                                        2 Velden (Min &amp; Max getallen)
                                    </label>
                                    <p class="description" style="margin:4px 0 0; font-size:11px;">
                                        Kies 2 velden om te filteren op prijs of oppervlakte.
                                    </p>
                                </div>

                                <!-- Configuratie-paneel: alleen zichtbaar als type=buttons -->
                                <div class="svgml-buttons-options" style="<?php echo 'buttons' !== $ft ? 'display:none;' : ''; ?>">
                                    <div style="margin-bottom: 8px;">
                                        <label style="display:block; margin-bottom:4px;">
                                            <input type="radio" name="svgml_button_source_<?php echo esc_attr( $ff ); ?>"
                                                   value="auto" class="svgml-button-source-radio"
                                                   <?php checked( $fb_src, 'auto' ); ?>>
                                            Auto (alle waarden)
                                        </label>
                                        <label style="display:block;">
                                            <input type="radio" name="svgml_button_source_<?php echo esc_attr( $ff ); ?>"
                                                   value="custom" class="svgml-button-source-radio"
                                                   <?php checked( $fb_src, 'custom' ); ?>>
                                            Aangepast
                                        </label>
                                    </div>

                                    <label style="display:flex; align-items:center; gap:4px; margin-bottom:6px;">
                                        <input type="checkbox" class="svgml-button-show-count-checkbox"
                                               <?php checked( $fb_cnt, '1' ); ?>>
                                        Toon aantallen
                                    </label>

                                    <textarea class="svgml-button-custom-values-textarea"
                                              placeholder="Komma-gescheiden waarden"
                                              style="width:100%; min-height:40px; display:<?php echo 'custom' === $fb_src ? 'block' : 'none'; ?>;">
                                        <?php echo esc_textarea( $fb_val ); ?>
                                    </textarea>
                                </div>
                            </td>
                            <td>
                                <input type="text" name="svgml_filter_prefix[]"
                                       value="<?php echo esc_attr( $f_prefix ); ?>"
                                       placeholder="€" class="small-text" style="width:48px;" title="Prefix">
                                <input type="text" name="svgml_filter_suffix[]"
                                       value="<?php echo esc_attr( $f_suffix ); ?>"
                                       placeholder="m²" class="small-text" style="width:48px;" title="Suffix">
                                <input type="text" name="svgml_filter_placeholder[]"
                                       value="<?php echo esc_attr( $f_placeholder ); ?>"
                                       placeholder="Alles" class="small-text" style="width:72px;" title="Dropdown standaardtekst">
                            </td>
                            <td>
                                <button type="button" class="button svgml-remove-filter">✕</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="padding:18px 24px 10px;">
                    <button type="button" class="button button-secondary" id="svgml-add-filter">
                        + Filter toevoegen
                    </button>
                </div>

                <p class="description" style="padding:4px 24px 18px; margin:0;">
                    <?php if ( 'manual' === $map_mode ) : ?>
                        <strong>Keuzelijst/Knoppen:</strong> Haalt automatisch de unieke waarden op uit de handmatig ingevoerde data.<br>
                        <strong>Schuifregelaar:</strong> Detecteert automatisch de min/max waarden uit de ingevoerde data.<br>
                    <?php else : ?>
                        <strong>Keuzelijst:</strong> Haalt automatisch unieke waarden op uit de JSON (bijv. voor Type, Stad).<br>
                        <strong>Schuifregelaar:</strong> Detecteert automatisch min/max waarden (bijv. voor Prijs, Oppervlak).<br>
                    <?php endif; ?>
                    Regio's die niet aan de actieve filters voldoen worden gedempt op de kaart.
                </p>
            </div>

            <!-- ─── FILTER KLEUREN ─────────────────────────────────────── -->
            <div class="svgml-section">
                <h2>Filterkleuroverride</h2>
                <p class="svgml-description">
                    Optioneel: overschrijf de vulkleur van SVG-regio's zodra een filter actief is.
                    Laat leeg om de standaard status- of SVG-kleuren te behouden.
                </p>

                <table class="form-table">
                    <tr>
                        <th>
                            <label for="svgml_filter_match_color">
                                ✓ Voldoet aan filter
                            </label>
                        </th>
                        <td>
                            <!--
                                Deze kleur wordt toegepast op regio's die MATCHEN met
                                de actieve filterinstelling. Laat leeg om de originele
                                SVG-kleur of status-kleur te bewaren.
                            -->
                            <div style="display:flex; align-items:center; gap:10px;">
                                <input type="color"
                                       id="svgml_filter_match_color"
                                       name="svgml_filter_match_color"
                                       value="<?php echo esc_attr( $filter_match_color ?: '#4caf50' ); ?>"
                                       class="svgml-color-input"
                                       <?php echo empty( $filter_match_color ) ? 'data-empty="1"' : ''; ?>>
                                <label style="display:flex;align-items:center;gap:5px;cursor:pointer;">
                                    <input type="checkbox"
                                           id="svgml_filter_match_enabled"
                                           <?php checked( ! empty( $filter_match_color ) ); ?>>
                                    Kleuroverride inschakelen
                                </label>
                                <span id="svgml-match-preview" class="svgml-filter-color-preview"
                                      style="background:<?php echo esc_attr( $filter_match_color ?: '#4caf50' ); ?>">
                                    Matched
                                </span>
                            </div>
                            <p class="description">
                                Als uitgeschakeld: regio's die voldoen aan het filter behouden hun eigen kleur.
                            </p>
                            <!--
                                We gebruiken een leeg hidden veld als de checkbox UIT staat.
                                Zo weet de server dat de kleur uitgeschakeld moet worden.
                            -->
                            <input type="hidden" name="svgml_filter_match_color"
                                   id="svgml_filter_match_color_val"
                                   value="<?php echo esc_attr( $filter_match_color ); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>
                            <label for="svgml_filter_dim_color">
                                ✗ Voldoet NIET aan filter
                            </label>
                        </th>
                        <td>
                            <!--
                                Deze kleur wordt toegepast op regio's die NIET matchen
                                met het actieve filter (de "gedimde" regio's).
                                Laat leeg voor de standaard opacity-vermindering.
                            -->
                            <div style="display:flex; align-items:center; gap:10px;">
                                <input type="color"
                                       id="svgml_filter_dim_color"
                                       name="svgml_filter_dim_color"
                                       value="<?php echo esc_attr( $filter_dim_color ?: '#cccccc' ); ?>"
                                       class="svgml-color-input"
                                       <?php echo empty( $filter_dim_color ) ? 'data-empty="1"' : ''; ?>>
                                <label style="display:flex;align-items:center;gap:5px;cursor:pointer;">
                                    <input type="checkbox"
                                           id="svgml_filter_dim_enabled"
                                           <?php checked( ! empty( $filter_dim_color ) ); ?>>
                                    Kleuroverride inschakelen
                                </label>
                                <span id="svgml-dim-preview" class="svgml-filter-color-preview"
                                      style="background:<?php echo esc_attr( $filter_dim_color ?: '#cccccc' ); ?>">
                                    Uitgefilterd
                                </span>
                            </div>
                            <p class="description">
                                Als uitgeschakeld: regio's die niet voldoen worden gedempt via de standaard opacity (20%).
                            </p>
                            <input type="hidden" name="svgml_filter_dim_color"
                                   id="svgml_filter_dim_color_val"
                                   value="<?php echo esc_attr( $filter_dim_color ); ?>">
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button( 'Filters opslaan' ); ?>
        </form>

        <!-- Template voor nieuwe filterrij -->
        <template id="svgml-filter-row-template">
            <tr class="svgml-filter-row">
                <td class="svgml-drag-handle" style="cursor:grab; text-align:center; color:#999; width:30px;">
                    <span class="dashicons dashicons-sort"></span>
                </td>
                <td>
                    <select name="svgml_filter_field[]" class="svgml-filter-field-select">
                        <option value="">— kies veld —</option>
                        <?php foreach ( $filter_field_options as $fo ) : ?>
                            <option value="<?php echo esc_attr( $fo['value'] ); ?>"><?php echo esc_html( $fo['label'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <select name="svgml_filter_type[]" class="svgml-filter-type-select">
                        <option value="dropdown">Keuzelijst (dropdown)</option>
                        <option value="range">Schuifregelaar (range)</option>
                        <option value="search">Zoekveld (autocomplete)</option>
                        <option value="input">Invoerveld (input)</option>
                        <option value="buttons">Knoppen (buttons)</option>
                    </select>
                </td>
                <td>
                    <input type="text" name="svgml_filter_label[]"
                           placeholder="Bijv. Type, Prijs, Oppervlak"
                           class="regular-text">
                </td>
                <td>
                    <!-- Hidden velden: buttons-opties -->
                    <input type="hidden" name="svgml_filter_button_source[]"
                           value="auto"
                           class="svgml-button-source-val">
                    <input type="hidden" name="svgml_filter_button_show_count[]"
                           value="0"
                           class="svgml-button-show-count-val">
                    <input type="hidden" name="svgml_filter_button_custom_values[]"
                           value=""
                           class="svgml-button-custom-values-val">

                    <!-- Hidden veld: input-mode -->
                    <input type="hidden" name="svgml_filter_input_mode[]"
                           value="single"
                           class="svgml-input-mode-val">

                    <!-- Configuratie-paneel: input-modus (zichtbaar als type=input) -->
                    <div class="svgml-input-options" style="display:none;">
                        <label style="display:block; margin-bottom:4px;">
                            <input type="radio" class="svgml-input-mode-radio" value="single" checked>
                            1 Veld (Tekst / Exact)
                        </label>
                        <label style="display:block; margin-bottom:4px;">
                            <input type="radio" class="svgml-input-mode-radio" value="minmax">
                            2 Velden (Min &amp; Max getallen)
                        </label>
                        <p class="description" style="margin:4px 0 0; font-size:11px;">
                            Kies 2 velden om te filteren op prijs of oppervlakte.
                        </p>
                    </div>

                    <!-- Configuratie-paneel: alleen zichtbaar als type=buttons -->
                    <div class="svgml-buttons-options" style="display:none;">
                        <div style="margin-bottom: 8px;">
                            <label style="display:block; margin-bottom:4px;">
                                <input type="radio" name="svgml_button_source_template"
                                       value="auto" class="svgml-button-source-radio" checked>
                                Auto (alle waarden)
                            </label>
                            <label style="display:block;">
                                <input type="radio" name="svgml_button_source_template"
                                       value="custom" class="svgml-button-source-radio">
                                Aangepast
                            </label>
                        </div>

                        <label style="display:flex; align-items:center; gap:4px; margin-bottom:6px;">
                            <input type="checkbox" class="svgml-button-show-count-checkbox">
                            Toon aantallen
                        </label>

                        <textarea class="svgml-button-custom-values-textarea"
                                  placeholder="Komma-gescheiden waarden"
                                  style="width:100%; min-height:40px; display:none;">
                        </textarea>
                    </div>
                </td>
                <td>
                    <input type="text" name="svgml_filter_prefix[]"
                           placeholder="€" class="small-text" style="width:48px;" title="Prefix">
                    <input type="text" name="svgml_filter_suffix[]"
                           placeholder="m²" class="small-text" style="width:48px;" title="Suffix">
                    <input type="text" name="svgml_filter_placeholder[]"
                           placeholder="Alles" class="small-text" style="width:72px;" title="Dropdown standaardtekst">
                </td>
                <td>
                    <button type="button" class="button svgml-remove-filter">✕</button>
                </td>
            </tr>
        </template>

    </div>
    <?php
}
