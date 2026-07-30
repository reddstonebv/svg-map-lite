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
                    $existing  = get_post_meta( $map_id, '_svgml_manual_data', true ) ?: [];
                    $reordered = [];
                    // Insert in submitted order so drag-to-reorder is preserved.
                    foreach ( $decoded as $poly_id => $fields ) {
                        $clean_id = sanitize_text_field( (string) $poly_id );
                        if ( ! $clean_id || ! is_array( $fields ) ) continue;
                        $clean_fields = [];
                        foreach ( $panel_config as $i => $block ) {
                            $field_key = 'manual_field_' . $i;
                            $clean_fields[ $field_key ] = isset( $fields[ $field_key ] )
                                ? sanitize_text_field( $fields[ $field_key ] )
                                : '';
                        }
                        $reordered[ $clean_id ] = $clean_fields;
                    }
                    // Append regions not in the submitted payload (e.g. excluded ones).
                    foreach ( $existing as $pid => $data ) {
                        if ( ! isset( $reordered[ $pid ] ) ) {
                            $reordered[ $pid ] = $data;
                        }
                    }
                    update_post_meta( $map_id, '_svgml_manual_data', $reordered );
                    delete_transient( 'svgml_html_' . $map_id );
                    echo '<div class="notice notice-success is-dismissible"><p>Regio gegevens opgeslagen!</p></div>';
                }
            }
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
                    
                    <input type="hidden" name="polygon_id" id="polygon_id" value="<?php echo isset( $all_polygons[0] ) ? esc_attr( $all_polygons[0]['id'] ) : ''; ?>">
                    <input type="hidden" name="svgml_manual_data_all" id="svgml_manual_data_all" value="">
                    
                    <?php if ( empty( $panel_config ) ) : ?>
                        <div class="form-group" style="padding:24px 12px;color:#b00;background:#fff3f3;border:1px solid #f5cccc;border-radius:6px;text-align:center;font-weight:600;">
                            Bouw eerst je paneel in de <em>Panel Builder</em> tab om hier velden in te vullen.
                        </div>
                    <?php else : ?>
                        <?php foreach ( $panel_config as $i => $block ) :
                            $type = isset( $block['type'] ) ? $block['type'] : '';
                            $label = isset( $block['label'] ) && !empty( $block['label'] ) ? esc_html( $block['label'] ) : 'Veld ' . ($i+1);
                            $field_key = 'manual_field_' . $i;
                        ?>
                        <div class="form-group">
                            <label for="<?php echo esc_attr( $field_key ); ?>">
                                <strong><?php echo $label; ?></strong>
                            </label>
                            <?php if ( $type === 'text' || $type === 'html' ) : ?>
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
        }

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
