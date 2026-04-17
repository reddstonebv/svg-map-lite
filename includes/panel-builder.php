<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function svgml_render_panel_builder_page( $map_id ) {

    // ── Formulier verwerken ──────────────────────────────────────────────────
    if ( isset( $_POST['svgml_panelbuilder_nonce'] ) ) {

        if ( ! wp_verify_nonce( $_POST['svgml_panelbuilder_nonce'], 'svgml_save_panelbuilder' ) ) {
            echo '<div class="notice notice-error"><p>Beveiligingsfout. Probeer opnieuw.</p></div>';
        } else {
            // ── Shared validation constants ──────────────────────────────────
            $valid_types  = [ 'thumbnail', 'heading', 'badge', 'price', 'text', 'html', 'link', 'divider', 'static_html', 'static_button' ];
            $valid_widths = [ 25, 33, 50, 75, 100 ];

            $map_mode  = get_post_meta( $map_id, '_svgml_map_mode', true ) ?: 'json';
            $is_manual = ( 'manual' === $map_mode );

            // ── Primary path: JSON from hidden field (panel-builder.js) ──────
            // assets/js/panel-builder.js serialises the current DOM order into
            // #svgml_panel_blocks before the POST fires, so this field always
            // reflects the correct drag-and-drop order and checkbox state.
            $raw_json    = stripslashes( $_POST['svgml_panel_blocks'] ?? '' );
            $json_blocks = ( $raw_json !== '' ) ? json_decode( $raw_json, true ) : null;

            $blocks = [];

            $static_types = [ 'static_html', 'static_button', 'divider' ];

            if ( is_array( $json_blocks ) ) {
                // JSON path — authoritative; JS has already done the ordering.
                foreach ( $json_blocks as $block ) {
                    $clean_type   = sanitize_text_field( $block['type']         ?? 'text' );
                    $clean_field  = sanitize_text_field( $block['field']        ?? '' );
                    $clean_label  = sanitize_text_field( $block['label']        ?? '' );
                    $clean_width  = intval( $block['width']                     ?? 100 );
                    $clean_html   = ! empty( $block['html'] );
                    $clean_static = wp_kses_post( $block['static_value']        ?? '' );
                    $clean_prefix = sanitize_text_field( $block['prefix']       ?? '' );
                    $clean_suffix = sanitize_text_field( $block['suffix']       ?? '' );

                    if ( ! in_array( $clean_type, $valid_types ) )   $clean_type  = 'text';
                    if ( ! in_array( $clean_width, $valid_widths ) ) $clean_width = 100;
                    if ( ! $is_manual && ! in_array( $clean_type, $static_types ) && empty( $clean_field ) ) continue;

                    $blocks[] = [
                        'field'        => $clean_field,
                        'type'         => $clean_type,
                        'label'        => $clean_label,
                        'width'        => $clean_width,
                        'html'         => $clean_html,
                        'static_value' => $clean_static,
                        'prefix'       => $clean_prefix,
                        'suffix'       => $clean_suffix,
                    ];
                }
            } else {
                // ── Fallback path: parallel arrays (no-JS / legacy) ──────────
                $raw_fields        = $_POST['svgml_block_field']        ?? [];
                $raw_types         = $_POST['svgml_block_type']         ?? [];
                $raw_labels        = $_POST['svgml_block_label']        ?? [];
                $raw_widths        = $_POST['svgml_block_width']        ?? [];
                $raw_html_flags    = $_POST['svgml_block_html']         ?? [];
                $raw_static_values = $_POST['svgml_block_static_value'] ?? [];
                $raw_prefixes      = $_POST['svgml_block_prefix']       ?? [];
                $raw_suffixes      = $_POST['svgml_block_suffix']       ?? [];

                foreach ( $raw_fields as $i => $field ) {
                    $clean_field  = sanitize_text_field( $field );
                    $clean_type   = sanitize_text_field( $raw_types[ $i ]         ?? 'text' );
                    $clean_label  = sanitize_text_field( $raw_labels[ $i ]        ?? '' );
                    $clean_width  = intval( $raw_widths[ $i ]                     ?? 100 );
                    $clean_html   = ( ( $raw_html_flags[ $i ]                     ?? '0' ) === '1' );
                    $clean_static = wp_kses_post( $raw_static_values[ $i ]        ?? '' );
                    $clean_prefix = sanitize_text_field( $raw_prefixes[ $i ]      ?? '' );
                    $clean_suffix = sanitize_text_field( $raw_suffixes[ $i ]      ?? '' );

                    if ( ! in_array( $clean_type, $valid_types ) )   $clean_type  = 'text';
                    if ( ! in_array( $clean_width, $valid_widths ) ) $clean_width = 100;
                    if ( ! $is_manual && ! in_array( $clean_type, $static_types ) && empty( $clean_field ) ) continue;

                    $blocks[] = [
                        'field'        => $clean_field,
                        'type'         => $clean_type,
                        'label'        => $clean_label,
                        'width'        => $clean_width,
                        'html'         => $clean_html,
                        'static_value' => $clean_static,
                        'prefix'       => $clean_prefix,
                        'suffix'       => $clean_suffix,
                    ];
                }
            }

            update_post_meta( $map_id, '_svgml_panel_blocks', $blocks );

            // ── Save panel settings ──────────────────────────────────────────
            $panel_title = sanitize_text_field( $_POST['svgml_panel_title'] ?? '' );
            update_post_meta( $map_id, '_svgml_panel_title', $panel_title );

            // ── Save legacy fields (advanced) ────────────────────────────────
            $raw_fields_legacy = sanitize_textarea_field( $_POST['svgml_display_fields'] ?? '' );
            $fields_array      = array_values( array_filter(
                array_map( 'trim', preg_split( '/[\n\r,]+/', $raw_fields_legacy ) )
            ) );
            update_post_meta( $map_id, '_svgml_display_fields', $fields_array );

            // Note: Status field and status colors are saved via the Display tab.
            // They're no longer in Panel Builder to avoid confusion.

            // ── Save overview settings ───────────────────────────────────────
            $overview_enabled = isset( $_POST['svgml_overview_enabled'] ) ? true : false;
            update_post_meta( $map_id, '_svgml_overview_enabled', $overview_enabled );

            // Overview blocks (same structure as panel blocks, but without width)
            $raw_ov_fields     = $_POST['svgml_overview_field'] ?? [];
            $raw_ov_types      = $_POST['svgml_overview_type']  ?? [];
            $raw_ov_labels     = $_POST['svgml_overview_label'] ?? [];
            // HTML flag: hidden input + checkbox combo, same as Panel Blocks.
            $raw_ov_html_flags = $_POST['svgml_overview_html']  ?? [];

            $overview_blocks = [];
            foreach ( $raw_ov_fields as $i => $ov_field ) {
                $clean_field = sanitize_text_field( $ov_field );
                $clean_type  = sanitize_text_field( $raw_ov_types[ $i ] ?? 'text' );
                $clean_label = sanitize_text_field( $raw_ov_labels[ $i ] ?? '' );
                $clean_html  = ( ( $raw_ov_html_flags[ $i ] ?? '0' ) === '1' );

                $valid_types = [ 'heading', 'badge', 'price', 'text', 'link' ];
                if ( ! in_array( $clean_type, $valid_types ) ) {
                    $clean_type = 'text';
                }
                if ( ! $is_manual && empty( $clean_field ) ) continue;

                $overview_blocks[] = [
                    'field' => $clean_field,
                    'type'  => $clean_type,
                    'label' => $clean_label,
                    'html'  => $clean_html,
                ];
            }
            update_post_meta( $map_id, '_svgml_overview_blocks', $overview_blocks );

            delete_transient( 'svgml_json_cache_' . $map_id );
            delete_transient( 'svgml_html_'       . $map_id );

            echo '<div class="notice notice-success is-dismissible"><p>Panel Builder opgeslagen!</p></div>';
        }
    }

    // ── Huidige waarden ophalen ──────────────────────────────────────────────
    $panel_blocks      = get_post_meta( $map_id, '_svgml_panel_blocks', true );
    if ( ! is_array( $panel_blocks ) ) $panel_blocks = [];

    $panel_title       = get_post_meta( $map_id, '_svgml_panel_title', true ) ?: '';

    $display_fields    = get_post_meta( $map_id, '_svgml_display_fields', true );
    if ( ! is_array( $display_fields ) ) $display_fields = [];

    $status_field      = get_post_meta( $map_id, '_svgml_status_field', true ) ?: '';

    $status_colors     = get_post_meta( $map_id, '_svgml_status_colors', true );
    if ( ! is_array( $status_colors ) ) $status_colors = [];

    $status_hex_colors = get_post_meta( $map_id, '_svgml_status_hex_colors', true );
    if ( ! is_array( $status_hex_colors ) ) $status_hex_colors = [];

    $overview_enabled  = (bool) get_post_meta( $map_id, '_svgml_overview_enabled', true );

    $overview_blocks   = get_post_meta( $map_id, '_svgml_overview_blocks', true );
    if ( ! is_array( $overview_blocks ) ) $overview_blocks = [];

    $map_mode    = get_post_meta( $map_id, '_svgml_map_mode', true ) ?: 'json';
    $is_manual   = ( 'manual' === $map_mode );
    // In manual mode there is no JSON feed. Use the fixed manual data keys.
    if ( 'manual' === $map_mode ) {
        $field_names = [ 'title', 'status', 'size' ];
    } else {
        $field_names = svgml_get_json_field_names( $map_id ); // Auto-detect uit JSON
    }

    // ── Overview field options ───────────────────────────────────────────────
    // In manual mode, data keys are manual_field_0, manual_field_1, … (index-based).
    // The label shown to the user is the panel block's own label (or a type fallback).
    // In JSON mode, mirror the same $field_names used by the main panel block selects.
    if ( 'manual' === $map_mode ) {
        $ov_type_fallbacks = [
            'thumbnail' => 'Afbeelding', 'heading' => 'Koptekst', 'badge'  => 'Badge',
            'price'     => 'Prijs',      'text'    => 'Tekst',    'html'   => 'HTML',
            'link'      => 'Link',
        ];
        $overview_field_options = [];
        foreach ( $panel_blocks as $i => $pb ) {
            if ( ( $pb['type'] ?? '' ) === 'divider' ) continue;
            $pb_label = ! empty( $pb['label'] )
                ? $pb['label']
                : ( $ov_type_fallbacks[ $pb['type'] ?? 'text' ] ?? 'Veld' ) . ' ' . ( $i + 1 );
            $overview_field_options[] = [
                'value' => 'manual_field_' . $i,
                'label' => $pb_label,
            ];
        }
        if ( empty( $overview_field_options ) ) {
            $overview_field_options = [
                [ 'value' => 'manual_field_0', 'label' => 'Veld 1' ],
                [ 'value' => 'manual_field_1', 'label' => 'Veld 2' ],
                [ 'value' => 'manual_field_2', 'label' => 'Veld 3' ],
            ];
        }
    } else {
        $overview_field_options = array_map(
            fn( $fn ) => [ 'value' => $fn, 'label' => $fn ],
            $field_names
        );
    }
    $block_types       = [ 'thumbnail', 'heading', 'badge', 'price', 'text', 'html', 'link', 'divider', 'static_html', 'static_button' ];
    $block_type_labels = [
        'thumbnail'     => '🖼️ Thumbnail',
        'heading'       => '🔤 Koptekst',
        'badge'         => '🏷️ Badge',
        'price'         => '💶 Prijs',
        'text'          => '📝 Tekst',
        'html'          => '🌐 HTML (raw)',
        'link'          => '🔗 Link',
        'divider'       => '─── Scheidingslijn',
        'static_html'   => '✏️ Statische HTML',
        'static_button' => '🔘 Statische Knop',
    ];
    // Breedte-opties voor elk blok
    $block_width_options = [
        100 => '100% – Volledig',
        75  => '75%',
        50  => '50% – Half',
        33  => '33% – Derde',
        25  => '25% – Kwart',
    ];

    ?>
    <div class="wrap svgml-admin-wrap">
        <h1><span class="dashicons dashicons-welcome-widgets-menus"></span> SVG Map Lite – Panel Builder</h1>

        <p class="svgml-description">
            Configureer hier het info-panel dat verschijnt als een bezoeker op een regio klikt.
            Stel de paneelinstellingen in, voeg blokken toe (thumbnail, koptekst, badge, prijs, tekst of link),
            en kies de statuskleuren. De volgorde van de blokken bepaalt de weergave.
        </p>

        <form method="post" action="">
            <?php wp_nonce_field( 'svgml_save_panelbuilder', 'svgml_panelbuilder_nonce' ); ?>

            <!-- ─── PANEELINSTELLINGEN ─────────────────────────────────── -->
            <div class="svgml-section">
                <h2>Paneelinstellingen</h2>
                <p class="svgml-description">
                    Basisinstellingen voor het info-panel: titel en positie ten opzichte van de kaart.
                </p>
                <table class="form-table">
                    <tr>
                        <th><label for="svgml_panel_title">Panel Titel</label></th>
                        <td>
                            <input type="text"
                                   id="svgml_panel_title"
                                   name="svgml_panel_title"
                                   value="<?php echo esc_attr( $panel_title ); ?>"
                                   class="regular-text"
                                   placeholder="Bijv. Object Info">
                            <p class="description">
                                Optionele vaste koptekst boven het info-panel. Laat leeg om geen titel te tonen.
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- ─── PANEL BLOKKEN ──────────────────────────────────────── -->
            <div class="svgml-section">
                <h2>Panel Blokken</h2>

                <!-- Flex-wrapper: tabel links, live voorbeeld rechts -->
                <div class="svgml-blocks-with-preview">
                <div class="svgml-blocks-table-wrap">
                <table class="wp-list-table widefat fixed striped" id="svgml-blocks-table">
                    <thead>
                        <tr>
                            <th style="width:3%">☰</th>
                            <?php if ( ! $is_manual ) : ?>
                            <th style="width:15%">JSON Veld</th>
                            <?php endif; ?>
                            <th style="width:15%">Type</th>
                            <th style="width:12%">Breedte</th>
                            <th style="width:22%">Label (optioneel)</th>
                            <th style="width:18%">Inhoud / URL</th>
                            <th style="width:10%;text-align:center" title="Waarde bevat HTML-opmaak">HTML</th>
                            <th style="width:5%">✕</th>
                        </tr>
                    </thead>
                    <tbody id="svgml-blocks-tbody">
                        <?php if ( ! empty( $panel_blocks ) ) :
                            foreach ( $panel_blocks as $block ) :
                                $b_field  = $block['field']        ?? '';
                                $b_type   = $block['type']         ?? 'text';
                                $b_label  = $block['label']        ?? '';
                                $b_width  = intval( $block['width'] ?? 100 );
                                $b_html   = ! empty( $block['html'] );
                                $b_static = $block['static_value'] ?? '';
                                $b_prefix = $block['prefix']       ?? '';
                                $b_suffix = $block['suffix']       ?? '';
                                $is_static = in_array( $b_type, [ 'static_html', 'static_button' ] );
                        ?>
                        <tr class="svgml-block-row">
                            <td class="svgml-drag-handle" title="Versleep om volgorde te wijzigen">⠿</td>
                            <?php if ( ! $is_manual ) : ?>
                            <td>
                                <?php if ( 'divider' === $b_type ) : ?>
                                    <input type="hidden" name="svgml_block_field[]" value="">
                                    <em style="color:#888">—</em>
                                <?php else : ?>
                                <select name="svgml_block_field[]" class="svgml-block-field-select">
                                    <option value="">— kies veld —</option>
                                    <?php foreach ( $field_names as $fn ) : ?>
                                        <option value="<?php echo esc_attr( $fn ); ?>"
                                            <?php selected( $b_field, $fn ); ?>>
                                            <?php echo esc_html( $fn ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <?php if ( ! in_array( $b_field, $field_names ) && ! empty( $b_field ) ) : ?>
                                        <option value="<?php echo esc_attr( $b_field ); ?>" selected>
                                            <?php echo esc_html( $b_field ); ?>
                                        </option>
                                    <?php endif; ?>
                                </select>
                                <?php endif; ?>
                            </td>
                            <?php else : ?>
                            <input type="hidden" name="svgml_block_field[]" value="">
                            <?php endif; ?>
                            <td>
                                <select name="svgml_block_type[]" class="svgml-block-type-select">
                                    <?php foreach ( $block_type_labels as $bt_val => $bt_label ) : ?>
                                        <option value="<?php echo esc_attr( $bt_val ); ?>"
                                            <?php selected( $b_type, $bt_val ); ?>>
                                            <?php echo esc_html( $bt_label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <!--
                                    Breedte als percentage van het panel.
                                    Blokken worden naast elkaar getoond met flexbox.
                                    Twee 50%-blokken staan bijv. naast elkaar.
                                -->
                                <select name="svgml_block_width[]" class="svgml-block-width-select">
                                    <?php foreach ( $block_width_options as $bw_val => $bw_label ) : ?>
                                        <option value="<?php echo esc_attr( $bw_val ); ?>"
                                            <?php selected( $b_width, $bw_val ); ?>>
                                            <?php echo esc_html( $bw_label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="text" name="svgml_block_label[]"
                                       value="<?php echo esc_attr( $b_label ); ?>"
                                       placeholder="Label"
                                       class="regular-text" style="width:100px">
                                <input type="text" name="svgml_block_prefix[]"
                                       value="<?php echo esc_attr( $b_prefix ); ?>"
                                       placeholder="€" class="small-text" style="width:40px;" title="Prefix">
                                <input type="text" name="svgml_block_suffix[]"
                                       value="<?php echo esc_attr( $b_suffix ); ?>"
                                       placeholder="m²" class="small-text" style="width:40px;" title="Suffix">
                            </td>
                            <td>
                                <input type="text" name="svgml_block_static_value[]"
                                       value="<?php echo esc_attr( $b_static ); ?>"
                                       placeholder="<?php echo $is_static ? ( 'static_button' === $b_type ? 'https://...' : 'HTML inhoud...' ) : ''; ?>"
                                       class="regular-text svgml-block-static-val"
                                       <?php if ( ! $is_static ) echo 'style="display:none"'; ?>>
                            </td>
                            <td style="text-align:center">
                                <!-- Hidden input zorgt dat de array-index altijd aanwezig is,
                                     ook als de checkbox niet aangevinkt is (HTML-formulier-gedrag). -->
                                <input type="hidden" name="svgml_block_html[]" value="<?php echo $b_html ? '1' : '0'; ?>">
                                <input type="checkbox" class="svgml-block-html-cb"
                                       title="Veld bevat HTML-opmaak – render zonder escaping"
                                       <?php checked( $b_html, true ); ?>>
                            </td>
                            <td>
                                <button type="button" class="button svgml-remove-block" title="Verwijder blok">✕</button>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <?php foreach ( $block_types as $bt ) : ?>
                        <button type="button"
                                class="button button-secondary svgml-add-block"
                                data-type="<?php echo esc_attr( $bt ); ?>">
                            + <?php echo esc_html( $block_type_labels[ $bt ] ); ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <p class="description">
                    <strong>Tip:</strong> Voeg een <em>Thumbnail</em> blok toe als eerste om de afbeelding
                    bovenaan het panel te tonen. Gebruik <em>Badge</em> voor status, <em>Prijs</em> voor
                    geldbedragen (met automatische opmaak), <em>Link</em> voor klikbare URL's, en
                    <em>HTML (raw)</em> voor velden die HTML-opmaak bevatten (bijv. een <code>description</code>
                    met <code>&lt;div&gt;</code> of <code>&lt;p&gt;</code> tags).
                </p>
                </div><!-- .svgml-blocks-table-wrap -->

                <!-- Live voorbeeld panel: rendert het eerste JSON-object met de huidige blokken -->
                <div class="svgml-preview-outer">
                    <h3>Voorbeeld panel</h3>
                    <p class="svgml-preview-label"><?php echo $is_manual ? 'Op basis van je ingevulde vlakken.' : 'Op basis van het eerste object uit je JSON-feed.'; ?></p>
                    <div id="svgml-live-preview">
                        <p class="svgml-preview-empty">Voeg blokken toe om het voorbeeld te zien.</p>
                    </div>
                </div>

                </div><!-- .svgml-blocks-with-preview -->
            </div>

            <!-- ─── STATUS KLEUREN NOTITIE ──────────────────────────────── -->
            <!-- Status-instellingen staan in het Weergave-tabblad               -->
            <div class="svgml-section" style="border-left: 4px solid #f0a500;">
                <h2>Statuskleuren</h2>
                <div style="padding: 16px 24px; display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
                    <span style="font-size:13px; color:#555;">
                        De statuskleuren (Beschikbaar / Onder Optie / Verkocht) worden ingesteld in het
                        <strong>Weergave</strong>-tabblad. De badge in het info-panel pikt de kleuren
                        automatisch op vanuit die instellingen.
                    </span>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=svgml-display&map_id=' . $map_id ) ); ?>"
                       class="button button-secondary" style="flex-shrink:0;">
                        → Naar Weergave &amp; Statuskleuren
                    </a>
                </div>
                <?php if ( ! empty( $status_colors ) ) : ?>
                <div style="padding: 0 24px 16px; display:flex; gap:10px; flex-wrap:wrap;">
                    <?php foreach ( $status_colors as $sv => $sc ) :
                        $sh = $status_hex_colors[ $sv ] ?? '#888888';
                    ?>
                    <span style="display:inline-flex; align-items:center; gap:6px; font-size:12px; background:#f9f9f9; padding:5px 10px; border-radius:50px; border:1px solid #eee;">
                        <span style="width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr( $sh ); ?>;display:inline-block;flex-shrink:0;"></span>
                        <?php echo esc_html( $sv ); ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- ─── OVERZICHT PANEL ──────────────────────────────────────── -->
            <div class="svgml-section">
                <h2>Overzicht – Lijst bij laden</h2>
                <p class="svgml-description">
                    Optioneel: toon een lijst van <strong>alle gekoppelde objecten</strong> zodra de pagina laadt,
                    vóórdat een bezoeker op een regio klikt. Configureer hier welke velden per rij getoond worden.
                    Klikken op een rij in de lijst opent het detail-panel van dat object.
                </p>

                <table class="form-table">
                    <tr>
                        <th><label for="svgml_overview_enabled">Overzicht inschakelen</label></th>
                        <td>
                            <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                <input type="checkbox" id="svgml_overview_enabled"
                                       name="svgml_overview_enabled" value="1"
                                       <?php checked( $overview_enabled, true ); ?>>
                                <span>Toon een objectenlijst als startweergave in het panel</span>
                            </label>
                            <p class="description">
                                Bij inschakelen verschijnt bij het laden automatisch een lijst van alle objecten
                                die in de Regio Koppeling zijn opgenomen. Klikken op een rij toont het detailpanel.
                            </p>
                        </td>
                    </tr>
                </table>

                <!-- Flex-wrapper: tabel links, live voorbeeld rechts -->
                <div class="svgml-blocks-with-preview">
                <div class="svgml-blocks-table-wrap">
                <table class="wp-list-table widefat fixed striped" id="svgml-overview-table" style="margin:0">
                    <thead>
                        <tr>
                            <th style="width:28px">☰</th>
                            <th style="width:30%"><?php echo ( 'manual' === $map_mode ) ? 'Veld' : 'JSON Veld'; ?></th>
                            <th style="width:25%">Type</th>
                            <th>Label (optioneel)</th>
                            <th style="width:60px" title="Waarde bevat HTML-opmaak">HTML</th>
                            <th style="width:50px">✕</th>
                        </tr>
                    </thead>
                    <tbody id="svgml-overview-tbody">
                        <?php if ( ! empty( $overview_blocks ) ) :
                            // Overzicht-bloktypen (geen thumbnail of divider in lijst-rijen)
                            $ov_type_labels = [
                                'heading' => '🔤 Koptekst',
                                'badge'   => '🏷️ Badge',
                                'price'   => '💶 Prijs',
                                'text'    => '📝 Tekst',
                                'link'    => '🔗 Link',
                            ];
                            foreach ( $overview_blocks as $ob ) :
                                $ob_field = $ob['field'] ?? '';
                                $ob_type  = $ob['type']  ?? 'text';
                                $ob_label = $ob['label'] ?? '';
                                $ob_html  = ! empty( $ob['html'] );
                        ?>
                        <tr class="svgml-overview-row">
                            <td class="svgml-drag-handle">⠿</td>
                            <td>
                                <select name="svgml_overview_field[]" class="svgml-overview-field-select">
                                    <option value="">— kies veld —</option>
                                    <?php foreach ( $overview_field_options as $ov_opt ) : ?>
                                        <option value="<?php echo esc_attr( $ov_opt['value'] ); ?>"
                                            <?php selected( $ob_field, $ov_opt['value'] ); ?>>
                                            <?php echo esc_html( $ov_opt['label'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <?php
                                    $ov_known_values = array_column( $overview_field_options, 'value' );
                                    if ( ! in_array( $ob_field, $ov_known_values, true ) && ! empty( $ob_field ) ) : ?>
                                        <option value="<?php echo esc_attr( $ob_field ); ?>" selected>
                                            <?php echo esc_html( $ob_field ); ?>
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </td>
                            <td>
                                <select name="svgml_overview_type[]">
                                    <?php foreach ( $ov_type_labels as $ot_val => $ot_label ) : ?>
                                        <option value="<?php echo esc_attr( $ot_val ); ?>"
                                            <?php selected( $ob_type, $ot_val ); ?>>
                                            <?php echo esc_html( $ot_label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="text" name="svgml_overview_label[]"
                                       value="<?php echo esc_attr( $ob_label ); ?>"
                                       placeholder="Optioneel label" class="regular-text">
                            </td>
                            <td style="text-align:center">
                                <input type="hidden" name="svgml_overview_html[]" value="<?php echo $ob_html ? '1' : '0'; ?>">
                                <input type="checkbox" class="svgml-block-html-cb"
                                    <?php checked( $ob_html ); ?>
                                    title="Waarde bevat HTML-opmaak">
                            </td>
                            <td>
                                <button type="button" class="button svgml-remove-overview" title="Verwijder">✕</button>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <div style="padding:18px 24px 10px; display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="button" class="button button-secondary svgml-add-overview" data-type="heading">+ 🔤 Koptekst</button>
                    <button type="button" class="button button-secondary svgml-add-overview" data-type="badge">+ 🏷️ Badge</button>
                    <button type="button" class="button button-secondary svgml-add-overview" data-type="price">+ 💶 Prijs</button>
                    <button type="button" class="button button-secondary svgml-add-overview" data-type="text">+ 📝 Tekst</button>
                    <button type="button" class="button button-secondary svgml-add-overview" data-type="link">+ 🔗 Link</button>
                </div>
                <p class="description">
                    <strong>Tip:</strong> Voeg een <em>Koptekst</em> toe voor de naam van het object,
                    een <em>Badge</em> voor de status, en een <em>Prijs</em> voor het bedrag.
                    Zo krijgt elke rij in de lijst direct de belangrijkste informatie.
                </p>
                </div><!-- .svgml-blocks-table-wrap -->

                <!-- Live voorbeeld: toont hoe één rij in de overzichtslijst eruitziet -->
                <div class="svgml-preview-outer">
                    <h3>Voorbeeld overzichtsrij</h3>
                    <p class="svgml-preview-label"><?php echo $is_manual ? 'Op basis van je ingevulde vlakken.' : 'Op basis van het eerste object uit je JSON-feed.'; ?></p>
                    <div id="svgml-overview-live-preview">
                        <p class="svgml-preview-empty">Voeg blokken toe om het voorbeeld te zien.</p>
                    </div>
                </div>

                </div><!-- .svgml-blocks-with-preview -->
            </div>

            <!-- ─── GEAVANCEERD: LEGACY VELDEN ───────────────────────────── -->
            <details class="svgml-section svgml-section-advanced">
                <summary style="cursor:pointer; padding:12px 20px; font-weight:600; font-size:13px;">
                    ⚙️ Geavanceerd: Te tonen velden (legacy)
                </summary>
                <p class="svgml-description" style="margin:0; padding:10px 20px; background:#fffbe6; border-left:3px solid #f0b429;">
                    <strong>Let op:</strong> Dit veld is alleen van toepassing als er <em>geen Panel Blokken</em> zijn geconfigureerd (zie hierboven).
                    Met blokken ingesteld heeft dit veld geen effect. Gebruik dit als eenvoudige fallback zonder visuele opmaak.
                </p>
                <table class="form-table">
                    <tr>
                        <th><label for="svgml_display_fields">Te tonen velden</label></th>
                        <td>
                            <textarea id="svgml_display_fields"
                                      name="svgml_display_fields"
                                      class="large-text"
                                      rows="6"
                                      placeholder="naam&#10;adres&#10;telefoon&#10;beschrijving"><?php
                                echo esc_textarea( implode( "\n", $display_fields ) );
                            ?></textarea>
                            <p class="description">
                                Vul één veldnaam per regel in, zoals ze in je JSON-objecten voorkomen.<br>
                                Voorbeeld: <code>naam</code>, <code>adres</code>, <code>website</code>
                            </p>
                        </td>
                    </tr>
                </table>
            </details>

            <?php
            /**
             * Hidden field: populated by assets/js/panel-builder.js right
             * before the form submits.  The JS walks every .svgml-block-row,
             * builds an array of objects and JSON.stringifies it here.
             * The PHP save handler below reads this field as its primary path.
             */
            ?>
            <input type="hidden" name="svgml_panel_blocks" id="svgml_panel_blocks" value="">

            <?php submit_button( 'Panel Builder opslaan' ); ?>
        </form>

        <!-- ── Verborgen rij-template voor JavaScript ─────────────────────── -->
        <template id="svgml-block-row-template">
            <tr class="svgml-block-row">
                <td class="svgml-drag-handle" title="Versleep om volgorde te wijzigen">⠿</td>
                <?php if ( ! $is_manual ) : ?>
                <td>
                    <select name="svgml_block_field[]" class="svgml-block-field-select">
                        <option value="">— kies veld —</option>
                        <?php foreach ( $field_names as $fn ) : ?>
                            <option value="<?php echo esc_attr( $fn ); ?>"><?php echo esc_html( $fn ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <?php else : ?>
                <input type="hidden" name="svgml_block_field[]" value="">
                <?php endif; ?>
                <td>
                    <select name="svgml_block_type[]" class="svgml-block-type-select">
                        <?php foreach ( $block_type_labels as $bt_val => $bt_label ) : ?>
                            <option value="<?php echo esc_attr( $bt_val ); ?>"><?php echo esc_html( $bt_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <select name="svgml_block_width[]" class="svgml-block-width-select">
                        <?php foreach ( $block_width_options as $bw_val => $bw_label ) : ?>
                            <option value="<?php echo esc_attr( $bw_val ); ?>"><?php echo esc_html( $bw_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <input type="text" name="svgml_block_label[]"
                           placeholder="Label" class="regular-text" style="width:100px">
                    <input type="text" name="svgml_block_prefix[]"
                           placeholder="€" class="small-text" style="width:40px;" title="Prefix">
                    <input type="text" name="svgml_block_suffix[]"
                           placeholder="m²" class="small-text" style="width:40px;" title="Suffix">
                </td>
                <td>
                    <input type="text" name="svgml_block_static_value[]"
                           placeholder="Inhoud / URL"
                           class="regular-text svgml-block-static-val"
                           style="display:none">
                </td>
                <td style="text-align:center">
                    <input type="hidden" name="svgml_block_html[]" value="0">
                    <input type="checkbox" class="svgml-block-html-cb"
                           title="Veld bevat HTML-opmaak – render zonder escaping">
                </td>
                <td>
                    <button type="button" class="button svgml-remove-block" title="Verwijder blok">✕</button>
                </td>
            </tr>
        </template>

        <!-- ── Verborgen rij-template voor overzicht-rijen ──────────────── -->
        <template id="svgml-overview-row-template">
            <tr class="svgml-overview-row">
                <td class="svgml-drag-handle">⠿</td>
                <td>
                    <select name="svgml_overview_field[]" class="svgml-overview-field-select">
                        <option value="">— kies veld —</option>
                        <?php foreach ( $overview_field_options as $ov_opt ) : ?>
                            <option value="<?php echo esc_attr( $ov_opt['value'] ); ?>"><?php echo esc_html( $ov_opt['label'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <select name="svgml_overview_type[]">
                        <option value="heading">🔤 Koptekst</option>
                        <option value="badge">🏷️ Badge</option>
                        <option value="price">💶 Prijs</option>
                        <option value="text">📝 Tekst</option>
                        <option value="link">🔗 Link</option>
                    </select>
                </td>
                <td>
                    <input type="text" name="svgml_overview_label[]"
                           placeholder="Optioneel label" class="regular-text">
                </td>
                <td style="text-align:center">
                    <input type="hidden" name="svgml_overview_html[]" value="0">
                    <input type="checkbox" class="svgml-block-html-cb"
                        title="Waarde bevat HTML-opmaak">
                </td>
                <td>
                    <button type="button" class="button svgml-remove-overview" title="Verwijder">✕</button>
                </td>
            </tr>
        </template>

    </div>
    <?php
}
