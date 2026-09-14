<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Mapping page for SVG Map Lite
 * Extracted from svg-map-lite.php with multi-map support
 */


function svgml_render_mapping_page( $map_id ) {

    // Get map mode to determine which interface to show
    $map_mode = get_post_meta( $map_id, '_svgml_map_mode', true ) ?: 'json';

    // Show JSON mapping interface for JSON mode
    if ( 'json' === $map_mode ) {
        svgml_render_json_mapping_interface( $map_id );
    } else {
        // Show manual data entry interface for manual mode
        svgml_render_manual_data_interface( $map_id );
    }
}

/**
 * JSON Mode: Region mapping interface (original functionality)
 * Maps SVG IDs to JSON object IDs
 */
function svgml_render_json_mapping_interface( $map_id ) {
    if ( isset( $_POST['svgml_mapping_nonce'] ) ) {

        if ( ! wp_verify_nonce( $_POST['svgml_mapping_nonce'], 'svgml_save_mapping' ) ) {
            echo '<div class="notice notice-error"><p>Beveiligingsfout. Probeer opnieuw.</p></div>';
        } else {
            // ── Koppelingen opslaan ──────────────────────────────────────────
            // $_POST['svgml_mapping'] is een associatieve array:
            // [ 'svg-id-naam' => 'json-object-id', ... ]
            $raw_mapping   = $_POST['svgml_mapping'] ?? [];
            $clean_mapping = [];

            foreach ( $raw_mapping as $svg_id => $json_id ) {
                $clean_svg_id  = sanitize_text_field( $svg_id );
                $clean_json_id = sanitize_text_field( $json_id );

                if ( ! empty( $clean_svg_id ) && ! empty( $clean_json_id ) ) {
                    $clean_mapping[ $clean_svg_id ] = $clean_json_id;
                }
            }

            update_post_meta( $map_id, '_svgml_id_mapping', $clean_mapping );

            // ── Uitgesloten regio's opslaan ──────────────────────────────────
            // $_POST['svgml_excluded'] is een gewone array van SVG-id's waarvan
            // de checkbox is aangevinkt. Als er niets aangevinkt is, bestaat de
            // sleutel niet in $_POST – vandaar de ?? [] als fallback.
            $raw_excluded   = $_POST['svgml_excluded'] ?? [];
            $clean_excluded = [];

            foreach ( $raw_excluded as $svg_id ) {
                // sanitize_text_field() verwijdert gevaarlijke tekens
                $clean_id = sanitize_text_field( $svg_id );
                if ( ! empty( $clean_id ) ) {
                    $clean_excluded[] = $clean_id;
                }
            }

            // array_unique() voorkomt dubbele vermeldingen
            update_post_meta( $map_id, '_svgml_excluded_ids', array_values( array_unique( $clean_excluded ) ) );

            echo '<div class="notice notice-success is-dismissible"><p>Koppelingen opgeslagen!</p></div>';
        }
    }

    // Huidige waarden ophalen
    $svg_ids      = get_post_meta( $map_id, '_svgml_svg_ids', true ) ?: [];
    $id_mapping   = get_post_meta( $map_id, '_svgml_id_mapping', true ) ?: [];
    $excluded_ids = get_post_meta( $map_id, '_svgml_excluded_ids', true ) ?: [];
    $json_url     = get_post_meta( $map_id, '_svgml_json_url', true ) ?: '';
    $layers       = get_post_meta( $map_id, '_svgml_layers', true ) ?: [];
    $source_type  = get_post_meta( $map_id, '_svgml_source_type', true ) ?: 'svg';

    // ── Build layer lookup voor image-modus met meerdere lagen ────────────────
    $id_to_layer = [];
    if ( 'image' === $source_type && ! empty( $layers ) ) {
        foreach ( $layers as $li => $layer ) {
            foreach ( ($layer['polygons'] ?? []) as $poly ) {
                $pid = $poly['id'] ?? '';
                if ( $pid ) {
                    $id_to_layer[ $pid ] = $layer['name'] ?? ('Laag ' . ($li + 1));
                    if ( ! in_array( $pid, $svg_ids, true ) ) {
                        $svg_ids[] = $pid;
                    }
                }
            }
        }
    }

    // Toon een waarschuwing als er nog geen SVG-ID's zijn
    if ( empty( $svg_ids ) ) { ?>
        <div class="wrap svgml-admin-wrap">
            <h1><span class="dashicons dashicons-location-alt"></span> SVG Map Lite – Regio Koppeling</h1>
            <div class="notice notice-warning">
                <p>
                    Nog geen SVG geüpload of geen ID's gevonden in de SVG.
                    <a href="<?php echo admin_url( 'admin.php?page=svgml-settings&map_id=' . $map_id ); ?>">
                        → Ga eerst naar Instellingen
                    </a>
                </p>
            </div>
        </div>
        <?php return;
    } ?>

    <div class="wrap svgml-admin-wrap">
        <h1><span class="dashicons dashicons-location-alt"></span> SVG Map Lite – Regio Koppeling</h1>

        <p>
            Vul hieronder voor elke SVG-regio het bijbehorende JSON-object ID in.
            Dit is de waarde van het <em>ID-veld</em> dat je in de instellingen hebt opgegeven
            (<code><?php echo esc_html( get_post_meta( $map_id, '_svgml_json_id_field', true ) ?: 'id' ); ?></code>).<br>

            <?php if ( $json_url ) : ?>
                <strong>JSON Feed:</strong>
                <a href="<?php echo esc_url( $json_url ); ?>" target="_blank">
                    <?php echo esc_html( $json_url ); ?>
                </a>
            <?php else : ?>
                <em>JSON Feed URL nog niet ingesteld.
                <a href="<?php echo admin_url( 'admin.php?page=svgml-settings&map_id=' . $map_id ); ?>">Stel in via Instellingen</a>.</em>
            <?php endif; ?>
        </p>

        <form method="post" action="">
            <?php wp_nonce_field( 'svgml_save_mapping', 'svgml_mapping_nonce' ); ?>

            <?php
            // ── Build a per-layer grouping of SVG IDs ──────────────────────
            // With multiple layers we show a separate table per layer.
            // With one layer or SVG mode: just one table without layer title.
            $has_multi_layers = ( 'image' === $source_type && count( $layers ) > 1 );

            if ( $has_multi_layers ) {
                // Group SVG IDs per layer
                $layer_groups = []; // [ layer-name => [ svg_id, ... ] ]
                $ungrouped    = []; // IDs that don't belong to a layer

                foreach ( $layers as $li => $layer ) {
                    $lname = $layer['name'] ?? ( 'Laag ' . ( $li + 1 ) );
                    $layer_groups[ $lname ] = [];
                    foreach ( ( $layer['polygons'] ?? [] ) as $poly ) {
                        $pid = $poly['id'] ?? '';
                        if ( $pid ) $layer_groups[ $lname ][] = $pid;
                    }
                }

                // IDs that are in svgml_svg_ids but not in a layer (edge case)
                $all_grouped = [];
                foreach ( $layer_groups as $ids ) $all_grouped = array_merge( $all_grouped, $ids );
                $ungrouped = array_diff( $svg_ids, $all_grouped );

                // Render one table per layer
                foreach ( $layer_groups as $lname => $layer_svg_ids ) :
                    if ( empty( $layer_svg_ids ) ) continue;
                ?>
                    <div class="svgml-layer-section">
                        <h2 class="svgml-layer-section-title">
                            <span class="dashicons dashicons-format-image" style="margin-right:6px; color:var(--svgml-red, #2a9d8f);"></span>
                            <?php echo esc_html( $lname ); ?>
                            <span class="svgml-layer-section-count">(<?php echo count( $layer_svg_ids ); ?> regions)</span>
                        </h2>

                        <?php svgml_render_mapping_table( $layer_svg_ids, $id_mapping, $excluded_ids ); ?>
                    </div>
                <?php endforeach;

                // Any ungrouped IDs
                if ( ! empty( $ungrouped ) ) : ?>
                    <div class="svgml-layer-section">
                        <h2 class="svgml-layer-section-title">
                            <span class="dashicons dashicons-warning" style="margin-right:6px; color:#f0a500;"></span>
                            Unassigned Regions
                            <span class="svgml-layer-section-count">(<?php echo count( $ungrouped ); ?>)</span>
                        </h2>

                        <?php svgml_render_mapping_table( array_values( $ungrouped ), $id_mapping, $excluded_ids ); ?>
                    </div>
                <?php endif;

            } else {
                // Single layer or SVG mode: one table
                svgml_render_mapping_table( $svg_ids, $id_mapping, $excluded_ids );
            }
            ?>

            <?php submit_button( 'Koppelingen opslaan' ); ?>
        </form>
    </div>
    <?php
}

/**
 * Render a mapping table for a set of SVG IDs.
 * Reused for each layer (or for all IDs in single-layer/SVG mode).
 *
 * @param array $svg_ids       List of SVG ID strings
 * @param array $id_mapping    [ svg_id => json_id ] mapping data
 * @param array $excluded_ids  List of excluded SVG ID strings
 */
function svgml_render_mapping_table( $svg_ids, $id_mapping, $excluded_ids ) {
    ?>
    <table class="wp-list-table widefat fixed striped svgml-mapping-table">
        <thead>
            <tr>
                <th class="svgml-col-svg">SVG Region ID</th>
                <th class="svgml-col-json">JSON Object ID (value)</th>
                <th class="svgml-col-confirm">Confirmation (name)</th>
                <th class="svgml-col-exclude">Exclude</th>
                <th class="svgml-col-status">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $svg_ids as $svg_id ) :
                $mapped_value = $id_mapping[ $svg_id ] ?? '';
                $is_excluded  = in_array( $svg_id, $excluded_ids, true );
            ?>
            <tr class="<?php echo $is_excluded ? 'svgml-row-excluded' : ''; ?>"
                data-excluded="<?php echo $is_excluded ? '1' : '0'; ?>">

                <td>
                    <strong><code><?php echo esc_html( $svg_id ); ?></code></strong>
                </td>

                <td>
                    <input type="text"
                           name="svgml_mapping[<?php echo esc_attr( $svg_id ); ?>]"
                           value="<?php echo esc_attr( $mapped_value ); ?>"
                           class="regular-text svgml-mapping-input"
                           placeholder="Bijv. 42  of  amsterdam"
                           <?php echo $is_excluded ? 'disabled' : ''; ?>>
                </td>

                <!-- Bevestigingskolom: wordt via AJAX ingevuld door admin.js -->
                <td>
                    <span class="svgml-confirm-name"
                          data-svg-id="<?php echo esc_attr( $svg_id ); ?>">
                        <?php echo ( $mapped_value && ! $is_excluded ) ? '<em class="svgml-confirm-loading">laden…</em>' : ''; ?>
                    </span>
                </td>

                <!-- Uitsluit-kolom -->
                <td class="svgml-col-exclude-cell">
                    <label class="svgml-exclude-label">
                        <input type="checkbox"
                               name="svgml_excluded[]"
                               value="<?php echo esc_attr( $svg_id ); ?>"
                               class="svgml-exclude-checkbox"
                               <?php checked( $is_excluded ); ?>>
                        <span class="svgml-exclude-text">
                            <?php echo $is_excluded ? 'Uitgesloten' : 'Uitsluiten'; ?>
                        </span>
                    </label>
                </td>

                <td>
                    <?php if ( $is_excluded ) : ?>
                        <span class="svgml-status-excluded">⊘ Uitgesloten</span>
                    <?php elseif ( $mapped_value ) : ?>
                        <span class="svgml-status-ok">✓ Gekoppeld aan: <em><?php echo esc_html( $mapped_value ); ?></em></span>
                    <?php else : ?>
                        <span class="svgml-status-empty">– Niet gekoppeld</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

/**
 * Geeft een leesbare naam voor een paneelblok-type, voor gebruik als label
 * wanneer de gebruiker zelf geen label heeft ingevuld in de Panel Builder.
 *
 * Waarom een aparte functie met een array: zo staat de vertaling van
 * type naar naam op één plek, en kan er later gewoon een regel bij als er
 * een nieuw bloktype bijkomt in de Panel Builder — zonder deze functie te
 * hoeven doorzoeken op if/else-ketens.
 *
 * @param string $type    Het bloktype, bv. 'thumbnail', 'heading', ...
 * @param int    $index   Index (0-based) van het blok in $panel_config.
 * @return string         Leesbare naam, bv. "Thumbnail (veld 3)".
 */
function svgml_get_manual_field_fallback_label( $type, $index ) {
    // Volgnummer is 1-based voor de gebruiker (index 0 = "veld 1").
    $field_number = $index + 1;

    // Bekende bloktypes uit de Panel Builder → leesbare Nederlandse naam.
    $type_names = array(
        'thumbnail'      => 'Thumbnail',
        'heading'        => 'Kop',
        'price'          => 'Prijs',
        'badge'          => 'Badge',
        'link'           => 'Link',
        'divider'        => 'Scheidingslijn',
        'static_button'  => 'Knop',
        'static_html'    => 'HTML',
        'text'           => 'Tekst',
        'html'           => 'HTML',
    );

    if ( isset( $type_names[ $type ] ) ) {
        // Volgnummer blijft zichtbaar, maar is nu puur decoratief: het helpt de
        // gebruiker een blok visueel te herkennen/terugvinden. Het beschrijft
        // NIET meer de echte opslagsleutel — die is een stabiele sleutel in
        // $block['field'] (toegekend bij opslaan, of via de eenmalige migratie
        // in includes/manual-field-keys.php), met manual_field_{index} alleen
        // nog als permanente fallback voor niet-gemigreerde data. Zie
        // svgml_get_manual_field_key() voor de volledige uitleg.
        return $type_names[ $type ] . ' (veld ' . $field_number . ')';
    }

    // Onbekend/leeg type: val terug op de oude, generieke naam.
    return 'Veld ' . $field_number;
}

/**
 * Manual Mode: Data entry interface for manual region mapping
 * Displays a two-column layout: polygon list (left) + data form (right)
 */
function svgml_render_manual_data_interface( $map_id ) {
    // Fetch panel config (dynamic fields)
    $panel_config = get_post_meta( $map_id, '_svgml_panel_blocks', true );
    if ( empty( $panel_config ) || ! is_array( $panel_config ) ) {
        $panel_config = [];
    }

    // Handle form submission for manual data
    if ( isset( $_POST['svgml_manual_data_nonce'] ) ) {
        if ( ! wp_verify_nonce( $_POST['svgml_manual_data_nonce'], 'svgml_save_manual_data' ) ) {
            echo '<div class="notice notice-error"><p>Beveiligingsfout. Probeer opnieuw.</p></div>';
        } else {
            // ── A0-bis: verouderd-formulier-guard ─────────────────────────────
            // Zelfde val als bij de Panel Builder (zie includes/panel-builder.php,
            // A0), maar hier destructiever: zonder deze guard zou isset($fields[$field_key])
            // hieronder voor ELKE sleutel falen als het formulier vóór een
            // plugin-update gerenderd is (data-manual-key's zijn dan nog
            // manual_field_N, terwijl _svgml_manual_data intussen door de
            // admin_init-migratie al mf_... sleutels heeft) — met als gevolg dat
            // update_post_meta() de VOLLEDIGE regiodata met lege strings zou
            // overschrijven. Geen ontkoppeling maar totale leegte. Vandaar: bij
            // een ontbrekende/verouderde marker helemaal niets opslaan.
            $svgml_manual_form_v = $_POST['svgml_manual_form_v'] ?? '';
            if ( '2' !== $svgml_manual_form_v ) {
                echo '<div class="notice notice-error"><p>Deze pagina was verouderd. Herlaad de pagina en probeer opnieuw.</p></div>';
            } else {
            // Process and save all region data from JSON payload
            $json_all = isset( $_POST['svgml_manual_data_all'] ) ? wp_unslash( $_POST['svgml_manual_data_all'] ) : '';
            if ( ! isset( $_POST['svgml_manual_data_all'] ) ) {
                echo '<div class="notice notice-error"><p>Geen gegevens ontvangen (veld ontbreekt). Probeer opnieuw.</p></div>';
            } elseif ( empty( $panel_config ) ) {
                echo '<div class="notice notice-error"><p>Geen paneel-velden geconfigureerd. Stel eerst de Panel Builder in.</p></div>';
            } else {
                $decoded = json_decode( $json_all, true );
                if ( ! is_array( $decoded ) ) {
                    echo '<div class="notice notice-error"><p>Ongeldige JSON ontvangen. Probeer opnieuw.</p></div>';
                } else {
                    // Nodig voor het A0-ter-vangnet hieronder: een overgeslagen regio
                    // moet zijn BESTAANDE waarden behouden. update_post_meta() verderop
                    // vervangt de HELE meta in één keer, dus 'continue' zonder deze data
                    // zou die regio niet met rust laten maar hem gewoon wissen — precies
                    // het dataverlies dat A0-ter moest voorkomen.
                    $existing_data = get_post_meta( $map_id, '_svgml_manual_data', true );
                    if ( ! is_array( $existing_data ) ) {
                        $existing_data = [];
                    }

                    $new_data = [];
                    // Insert in submitted order so drag-to-reorder is preserved.
                    foreach ( $decoded as $poly_id => $fields ) {
                        $clean_id = sanitize_text_field( (string) $poly_id );
                        if ( ! $clean_id || ! is_array( $fields ) ) {
                            // Ongeldige regio-ID of geen array aan velden: dit was al zo
                            // vóór deze wijziging (bestaand gedrag, geen regressie) — een
                            // regio met een kapotte/lege ID kan sowieso niet teruggevonden
                            // worden in $existing_data, dus hier bewust WEL 'continue'
                            // zonder de bestaande-data-behoud-truc van A0-ter hieronder.
                            continue;
                        }

                        // ── A0-ter: onafhankelijk vangnet, los van de A0-bis-marker ──
                        // Een sleutelmismatch is sowieso ongewenst gedrag, ook buiten
                        // het update-scenario (bug, race condition, iets dat A0-bis
                        // niet dekt). Bevat $fields wél waarden, maar komt er GEEN
                        // ENKELE verwachte sleutel in voor, dan is dit vrijwel zeker
                        // een mismatch — sla deze regio dan over in plaats van hem
                        // met lege strings te overschrijven. Bestaande data voor deze
                        // regio blijft dan gewoon staan: expliciet overgenomen uit
                        // $existing_data, want anders zou deze regio simpelweg
                        // ontbreken in $new_data en dus alsnog verdwijnen zodra
                        // update_post_meta() de volledige meta vervangt.
                        $expected_keys_present = false;
                        foreach ( $panel_config as $i => $block ) {
                            if ( array_key_exists( svgml_get_manual_field_key( $block, $i ), $fields ) ) {
                                $expected_keys_present = true;
                                break;
                            }
                        }
                        if ( ! empty( $fields ) && ! $expected_keys_present ) {
                            if ( isset( $existing_data[ $clean_id ] ) ) {
                                $new_data[ $clean_id ] = $existing_data[ $clean_id ];
                            }
                            continue;
                        }

                        $clean_fields = [];
                        foreach ( $panel_config as $i => $block ) {
                            $field_key = svgml_get_manual_field_key( $block, $i );
                            $clean_fields[ $field_key ] = isset( $fields[ $field_key ] )
                                ? sanitize_text_field( $fields[ $field_key ] )
                                : '';
                        }
                        $new_data[ $clean_id ] = $clean_fields;
                    }
                    update_post_meta( $map_id, '_svgml_manual_data', $new_data );
                    delete_transient( 'svgml_html_' . $map_id );
                    echo '<div class="notice notice-success is-dismissible"><p>Regio gegevens opgeslagen!</p></div>';
                }
            }
            } // einde A0-bis-guard else
        }
    }

    // Handle exclude toggle (submitted via AJAX button in the sidebar)
    if ( isset( $_POST['svgml_exclude_toggle_nonce'] ) ) {
        if ( wp_verify_nonce( $_POST['svgml_exclude_toggle_nonce'], 'svgml_exclude_toggle' ) ) {
            $toggle_id   = sanitize_text_field( $_POST['svgml_toggle_id'] ?? '' );
            $excl        = get_post_meta( $map_id, '_svgml_excluded_ids', true ) ?: [];
            if ( ! is_array( $excl ) ) $excl = [];
            if ( in_array( $toggle_id, $excl, true ) ) {
                $excl = array_values( array_diff( $excl, [ $toggle_id ] ) );
            } else {
                $excl[] = $toggle_id;
            }
            update_post_meta( $map_id, '_svgml_excluded_ids', $excl );
            delete_transient( 'svgml_html_' . $map_id );
        }
    }

    // Get map data
    $layers      = get_post_meta( $map_id, '_svgml_layers', true ) ?: [];
    $source_type = get_post_meta( $map_id, '_svgml_source_type', true ) ?: 'svg';
    $manual_data = get_post_meta( $map_id, '_svgml_manual_data', true ) ?: [];
    $excluded_ids = get_post_meta( $map_id, '_svgml_excluded_ids', true ) ?: [];
    if ( ! is_array( $excluded_ids ) ) $excluded_ids = [];

    // Build list of all polygons
    $all_polygons = [];
    if ( 'image' === $source_type && ! empty( $layers ) ) {
        foreach ( $layers as $layer_idx => $layer ) {
            foreach ( ( $layer['polygons'] ?? [] ) as $poly ) {
                $pid = $poly['id'] ?? '';
                if ( $pid ) {
                    $all_polygons[] = [
                        'id'    => $pid,
                        'name'  => $poly['name'] ?? $pid,
                        'layer' => $layer['name'] ?? ( 'Laag ' . ( $layer_idx + 1 ) ),
                    ];
                }
            }
        }
    } else {
        $svg_ids = get_post_meta( $map_id, '_svgml_svg_ids', true ) ?: [];
        foreach ( $svg_ids as $pid ) {
            if ( $pid ) {
                $all_polygons[] = [
                    'id'    => $pid,
                    'name'  => $pid,
                    'layer' => '',
                ];
            }
        }
    }

    // Sort the sidebar list by the saved manualData key order so drag-to-reorder
    // is reflected after page reload. Regions without saved data go at the end.
    if ( ! empty( $manual_data ) ) {
        $saved_order = array_keys( $manual_data );
        usort( $all_polygons, function( $a, $b ) use ( $saved_order ) {
            $pos_a = array_search( $a['id'], $saved_order, true );
            $pos_b = array_search( $b['id'], $saved_order, true );
            if ( $pos_a === false && $pos_b === false ) return 0;
            if ( $pos_a === false ) return 1;
            if ( $pos_b === false ) return -1;
            return $pos_a - $pos_b;
        } );
    }

    if ( empty( $all_polygons ) ) {
        ?>
        <div class="wrap svgml-admin-wrap">
            <h1><span class="dashicons dashicons-location-alt"></span> SVG Map Lite – Region Data</h1>
            <div class="notice notice-warning">
                <p>
                    Geen regio&#39;s gevonden in de kaart.
                    <a href="<?php echo admin_url( 'admin.php?page=svgml-settings&map_id=' . $map_id ); ?>">
                        &rarr; Ga eerst naar Instellingen
                    </a>
                </p>
            </div>
        </div>
        <?php return;
    }
    ?>

    <div class="wrap svgml-admin-wrap">
        <h1><span class="dashicons dashicons-location-alt"></span> SVG Map Lite – Region Data</h1>
        
        <div class="svgml-manual-layout">
            <div class="svgml-manual-sidebar">
                <h3 style="margin-top:0; color:var(--svgml-red, #2a9d8f);">Regio&#39;s</h3>
                <div class="svgml-polygon-list">
                    <?php foreach ( $all_polygons as $idx => $poly ) :
                        $is_excluded = in_array( $poly['id'], $excluded_ids, true );
                        $is_selected = ( $idx === 0 && ! $is_excluded );
                        $has_data    = isset( $manual_data[ $poly['id'] ] );
                    ?>
                        <div class="svgml-polygon-item <?php echo $is_selected ? 'active' : ''; ?> <?php echo $has_data ? 'has-data' : ''; ?> <?php echo $is_excluded ? 'svgml-item-excluded' : ''; ?>"
                             data-polygon-id="<?php echo esc_attr( $poly['id'] ); ?>"
                             data-polygon-index="<?php echo $idx; ?>">
                            <span class="svgml-drag-handle" title="Versleep om volgorde te wijzigen">⠿</span>
                            <div class="svgml-polygon-item-content">
                                <div class="svgml-polygon-item-name">
                                    <?php echo esc_html( $poly['name'] ); ?>
                                    <?php if ( $has_data && ! $is_excluded ) : ?>
                                        <span class="svgml-data-indicator" title="Gegevens ingevuld">&#10003;</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ( $poly['layer'] ) : ?>
                                <div class="svgml-polygon-item-layer">
                                    <?php echo esc_html( $poly['layer'] ); ?>
                                </div>
                                <?php endif; ?>
                                <form method="post" style="margin:0">
                                    <?php wp_nonce_field( 'svgml_exclude_toggle', 'svgml_exclude_toggle_nonce' ); ?>
                                    <input type="hidden" name="svgml_toggle_id" value="<?php echo esc_attr( $poly['id'] ); ?>">
                                    <button type="submit" class="svgml-exclude-toggle-btn <?php echo $is_excluded ? 'is-excluded' : ''; ?>"
                                            title="<?php echo $is_excluded ? 'Herstellen' : 'Uitsluiten'; ?>">
                                        <?php echo $is_excluded ? '⊘ Uitgesloten' : '✕ Uitsluiten'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="svgml-manual-content">
                <form method="post" action="" class="svgml-manual-form" id="svgml-manual-form">
                    <?php wp_nonce_field( 'svgml_save_manual_data', 'svgml_manual_data_nonce' ); ?>
                    <!-- Versie-marker voor de A0-bis-verouderd-formulier-guard in de
                         opslag-handler hierboven: ontbreekt deze waarde (of is hij
                         niet '2'), dan weigert de handler de opslag bewust — dat
                         voorkomt dat een vóór-de-update gerenderde pagina (met nog
                         manual_field_N als data-manual-key) na een update alle
                         regiodata met lege strings overschrijft. -->
                    <input type="hidden" name="svgml_manual_form_v" value="2">

                    <input type="hidden" name="polygon_id" id="polygon_id" value="<?php echo isset( $all_polygons[0] ) ? esc_attr( $all_polygons[0]['id'] ) : ''; ?>">
                    <input type="hidden" name="svgml_manual_data_all" id="svgml_manual_data_all" value="">
                    
                    <?php if ( empty( $panel_config ) ) : ?>
                        <div class="form-group" style="padding:24px 12px;color:#b00;background:#fff3f3;border:1px solid #f5cccc;border-radius:6px;text-align:center;font-weight:600;">
                            Bouw eerst je paneel in de <em>Panel Builder</em> tab om hier velden in te vullen.
                        </div>
                    <?php else : ?>
                        <?php foreach ( $panel_config as $i => $block ) :
                            $type = isset( $block['type'] ) ? $block['type'] : '';
                            // Eigen label heeft voorrang; zonder label vallen we terug op een
                            // naam afgeleid van het bloktype (zie svgml_get_manual_field_fallback_label()),
                            // want "Veld 1" zegt niets over wat er in dat vak hoort.
                            $label = isset( $block['label'] ) && !empty( $block['label'] )
                                ? esc_html( $block['label'] )
                                : esc_html( svgml_get_manual_field_fallback_label( $type, $i ) );
                            $field_key = svgml_get_manual_field_key( $block, $i );
                        ?>
                        <div class="form-group">
                            <label for="<?php echo esc_attr( $field_key ); ?>">
                                <strong><?php echo $label; ?></strong>
                            </label>
                            <?php if ( $type === 'thumbnail' ) : ?>
                                <?php
                                // Paneelblok type 'thumbnail': hier moet een AFBEELDING gekozen worden.
                                // Belangrijk: het opgeslagen formaat blijft een URL-string (net als bij
                                // een gewoon tekstveld) — géén attachment-ID — want de frontend
                                // (panel-renderer.js) bouwt hiermee direct een <img src="..."> op en
                                // verwacht dus een http(s)-URL, geen numeriek ID.
                                //
                                // Daarom blijft dit gewoon een <input type="text"> met dezelfde
                                // class="svgml-manual-field" en data-manual-key als elk ander veld:
                                // de JS verderop in dit bestand verzamelt ALLE waarden puur op basis
                                // van die class + attribuut (zie saveCurrentToManualData()). Verlies je
                                // die class of dat attribuut, dan wordt er bij opslaan stilzwijgend een
                                // lege waarde weggeschreven — geen foutmelding, dus lastig te debuggen.
                                //
                                // De knop "Kies afbeelding" hiernaast is puur een hulpmiddel: hij vult
                                // via JS (wp.media()) alleen de URL in ditzelfde tekstveld. Met de hand
                                // een URL plakken blijft dus gewoon werken (backward compatible met
                                // bestaande handmatige data).
                                ?>
                                <div class="svgml-thumbnail-field-wrap">
                                    <input type="text"
                                        id="<?php echo esc_attr( $field_key ); ?>"
                                        name="<?php echo esc_attr( $field_key ); ?>"
                                        class="regular-text svgml-manual-field svgml-thumbnail-input"
                                        data-manual-key="<?php echo esc_attr( $field_key ); ?>"
                                        placeholder="https://...">
                                    <button type="button" class="button svgml-thumbnail-pick-btn">Kies afbeelding</button>
                                    <?php // Kleine voorbeeldweergave. De <img> begint verborgen (geen src);
                                    // JS toont hem zodra het tekstveld een geldige http(s)-URL bevat —
                                    // zowel na kiezen via de media-library als na handmatig plakken. ?>
                                    <div class="svgml-thumbnail-preview">
                                        <img src="" alt="" style="display:none;">
                                    </div>
                                </div>
                            <?php elseif ( $type === 'text' || $type === 'html' ) : ?>
                                <textarea
                                    id="<?php echo esc_attr( $field_key ); ?>"
                                    name="<?php echo esc_attr( $field_key ); ?>"
                                    class="regular-text svgml-manual-field"
                                    data-manual-key="<?php echo esc_attr( $field_key ); ?>"
                                    rows="3"
                                    placeholder="<?php echo $label; ?>"></textarea>
                            <?php else : ?>
                                <input type="text"
                                    id="<?php echo esc_attr( $field_key ); ?>"
                                    name="<?php echo esc_attr( $field_key ); ?>"
                                    class="regular-text svgml-manual-field"
                                    data-manual-key="<?php echo esc_attr( $field_key ); ?>"
                                    placeholder="<?php echo $label; ?>">
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php submit_button( 'Gegevens opslaan' ); ?>
                </form>
            </div>
        </div>
    </div>

    <style>
        .svgml-manual-layout {
            display: flex;
            gap: 20px;
            margin-top: 20px;
        }

        .svgml-manual-sidebar {
            flex: 0 0 280px;
            padding: 15px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
            max-height: 600px;
            overflow-y: auto;
        }

        .svgml-polygon-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .svgml-polygon-item {
            display: flex;
            align-items: flex-start;
            gap: 6px;
            padding: 10px 12px;
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 3px;
            cursor: default;
            transition: all 0.2s ease;
        }

        .svgml-polygon-item-content {
            flex: 1;
            min-width: 0;
        }

        .svgml-polygon-item .svgml-drag-handle {
            padding-top: 2px;
            flex-shrink: 0;
            cursor: grab;
        }

        .svgml-polygon-item:hover {
            border-color: var(--svgml-red, #2a9d8f);
            background: #f5fffe;
        }

        .svgml-polygon-item.active {
            background: var(--svgml-red, #2a9d8f);
            color: white;
            border-color: var(--svgml-red, #2a9d8f);
        }

        .svgml-polygon-item.has-data::after {
            content: '';
            display: inline-block;
            width: 8px;
            height: 8px;
            background: var(--svgml-success, #28a745);
            border-radius: 50%;
            margin-left: 8px;
        }

        .svgml-polygon-item-name {
            font-weight: 600;
            font-size: 13px;
        }

        .svgml-polygon-item-layer {
            font-size: 11px;
            opacity: 0.7;
        }

        .svgml-polygon-item.active .svgml-polygon-item-layer {
            opacity: 0.9;
        }

        .svgml-data-indicator {
            font-weight: bold;
            margin-left: 4px;
            font-size: 12px;
        }

        .svgml-manual-content {
            flex: 1;
            padding: 20px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .svgml-manual-content h4 {
            margin-top: 0;
            color: var(--svgml-red, #2a9d8f);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
        }

        .form-group input[type="text"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 14px;
        }

        /* Thumbnail-veld: tekstveld + knop op één regel, voorbeeld eronder. */
        .svgml-thumbnail-field-wrap {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
        }

        .svgml-thumbnail-field-wrap .svgml-thumbnail-input {
            flex: 1;
            min-width: 200px;
        }

        .svgml-thumbnail-preview {
            flex-basis: 100%;
        }

        .svgml-thumbnail-preview img {
            max-width: 150px;
            max-height: 150px;
            border: 1px solid #ddd;
            border-radius: 3px;
            margin-top: 6px;
        }

        .form-group input[type="text"]:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--svgml-red, #2a9d8f);
            box-shadow: 0 0 0 3px rgba(42, 157, 143, 0.1);
        }
    </style>

    <script>
    jQuery(document).ready(function($) {
        // Manual data from the database — kept in memory and updated as the user edits
        var manualData = <?php echo wp_json_encode( empty( $manual_data ) ? new stdClass() : $manual_data ); ?>;
        if (Array.isArray(manualData)) { manualData = {}; }

        // Make region items draggable to reorder the overview on the frontend.
        $('.svgml-polygon-list').sortable({
            handle:      '.svgml-drag-handle',
            axis:        'y',
            placeholder: 'svgml-sort-placeholder',
            opacity:     0.85,
            tolerance:   'pointer',
            cancel:      'form, button, input, textarea, a',
        }).disableSelection();

        /**
         * Save the currently visible form fields into manualData before switching region.
         * Must be called BEFORE changing #polygon_id or clearing the fields.
         */
        function saveCurrentToManualData() {
            var currentId = $('#polygon_id').val();
            if (!currentId) return;
            var data = {};
            $('.svgml-manual-field').each(function() {
                data[$(this).attr('data-manual-key')] = $(this).val();
            });
            manualData[currentId] = data;
        }

        /**
         * Load polygon data from manualData and populate the form.
         * Does NOT call saveCurrentToManualData() — callers are responsible for that.
         */
        function loadPolygonData(polygonId) {
            $('#polygon_id').val(polygonId);
            $('.svgml-manual-field').each(function() {
                var key = $(this).attr('data-manual-key');
                var value = (manualData[polygonId] && typeof manualData[polygonId][key] !== 'undefined')
                    ? manualData[polygonId][key]
                    : '';
                $(this).val(value);
            });

            // Na het vullen van alle velden ook elke thumbnail-preview verversen,
            // anders blijft bij het wisselen van vlak de voorbeeldweergave van het
            // vórige vlak zichtbaar (of leeg terwijl het nieuwe vlak wél een URL heeft).
            $('.svgml-thumbnail-field-wrap').each(function() {
                updateThumbnailPreview($(this));
            });
        }

        /**
         * Toon of verberg de voorbeeldafbeelding van één thumbnail-veld.
         * $wrap is de .svgml-thumbnail-field-wrap div die zowel het tekstveld
         * als de <img> bevat. We tonen de <img> alleen als de waarde met
         * http:// of https:// begint — dezelfde check als panel-renderer.js
         * gebruikt op de frontend, zodat de admin-preview en de echte weergave
         * hetzelfde gedrag hebben (een onvolledige/lege waarde toont niets).
         */
        function updateThumbnailPreview($wrap) {
            var url = $wrap.find('.svgml-thumbnail-input').val();
            var $img = $wrap.find('.svgml-thumbnail-preview img');
            if (url && /^https?:\/\//i.test(url)) {
                $img.attr('src', url).show();
            } else {
                $img.hide().attr('src', '');
            }
        }

        // Klik op "Kies afbeelding": open de WordPress media-library en zet de
        // gekozen afbeelding-URL in het tekstveld ernaast.
        // Delegated op '.svgml-manual-content' (in plaats van rechtstreeks op de
        // knop) omdat er meerdere thumbnail-velden op de pagina kunnen staan —
        // zo werkt dezelfde ene handler voor elk van hen, en vinden we via
        // .closest() steeds het juiste veld/preview-paar bij de aangeklikte knop.
        $('.svgml-manual-content').on('click', '.svgml-thumbnail-pick-btn', function(e) {
            e.preventDefault(); // dit is geen submit-knop van het formulier
            var $wrap  = $(this).closest('.svgml-thumbnail-field-wrap');
            var $input = $wrap.find('.svgml-thumbnail-input');

            // Bewust GEEN hergebruikt/module-level media-frame (zoals elders in de
            // plugin): met meerdere thumbnail-velden op één pagina moet elke klik
            // zijn eigen frame openen, anders zou een herbruikt frame de vorige
            // (verkeerde) $input onthouden.
            var frame = wp.media({
                title:    'Kies afbeelding',
                button:   { text: 'Gebruik afbeelding' },
                multiple: false,
                library:  { type: 'image' }
            });

            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();

                // .val() zet de URL in HETZELFDE tekstveld dat saveCurrentToManualData()
                // straks met .val() uitleest — geen los data-attribuut of los element,
                // want dat zou bij opslaan een lege waarde opleveren (zie PHP-comment).
                // .trigger('input').trigger('change') zorgt dat de preview-listener
                // hieronder (en eventuele andere logica die op deze events luistert)
                // meteen meekrijgt dat de waarde is veranderd.
                $input.val(attachment.url).trigger('input').trigger('change');
            });

            frame.open();
        });

        // Ook bij handmatig typen/plakken van een URL (backward compatible pad)
        // de preview verversen — niet alleen na kiezen via de media-library.
        $('.svgml-manual-content').on('input', '.svgml-thumbnail-input', function() {
            updateThumbnailPreview($(this).closest('.svgml-thumbnail-field-wrap'));
        });

        // Handle polygon selection: save current edits, then load the new region.
        $('.svgml-polygon-item').on('click', function() {
            saveCurrentToManualData();
            $('.svgml-polygon-item').removeClass('active');
            $(this).addClass('active');
            var polygonId = $(this).attr('data-polygon-id');
            loadPolygonData(polygonId);
            var _mapId = (typeof svgmlAdmin !== 'undefined') ? svgmlAdmin.mapId : 0;
            if (_mapId) {
                sessionStorage.setItem('svgml_active_region_tab_' + _mapId, polygonId);
            }
        });

        // Serialize entire manualData object into hidden field before POST,
        // rebuilding in current DOM order so the new region sequence is saved to the backend.
        $('#svgml-manual-form').on('submit', function() {
            saveCurrentToManualData();
            var ordered = {};
            $('.svgml-polygon-item').each(function() {
                var id = $(this).data('polygon-id');
                if (manualData.hasOwnProperty(id)) {
                    ordered[id] = manualData[id];
                }
            });
            $('#svgml_manual_data_all').val(JSON.stringify(ordered));
        });

        // Initialise: restore previously active region tab or default to first.
        // Use loadPolygonData() directly — NOT .click() — to avoid saveCurrentToManualData()
        // clobbering manualData with empty strings before any fields are populated.
        var _mapId = (typeof svgmlAdmin !== 'undefined') ? svgmlAdmin.mapId : 0;
        var _savedRegion = _mapId ? sessionStorage.getItem('svgml_active_region_tab_' + _mapId) : null;
        var $initial = _savedRegion
            ? $('.svgml-polygon-item[data-polygon-id="' + CSS.escape(_savedRegion) + '"]')
            : $('.svgml-polygon-item').first();
        if (!$initial.length) {
            $initial = $('.svgml-polygon-item').first();
        }
        $initial.addClass('active');
        loadPolygonData($initial.attr('data-polygon-id'));
    });
    </script>
    <?php
}
