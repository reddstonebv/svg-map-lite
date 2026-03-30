<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Mapping page for SVG Map Lite
 * Extracted from svg-map-lite.php with multi-map support
 */

function svgml_render_mapping_page( $map_id ) {

    // Formulier verwerken
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
                if ( $pid ) $id_to_layer[ $pid ] = $layer['name'] ?? ('Laag ' . ($li + 1));
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
