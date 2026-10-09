<?php
/**
 * SVG Map Lite - Frontend Rendering
 * Shortcodes, custom CSS, and frontend utilities
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register shortcodes
 */
add_shortcode( 'svg_map',         'svgml_render_shortcode' );
add_shortcode( 'svg_map_panel',   'svgml_render_panel_shortcode' );
add_shortcode( 'svg_map_filters', 'svgml_render_filters_shortcode' );
add_shortcode( 'svg_map_lite',    'svgml_render_shortcode' );  // backward compat

/**
 * Enqueue all registered frontend assets on demand (called from shortcode callbacks).
 */
function svgml_enqueue_frontend_assets() {
    wp_enqueue_style(  'svgml-frontend-css' );
    wp_enqueue_script( 'svgml-utils-js' );
    wp_enqueue_script( 'svgml-frontend-js' );
    wp_enqueue_script( 'svgml-panel-renderer-js' );
    wp_enqueue_script( 'svgml-filters-js' );
}

/**
 * Register custom CSS output hook
 */
add_action( 'wp_head', 'svgml_output_custom_css' );

/**
 * SHORTCODE: [svg_map id="123"]
 * Renders the interactive map. The id attribute refers to a svgml_map post ID.
 * If no id is provided, attempts to find the first published svgml_map post.
 */
function svgml_render_shortcode( $atts ) {

    // Parse shortcode attributes and extract map_id
    $atts = shortcode_atts(
        [ 'id' => '' ],
        $atts,
        'svg_map'
    );

    $map_id = absint( $atts['id'] );

    // If WPML is active, swap the map ID for the current-language translation.
    $map_id = (int) apply_filters( 'wpml_object_id', $map_id, 'svgml_map', true );

    // Fallback: find first published svgml_map post if no ID provided
    if ( ! $map_id ) {
        $first_map = get_posts( [
            'post_type'      => 'svgml_map',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
        ] );
        if ( $first_map ) {
            $map_id = $first_map[0]->ID;
        } else {
            $admin_link = current_user_can( 'manage_options' )
                ? ' <a href="' . admin_url( 'admin.php?page=svgml-settings' ) . '">[Configureer SVG Map Lite]</a>'
                : '';
            return '<p class="svgml-error">Geen SVG kaart geconfigureerd.' . $admin_link . '</p>';
        }
    }

    // Enqueue frontend assets on demand (registered in svg-map-lite.php)
    svgml_enqueue_frontend_assets();

    // Helper function to get post meta with fallback
    $get_meta = function( $key, $default = '' ) use ( $map_id ) {
        $result = get_post_meta( $map_id, '_svgml_' . $key, true );
        if ( '' === $result || false === $result ) {
            return $default;
        }
        return $result;
    };

    // Get all options using post meta
    $source_type         = $get_meta( 'source_type', 'svg' );
    $svg_attachment_id   = $get_meta( 'svg_attachment_id', '' );
    $image_attachment_id = $get_meta( 'image_attachment_id', '' );
    $polygons            = $get_meta( 'polygons', [] );
    $json_url            = $get_meta( 'json_url', '' );
    $id_mapping          = $get_meta( 'id_mapping', [] );
    $display_fields      = $get_meta( 'display_fields', [] );
    $panel_title         = $get_meta( 'panel_title', '' );
    $json_id_field       = $get_meta( 'json_id_field', 'id' );
    $panel_blocks        = $get_meta( 'panel_blocks', [] );
    $filter_fields       = $get_meta( 'filter_fields', [] );
    $status_field        = $get_meta( 'status_field', '' );
    $status_colors       = $get_meta( 'status_colors', [] );
    $overview_enabled    = (bool) $get_meta( 'overview_enabled', false );
    $overview_blocks     = $get_meta( 'overview_blocks', [] );
    $overview_clickable  = get_post_meta( $map_id, '_svgml_overview_clickable', true ) !== '1';
    $json_array_key      = $get_meta( 'json_array_key', '' );
    $layers              = $get_meta( 'layers', [] );
    $layer_switcher      = $get_meta( 'layer_switcher', 'buttons' );

    // Ensure arrays are actually arrays
    if ( ! is_array( $polygons ) ) $polygons = [];
    if ( ! is_array( $id_mapping ) ) $id_mapping = [];
    if ( ! is_array( $display_fields ) ) $display_fields = [];
    if ( ! is_array( $panel_blocks ) ) $panel_blocks = [];
    if ( ! is_array( $filter_fields ) ) $filter_fields = [];
    if ( ! is_array( $status_colors ) ) $status_colors = [];
    if ( ! is_array( $overview_blocks ) ) $overview_blocks = [];
    if ( ! is_array( $layers ) ) $layers = [];

    // ── VALIDATIE OP BASIS VAN BRONTYPE ──────────────────────────────────────
    $is_image_mode = ( 'image' === $source_type );
    $svg_content   = '';
    $image_url     = '';

    if ( $is_image_mode ) {
        // Polygon-modus: afbeelding + getekende vlakken
        if ( ! $image_attachment_id ) {
            $admin_link = current_user_can( 'manage_options' )
                ? ' <a href="' . admin_url( 'admin.php?page=svgml-settings' ) . '">[Configureer SVG Map Lite]</a>'
                : '';
            return '<p class="svgml-error">Achtergrondafbeelding niet geconfigureerd.' . $admin_link . '</p>';
        }
        $image_url = wp_get_attachment_url( $image_attachment_id );
        if ( ! $image_url ) {
            return '<p class="svgml-error">Achtergrondafbeelding kon niet worden geladen.</p>';
        }
    } else {
        // SVG-modus
        if ( ! $svg_attachment_id ) {
            $admin_link = current_user_can( 'manage_options' )
                ? ' <a href="' . admin_url( 'admin.php?page=svgml-settings' ) . '">[Configureer SVG Map Lite]</a>'
                : '';
            return '<p class="svgml-error">SVG kaart niet geconfigureerd.' . $admin_link . '</p>';
        }

        $svg_path    = get_attached_file( $svg_attachment_id );
        $svg_content = ( $svg_path && file_exists( $svg_path ) ) ? file_get_contents( $svg_path ) : '';

        if ( empty( $svg_content ) ) {
            return '<p class="svgml-error">SVG-bestand kon niet worden geladen.</p>';
        }

        // Remove XML declarations
        $svg_content = preg_replace( '/<\?xml[^?]*\?>\s*/i', '', $svg_content );
        $svg_content = preg_replace( '/<!DOCTYPE[^>]*>\s*/i', '', $svg_content );

        // Add CSS class to root SVG element
        $svg_content = preg_replace(
            '/<svg\b/i',
            '<svg class="svgml-svg"',
            $svg_content,
            1
        );

        // Obliterate embedded <style> blocks (Illustrator/Inkscape export artefacts)
        // before any attribute stripping, so selector rules can't survive.
        $svg_content = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', '', $svg_content );

        // Strip hardcoded presentation attributes so PHP-generated CSS wins.
        $svg_content = preg_replace( '/\bstroke="[^"]*"/i',       '', $svg_content );
        $svg_content = preg_replace( '/\bstroke-width="[^"]*"/i', '', $svg_content );
        $svg_content = preg_replace( '/\bstroke-color="[^"]*"/i', '', $svg_content );
        $svg_content = preg_replace( '/\bfill="[^"]*"/i',         '', $svg_content );
        // Strip inline style blocks on shape elements only (not on <svg> or <g>)
        $svg_content = preg_replace(
            '/(<(?:polygon|path|circle|ellipse|rect|line|polyline)\b[^>]*?)\s+style="[^"]*"/i',
            '$1',
            $svg_content
        );
    }

    // ── JSON DATA OPHALEN ────────────────────────────────────────────────────
    $map_mode     = get_post_meta( $map_id, '_svgml_map_mode', true ) ?: 'json';
    $json_data    = svgml_get_json_data( $map_id );
    $excluded_ids = $get_meta( 'excluded_ids', [] );
    if ( ! is_array( $excluded_ids ) ) $excluded_ids = [];

    // In manual mode, pass the manually-entered region data so the frontend JS
    // can look up a clicked region's data without a JSON feed.
    $manual_data = [];
    if ( 'manual' === $map_mode ) {
        $manual_data = get_post_meta( $map_id, '_svgml_manual_data', true ) ?: [];
        if ( ! is_array( $manual_data ) ) $manual_data = [];
    }

    // ── DATA DOORGEVEN AAN JAVASCRIPT ───────────────────────────────────────
    $js_data = json_encode( [
        'mapId'           => $map_id,  // IMPORTANT: Include map ID for multi-map support
        'mapMode'         => $map_mode,
        'sourceType'      => $source_type,
        'imageUrl'        => $image_url,
        'polygons'        => $polygons,
        'mapping'         => $id_mapping,
        'jsonData'        => $json_data,
        'jsonIdField'     => $json_id_field,
        'displayFields'   => $display_fields,
        'panelBlocks'     => $panel_blocks,
        'panelTitle'      => $panel_title,
        'excludedIds'     => $excluded_ids,
        'statusField'     => $status_field,
        'statusColors'    => $status_colors,
        'filterFields'    => $filter_fields,
        'overviewEnabled' => $overview_enabled,
        'overviewBlocks'  => $overview_blocks,
        'overviewSortField'  => get_post_meta( $map_id, '_svgml_overview_sort_field', true ) ?: '',
        'overviewSortOrder'  => get_post_meta( $map_id, '_svgml_overview_sort_order', true ) ?: 'asc',
        'overviewClickable'  => $overview_clickable,
        'jsonArrayKey'    => $json_array_key,
        'manualData'      => $manual_data,
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT );

    wp_add_inline_script( 'svgml-frontend-js', 'var svgmlData = ' . $js_data . ';', 'before' );

    // Guaranteed fallback: output data inline so it works regardless of whether
    // wp_add_inline_script fires (some themes/contexts never flush the script queue).
    // This runs outside the transient cache so the data is always fresh.
    echo '<script id="svgml-data-' . esc_attr( $map_id ) . '">var svgmlData = ' . $js_data . ';</script>';

    // ── HTML TRANSIENT CACHE ───────────────────────────────────────────────────
    // De kaart-CSS (svgml_get_map_style_tag) wordt hier en onderaan BUITEN de
    // cache vóór de HTML geplakt. Zat hij in de transient, dan bleef na het
    // opslaan van nieuwe kleuren/Custom CSS tot 12 uur lang de oude CSS staan.
    $transient_key = 'svgml_html_' . $map_id;
    $cached_html   = get_transient( $transient_key );
    if ( false !== $cached_html ) {
        return svgml_get_map_style_tag( $map_id ) . $cached_html;
    }

    // ── BUILD HTML WITH OUTPUT BUFFER ──────────────────────────────────────────
    ob_start();
    ?>
    <div id="svgml-wrap-<?php echo esc_attr( $map_id ); ?>" class="svgml-wrap">

        <!-- ── MAP + PANEL CONTAINER ──────────────────────────────────────── -->
        <div class="svgml-container">

            <!-- The map: SVG or image + polygons -->
            <div class="svgml-map-wrap">
                <?php if ( $is_image_mode ) : ?>
                    <?php
                    // ── MULTI-LAYER SUPPORT ──────────────────────────────────────
                    if ( empty( $layers ) && ! empty( $image_attachment_id ) ) {
                        $layers = [[
                            'name' => 'Overzicht',
                            'image_attachment_id' => $image_attachment_id,
                            'polygons' => $polygons,
                            'stroke_color' => $get_meta( 'poly_stroke_color', '#2a9d8f' ),
                            'stroke_width' => $get_meta( 'poly_stroke_width', '1' ),
                        ]];
                    }

                    $has_multiple_layers = count( $layers ) > 1;
                    ?>

                    <?php if ( $has_multiple_layers ) : ?>
                        <?php if ( $layer_switcher === 'buttons' ) : ?>
                            <div class="svgml-layer-switcher svgml-layer-buttons">
                                <?php foreach ( $layers as $li => $layer ) : ?>
                                    <button type="button"
                                            class="svgml-layer-btn <?php echo $li === 0 ? 'svgml-layer-btn-active' : ''; ?>"
                                            data-layer="<?php echo $li; ?>">
                                        <?php echo esc_html( $layer['name'] ); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ( $layer_switcher === 'dropdown' ) : ?>
                            <div class="svgml-layer-switcher svgml-layer-dropdown-wrap">
                                <select class="svgml-layer-select" id="svgml-layer-select">
                                    <?php foreach ( $layers as $li => $layer ) : ?>
                                        <option value="<?php echo $li; ?>"><?php echo esc_html( $layer['name'] ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else : ?>
                            <div class="svgml-layer-switcher svgml-layer-custom">
                                <?php foreach ( $layers as $li => $layer ) : ?>
                                    <span class="svgml-layer-option <?php echo $li === 0 ? 'svgml-layer-option-active' : ''; ?>" data-layer="<?php echo $li; ?>">
                                        <?php echo esc_html( $layer['name'] ); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- ── LAYERS ──────────────────────────────────────────────────── -->
                    <?php foreach ( $layers as $li => $layer ) :
                        $layer_img_url = wp_get_attachment_url( $layer['image_attachment_id'] );
                        $layer_polys   = $layer['polygons'] ?? [];
                        $layer_stroke  = $layer['stroke_color'] ?? '#2a9d8f';
                        $layer_width   = $layer['stroke_width'] ?? '1';
                        if ( ! $layer_img_url ) continue;
                    ?>
                        <div class="svgml-image-map svgml-layer"
                             data-layer="<?php echo $li; ?>"
                             style="position:relative; display:inline-block; width:100%; <?php echo $li > 0 ? 'display:none;' : ''; ?>">
                            <img src="<?php echo esc_url( $layer_img_url ); ?>"
                                 alt="<?php echo esc_attr( $layer['name'] ); ?>"
                                 class="svgml-bg-image"
                                 style="display:block; width:100%; height:auto;">

                            <svg class="svgml-svg svgml-polygon-overlay"
                                 viewBox="0 0 1 1"
                                 preserveAspectRatio="none"
                                 style="position:absolute; top:0; left:0; width:100%; height:100%;">
                                <?php foreach ( $layer_polys as $poly ) :
                                    $poly_id = esc_attr( $poly['id'] ?? '' );
                                    $points  = $poly['points'] ?? [];
                                    if ( empty( $poly_id ) || count( $points ) < 3 ) continue;
                                    $pts_str = implode( ' ', array_map( function( $pt ) {
                                        return round( $pt['x'], 6 ) . ',' . round( $pt['y'], 6 );
                                    }, $points ) );
                                ?>
                                    <polygon id="<?php echo $poly_id; ?>"
                                             points="<?php echo esc_attr( $pts_str ); ?>"
                                             class="svgml-poly-region" />
                                <?php endforeach; ?>
                            </svg>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <?php echo $svg_content; ?>
                <?php endif; ?>
            </div>

        </div><!-- .svgml-container -->

    </div><!-- .svgml-wrap -->
    <?php
    $html = ob_get_clean();
    set_transient( $transient_key, $html, 12 * HOUR_IN_SECONDS );

    // Eerst opslaan in de cache (zonder CSS), dan pas de CSS ervoor plakken.
    return svgml_get_map_style_tag( $map_id ) . $html;
}

/**
 * SHORTCODE: [svg_map_panel id="123"]
 * Renders a standalone panel that can be placed elsewhere on the page.
 */
function svgml_render_panel_shortcode( $atts ) {

    $atts = shortcode_atts(
        [ 'id' => '' ],
        $atts,
        'svg_map_panel'
    );

    $map_id = absint( $atts['id'] );

    // If WPML is active, swap the map ID for the current-language translation.
    $map_id = (int) apply_filters( 'wpml_object_id', $map_id, 'svgml_map', true );

    // Fallback
    if ( ! $map_id ) {
        $first_map = get_posts( [
            'post_type'      => 'svgml_map',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
        ] );
        if ( $first_map ) {
            $map_id = $first_map[0]->ID;
        }
    }

    // Get panel title from post meta
    $panel_title = $map_id
        ? get_post_meta( $map_id, '_svgml_panel_title', true )
        : '';

    // Koppel het paneel aan zijn kaart, zodat paneel-CSS per kaart te scopen is
    // (.svgml-panel-map-{id}) en JS het kaart-ID kan uitlezen (data-map-id).
    // Het paneel staat buiten #svgml-wrap-{id}, dus zonder deze class kan CSS
    // het paneel van kaart A niet onderscheiden van dat van kaart B.
    // De bestaande id's en classes blijven ongewijzigd: klanten gebruiken ze in
    // hun CSS en panel-renderer.js zoekt op $('.svgml-panel').
    // $map_id is hier al de WPML-vertaling (of de fallback-kaart). Is er geen
    // kaart gevonden (0), dan voegen we niets toe.
    $map_class = $map_id ? ' svgml-panel-map-' . $map_id : '';
    $map_attr  = $map_id ? ' data-map-id="' . esc_attr( $map_id ) . '"' : '';

    ob_start();
    ?>
    <div class="svgml-panel svgml-panel-standalone<?php echo esc_attr( $map_class ); ?>" id="svgml-panel-standalone"<?php echo $map_attr; ?> aria-hidden="true">
        <div class="svgml-panel-inner">

            <button class="svgml-panel-close svgml-panel-close-standalone"
                    aria-label="Sluit info panel">
                <span aria-hidden="true">×</span>
            </button>

            <?php if ( $panel_title ) : ?>
                <h3 class="svgml-panel-title">
                    <?php echo esc_html( $panel_title ); ?>
                </h3>
            <?php endif; ?>

            <div class="svgml-panel-content" id="svgml-panel-content-standalone">
                <p class="svgml-panel-placeholder">Klik op een regio op de kaart voor meer informatie.</p>
            </div>
        </div>
    </div>
    <?php
    // Kaart-CSS vóór het paneel (één keer per pagina per kaart, zie
    // svgml_get_map_style_tag). Staat het paneel boven de kaart, dan komt de
    // CSS dus hier mee en niet pas bij de kaart.
    return svgml_get_map_style_tag( $map_id ) . ob_get_clean();
}

/**
 * SHORTCODE: [svg_map_filters id="123"]
 * Renders the filter bar for a given map.
 */
function svgml_render_filters_shortcode( $atts ) {

    $atts = shortcode_atts(
        [ 'id' => '' ],
        $atts,
        'svg_map_filters'
    );

    $map_id = absint( $atts['id'] );

    // If WPML is active, swap the map ID for the current-language translation.
    $map_id = (int) apply_filters( 'wpml_object_id', $map_id, 'svgml_map', true );

    if ( ! $map_id ) {
        $first_map = get_posts( [
            'post_type'      => 'svgml_map',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
        ] );
        if ( $first_map ) {
            $map_id = $first_map[0]->ID;
        }
    }

    if ( ! $map_id ) {
        return '';
    }

    $filter_fields = get_post_meta( $map_id, '_svgml_filter_fields', true );
    if ( ! is_array( $filter_fields ) || empty( $filter_fields ) ) {
        return '';
    }

    ob_start();
    ?>
    <div id="svgml-wrap-<?php echo esc_attr( $map_id ); ?>" class="svgml-wrap">
    <div class="svgml-filters-bar" id="svgml-filters-bar">
        <div class="svgml-filters-inner">
            <?php foreach ( $filter_fields as $filter ) :
                $f_field = sanitize_key( $filter['field'] ?? '' );
                $f_type  = sanitize_text_field( $filter['type'] ?? 'dropdown' );
                $f_label = sanitize_text_field( $filter['label'] ?? $f_field );
                $f_imode = sanitize_text_field( $filter['input_mode'] ?? 'single' );

                if ( empty( $f_field ) ) continue;
            ?>
            <div class="svgml-filter-item svgml-filter-type-<?php echo esc_attr( $f_type ); ?>"
                 data-field="<?php echo esc_attr( $f_field ); ?>"
                 data-type="<?php echo esc_attr( $f_type ); ?>">
                <label class="svgml-filter-label"><?php echo esc_html( $f_label ); ?></label>

                <?php if ( 'range' === $f_type ) : ?>
                    <div class="svgml-range-slider" id="svgml-range-<?php echo esc_attr( $f_field ); ?>"></div>
                    <div class="svgml-range-values">
                        <span class="svgml-range-min"></span>
                        <span class="svgml-range-max"></span>
                    </div>

                <?php elseif ( 'input' === $f_type ) : ?>
                    <?php if ( 'minmax' === $f_imode ) : ?>
                        <div class="svgml-input-minmax"
                             id="svgml-input-<?php echo esc_attr( $f_field ); ?>"
                             data-field="<?php echo esc_attr( $f_field ); ?>"
                             data-mode="minmax"
                             style="display:flex; gap:6px;">
                            <input type="text" class="svgml-filter-input-min" placeholder="Min" autocomplete="off" style="width:50%;">
                            <input type="text" class="svgml-filter-input-max" placeholder="Max" autocomplete="off" style="width:50%;">
                        </div>
                    <?php else : ?>
                        <input type="text"
                               class="svgml-filter-input-single"
                               id="svgml-input-<?php echo esc_attr( $f_field ); ?>"
                               data-field="<?php echo esc_attr( $f_field ); ?>"
                               data-mode="single"
                               placeholder="Zoek..."
                               autocomplete="off">
                    <?php endif; ?>

                <?php elseif ( 'search' === $f_type ) : ?>
                    <div class="svgml-search-wrap">
                        <input type="text"
                               class="svgml-filter-search"
                               id="svgml-search-<?php echo esc_attr( $f_field ); ?>"
                               placeholder="Search..."
                               autocomplete="off">
                        <ul class="svgml-autocomplete-list" id="svgml-autocomplete-<?php echo esc_attr( $f_field ); ?>"></ul>
                    </div>

                <?php elseif ( 'buttons' === $f_type ) : ?>
                    <div class="svgml-filter-buttons"
                         id="svgml-buttons-<?php echo esc_attr( $f_field ); ?>"
                         data-source="<?php echo esc_attr( $filter['button_source'] ?? 'auto' ); ?>"
                         data-show-count="<?php echo esc_attr( $filter['button_show_count'] ?? '0' ); ?>"
                         data-custom-values="<?php echo esc_attr( $filter['button_custom_values'] ?? '' ); ?>">
                    </div>

                <?php else :
                    $f_placeholder = $filter['placeholder'] ?? 'Alles';
                ?>
                    <select class="svgml-filter-select" id="svgml-select-<?php echo esc_attr( $f_field ); ?>">
                        <option value=""><?php echo esc_html( $f_placeholder ); ?></option>
                    </select>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <div class="svgml-filter-item svgml-filter-reset-wrap">
                <button type="button" class="svgml-filter-reset" id="svgml-filter-reset">
                    Reset
                </button>
            </div>
        </div>
    </div>
    </div>
    <?php
    // Kaart-CSS vóór de filterbalk (één keer per pagina per kaart).
    return svgml_get_map_style_tag( $map_id ) . ob_get_clean();
}

/**
 * Clear JSON cache for a specific map
 */
function svgml_clear_json_cache( $map_id = 0 ) {
    if ( $map_id ) {
        delete_transient( 'svgml_json_cache_' . $map_id );
        delete_transient( 'svgml_html_'       . $map_id );
    }
}

/**
 * OUTPUT CUSTOM CSS VAN ALLE KAARTEN (oud gedrag, html-export)
 *
 * Print één <style id="svgml-custom-css"> met de CSS van ALLE gepubliceerde
 * kaarten. Sinds 2.6.0 laadt een normale pagina deze functie NIET meer via
 * wp_head: elke shortcode levert zelf de CSS van zijn eigen kaart (zie
 * svgml_get_map_style_tag). Het oude gedrag komt terug met de filter:
 *   add_filter( 'svgml_load_all_map_css', '__return_true' );
 *
 * LET OP: de extensie svg-map-lite-html-export roept deze functie ZONDER
 * argumenten aan (buiten wp_head, via ob_start) en verwacht precies deze
 * opbouw: één <style id="svgml-custom-css">, de kop-comment, het :root-blok
 * als eerste blok, daarna de CSS van alle kaarten. Niet aanpassen.
 */
function svgml_output_custom_css() {

    // Roept WordPress ons aan via de wp_head-hook (de add_action bovenaan dit
    // bestand) en staat de filter uit? Dan doen we niets: de shortcodes leveren
    // de CSS nu zelf. doing_action() is alleen true TIJDENS wp_head, dus een
    // directe aanroep (zoals de html-export) draait gewoon volledig door.
    // De add_action blijft bewust staan, zodat een bestaande
    // remove_action( 'wp_head', 'svgml_output_custom_css' ) van een thema of
    // klant blijft werken.
    if ( doing_action( 'wp_head' ) && ! apply_filters( 'svgml_load_all_map_css', false ) ) {
        return;
    }

    $css_output = '';

    // Get all published maps
    $maps = get_posts( [
        'post_type'      => 'svgml_map',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
    ] );

    // CSS per kaart achter elkaar plakken (opbouw: zie svgml_get_map_css).
    foreach ( $maps as $map ) {
        $css_output .= svgml_get_map_css( $map->ID );
    }

    // Build a :root block with CSS variables derived from the first (or only) published map.
    // When multiple maps exist on the same page, the last map's values win for :root —
    // per-map overrides are handled by the .svgml-wrap-prefixed rules above.
    // (Bewust "=" en niet ".=": ongewijzigd oud gedrag, de html-export rekent hierop.)
    $root_css = '';
    foreach ( $maps as $map ) {
        $root_css = svgml_get_map_root_css( $map->ID );
    }

    if ( empty( $css_output ) && empty( $root_css ) ) return;

    echo "\n<style id=\"svgml-custom-css\">\n";
    echo "/* SVG Map Lite – Gegenereerd + Custom CSS */\n";
    if ( $root_css ) echo $root_css;
    echo $css_output;
    echo "</style>\n";
}

/**
 * <STYLE>-TAG MET DE CSS VAN ÉÉN KAART (voor in de shortcode-output)
 *
 * Elke shortcode ([svg_map], [svg_map_panel], [svg_map_filters]) zet het
 * resultaat hiervan vóór zijn eigen HTML. Zo laadt een pagina alleen de CSS
 * van de kaarten die er echt op staan, in plaats van die van alle kaarten.
 *
 * Geeft een lege string terug als:
 * - de CSS van deze kaart in dit request al is uitgevoerd (bijv. [svg_map] én
 *   [svg_map_panel] van dezelfde kaart op één pagina → maar één keer CSS);
 * - de filter svgml_load_all_map_css aan staat (dan staat alles al in de
 *   <head> via svgml_output_custom_css, en zou het hier dubbel komen);
 * - we niet op een gewone frontend-pagina ná de <head> zitten (zie hieronder).
 *
 * De tag mag NIET in de gecachte shortcode-HTML (transient svgml_html_{id})
 * terechtkomen, anders klopt hij niet meer na het opslaan van nieuwe CSS.
 * De shortcodes plakken hem daarom pas ná de cache ervoor.
 *
 * @param int $map_id ID van de svgml_map-post (al omgezet naar de WPML-vertaling).
 * @return string <style id="svgml-custom-css-{id}">…</style> of ''.
 */
function svgml_get_map_style_tag( $map_id ) {

    // "static" onthoudt deze lijst tussen aanroepen binnen hetzelfde request
    // (dus binnen één paginaweergave). Bij het volgende paginabezoek is hij
    // weer leeg.
    static $printed = [];

    $map_id = (int) $map_id;
    if ( ! $map_id || isset( $printed[ $map_id ] ) ) {
        return '';
    }

    if ( apply_filters( 'svgml_load_all_map_css', false ) ) {
        return '';
    }

    // Wanneer mag de CSS mee in de shortcode-output?
    // - did_action( 'wp_head' ): de <head> is al geprint, we zitten in de body
    //   van een gewone frontend-pagina (ook Elementor-popups in de footer).
    // - ! doing_action( 'wp_head' ): NIET terwijl de <head> nog bezig is. Sommige
    //   SEO-plugins renderen dan de content voor een og:description; daar hoort
    //   geen CSS-tekst in, en dat zou het "al uitgevoerd"-slot verbruiken.
    // - wp_doing_ajax(): AJAX-renders, o.a. de Elementor-editor die een widget
    //   na het bewerken opnieuw rendert via admin-ajax.php.
    // - Elementor edit-mode: bij het OPENEN van de editor rendert Elementor de
    //   widgets op de editorpagina zelf (wp-admin/post.php?action=elementor):
    //   geen wp_head en geen AJAX. Zonder deze check verliest een kaart in de
    //   editor zijn kleuren. is_edit_mode() is Elementors eigen functie en is
    //   alleen true bij action=elementor / elementor_ajax of in de preview.
    // De html-export draait op admin_init via admin.php?action=export: geen
    // wp_head, geen AJAX en geen Elementor edit-mode, dus daar komt hier nooit
    // een <style> uit (geen dubbele CSS).
    // (De Elementor-check staat als laatste: PHP stopt bij de eerste "true",
    // dus op een gewone frontend-pagina wordt Elementor niet eens gevraagd.)
    $allowed = ( did_action( 'wp_head' ) && ! doing_action( 'wp_head' ) )
               || wp_doing_ajax()
               || ( class_exists( '\Elementor\Plugin' )
                    && isset( \Elementor\Plugin::$instance->editor )
                    && \Elementor\Plugin::$instance->editor->is_edit_mode() );
    if ( ! $allowed ) {
        return '';
    }

    // Pas markeren als de tag echt wordt teruggegeven.
    $printed[ $map_id ] = true;

    // :root-variabelen eerst, net als in svgml_output_custom_css(). Staan er
    // twee kaarten op één pagina, dan wint de :root van de laatst gerenderde.
    return "\n<style id=\"svgml-custom-css-{$map_id}\">\n"
         . "/* SVG Map Lite – kaart {$map_id} */\n"
         . svgml_get_map_root_css( $map_id )
         . svgml_get_map_css( $map_id )
         . "</style>\n";
}

/**
 * :ROOT-BLOK MET CSS-VARIABELEN VAN ÉÉN KAART
 * (--svgml-accent en --svgml-panel-bg worden in frontend.css gebruikt.)
 *
 * @param int $map_id
 * @return string
 */
function svgml_get_map_root_css( $map_id ) {

    $accent   = get_post_meta( $map_id, '_svgml_slider_accent_color', true ) ?: '#cc0000';
    $panel_bg = get_post_meta( $map_id, '_svgml_panel_bg_color',      true ) ?: '#ffffff';

    // Ongeldige of lege hex-waarde → standaardkleur.
    if ( ! sanitize_hex_color( $accent ) )   $accent   = '#cc0000';
    if ( ! sanitize_hex_color( $panel_bg ) ) $panel_bg = '#ffffff';

    return ":root {\n"
         . "    --svgml-accent:   {$accent};\n"
         . "    --svgml-panel-bg: {$panel_bg};\n"
         . "    --svgml-panel-w:  300px;\n"
         . "    --svgml-thumb-ratio: 56.25%;\n"
         . "}\n";
}

/**
 * GEGENEREERDE + CUSTOM CSS VAN ÉÉN KAART (zonder <style>-tag en zonder :root)
 *
 * Statuskleuren, filterkleuren, paneel- en invoerveldstijl, polygoonlijnen
 * (allemaal gescoped op #svgml-wrap-{id}) en als laatste de vrije Custom CSS
 * uit het tabblad Stijlen (NIET automatisch gescoped).
 * Wordt gebruikt door svgml_output_custom_css() (alle kaarten) en
 * svgml_get_map_style_tag() (één kaart).
 *
 * @param int $map_id
 * @return string
 */
function svgml_get_map_css( $map_id ) {

    $css_output = '';

    // Helper function
    $get_meta = function( $key, $default = '' ) use ( $map_id ) {
        $result = get_post_meta( $map_id, '_svgml_' . $key, true );
        if ( '' === $result || false === $result ) {
            return $default;
        }
        return $result;
    };

    // Specificity prefix — scoped to this map's unique wrapper ID so CSS
    // from map A can never bleed into map B when multiple maps are published.
    $p = "#svgml-wrap-{$map_id}";

    // Get map-specific colors and settings
    $status_colors     = $get_meta( 'status_colors', [] );
    $status_hex_colors = $get_meta( 'status_hex_colors', [] );
    $status_opacity    = $get_meta( 'status_opacity', [] );

    if ( ! is_array( $status_colors ) ) $status_colors = [];
    if ( ! is_array( $status_hex_colors ) ) $status_hex_colors = [];
    if ( ! is_array( $status_opacity ) ) $status_opacity = [];

    foreach ( $status_hex_colors as $status_val => $hex ) {
        if ( ! sanitize_hex_color( $hex ) ) continue;

        $css_class = $status_colors[ $status_val ] ?? '';
        if ( empty( $css_class ) ) continue;

        $opacity_pct  = max( 10, min( 100, intval( $status_opacity[ $status_val ] ?? 100 ) ) );
        $fill_opacity = round( $opacity_pct / 100, 2 );

        $rgba_light = svgml_hex_to_rgba( $hex, 0.12 );
        $rgba_mid   = svgml_hex_to_rgba( $hex, 0.90 );

        $css_output .= "{$p} .svgml-svg [id].svgml-status-{$css_class},"
                     . "{$p} .svgml-svg [id].svgml-status-{$css_class} * { "
                     . "fill: {$hex}; "
                     . "fill-opacity: {$fill_opacity}; }\n";

        $css_output .= "{$p} .svgml-badge.svgml-badge-{$css_class} { "
                     . "background-color: {$rgba_light}; "
                     . "color: {$hex}; "
                     . "border: 1px solid {$rgba_mid}; }\n";
    }

    // Filter colors
    $filter_match_color = $get_meta( 'filter_match_color', '' );
    $filter_dim_color   = $get_meta( 'filter_dim_color', '' );

    if ( ! empty( $filter_match_color ) && sanitize_hex_color( $filter_match_color ) ) {
        $css_output .= "{$p} .svgml-svg [id]:not(.svgml-region-dimmed):not(.svgml-region-excluded),"
                     . "{$p} .svgml-svg [id]:not(.svgml-region-dimmed):not(.svgml-region-excluded) *"
                     . " { fill: {$filter_match_color}; }\n";
    }

    if ( ! empty( $filter_dim_color ) && sanitize_hex_color( $filter_dim_color ) ) {
        $css_output .= "{$p} .svgml-svg [id].svgml-region-dimmed,"
                     . "{$p} .svgml-svg [id].svgml-region-dimmed *"
                     . " { fill: {$filter_dim_color}; opacity: 1; }\n";
    }

    // Panel & Filter Styling
    $panel_bg_color      = $get_meta( 'panel_bg_color',      '' );
    $panel_text_color    = $get_meta( 'panel_text_color',     '' );
    $panel_border_radius = $get_meta( 'panel_border_radius',  '' );
    $filter_bg_color     = $get_meta( 'filter_bg_color',      '' );
    $filter_text_color   = $get_meta( 'filter_text_color',    '' );
    $panel_border_color  = $get_meta( 'panel_border_color',   '' );
    $panel_border_width  = $get_meta( 'panel_border_width',   '' );
    $slider_accent_color = $get_meta( 'slider_accent_color',  '' );

    if ( ! empty( $panel_bg_color ) && sanitize_hex_color( $panel_bg_color ) ) {
        $css_output .= "{$p} .svgml-panel-inner { background-color: {$panel_bg_color}; }\n";
    }
    if ( ! empty( $panel_text_color ) && sanitize_hex_color( $panel_text_color ) ) {
        $css_output .= "{$p} .svgml-panel-inner, {$p} .svgml-panel-inner * { color: {$panel_text_color}; }\n";
    }
    if ( '' !== $panel_border_radius ) {
        $radius = max( 0, min( 50, intval( $panel_border_radius ) ) );
        $css_output .= "{$p} .svgml-panel-inner { border-radius: {$radius}px; }\n";
    }
    if ( ! empty( $filter_bg_color ) && sanitize_hex_color( $filter_bg_color ) ) {
        $css_output .= "{$p} .svgml-filters-bar { background-color: {$filter_bg_color}; }\n";
    }
    if ( ! empty( $filter_text_color ) && sanitize_hex_color( $filter_text_color ) ) {
        $css_output .= "{$p} .svgml-filters-bar, {$p} .svgml-filter-label { color: {$filter_text_color}; }\n";
    }
    if ( ! empty( $panel_border_color ) && sanitize_hex_color( $panel_border_color ) && '' !== $panel_border_width && intval( $panel_border_width ) > 0 ) {
        $bw = intval( $panel_border_width );
        $css_output .= "{$p} .svgml-panel-inner { border: {$bw}px solid {$panel_border_color}; }\n";
    }
    if ( ! empty( $slider_accent_color ) && sanitize_hex_color( $slider_accent_color ) ) {
        $css_output .= "{$p} .svgml-range-slider .noUi-connect { background-color: {$slider_accent_color}; }\n";
        $css_output .= "{$p} .svgml-range-slider .noUi-handle { border-color: {$slider_accent_color}; }\n";
    }

    // Input field styling
    $input_bg_color     = $get_meta( 'input_bg_color',     '' );
    $input_text_color   = $get_meta( 'input_text_color',   '' );
    $input_border_color = $get_meta( 'input_border_color', '' );
    $input_focus_color  = $get_meta( 'input_focus_color',  '' );

    $input_selectors       = "{$p} .svgml-filter-input-single, {$p} .svgml-filter-input-min, {$p} .svgml-filter-input-max, {$p} .svgml-filter-search";
    $input_focus_selectors = "{$p} .svgml-filter-input-single:focus, {$p} .svgml-filter-input-min:focus, {$p} .svgml-filter-input-max:focus, {$p} .svgml-filter-search:focus";

    if ( ! empty( $input_bg_color ) && sanitize_hex_color( $input_bg_color ) ) {
        $css_output .= "{$input_selectors} { background-color: {$input_bg_color}; }\n";
    }
    if ( ! empty( $input_text_color ) && sanitize_hex_color( $input_text_color ) ) {
        $css_output .= "{$input_selectors} { color: {$input_text_color}; }\n";
    }
    if ( ! empty( $input_border_color ) && sanitize_hex_color( $input_border_color ) ) {
        $css_output .= "{$input_selectors} { border-color: {$input_border_color}; }\n";
    }
    if ( ! empty( $input_focus_color ) && sanitize_hex_color( $input_focus_color ) ) {
        $css_output .= "{$input_focus_selectors} { border-color: {$input_focus_color}; outline-color: {$input_focus_color}; }\n";
    }

    // Polygon stroke styling — meta keys: _svgml_poly_stroke_color / _svgml_poly_stroke_width
    $poly_stroke_color = sanitize_text_field(
        get_post_meta( $map_id, '_svgml_poly_stroke_color', true )
    );
    if ( empty( $poly_stroke_color ) ) {
        $poly_stroke_color = '#ffffff';
    }

    $raw_stroke_width  = get_post_meta( $map_id, '_svgml_poly_stroke_width', true );
    $poly_stroke_width = ( is_numeric( $raw_stroke_width ) && floatval( $raw_stroke_width ) > 0 )
                         ? floatval( $raw_stroke_width )
                         : 1;

    $raw_hover_width  = get_post_meta( $map_id, '_svgml_poly_stroke_width_hover', true );
    $hover_width      = ( is_numeric( $raw_hover_width ) && floatval( $raw_hover_width ) > 0 )
                        ? floatval( $raw_hover_width )
                        : 3;

    $raw_highlight_color   = get_post_meta( $map_id, '_svgml_highlight_color', true );
    $highlight_color       = ( ! empty( $raw_highlight_color ) && sanitize_hex_color( $raw_highlight_color ) )
                             ? $raw_highlight_color
                             : $poly_stroke_color;

    $raw_highlight_opacity = get_post_meta( $map_id, '_svgml_highlight_opacity', true );
    $highlight_opacity     = ( is_numeric( $raw_highlight_opacity ) )
                             ? round( max( 0.0, min( 1.0, floatval( $raw_highlight_opacity ) ) ), 2 )
                             : 0.7;

    // vector-effect: non-scaling-stroke → stroke-width is screen pixels.
    $sw       = round( $poly_stroke_width, 2 );
    $sw_hover = round( $hover_width, 2 );

    $stroke_rgba_half  = svgml_hex_to_rgba( $poly_stroke_color, 0.5 );
    $stroke_rgba_full  = svgml_hex_to_rgba( $poly_stroke_color, 0.9 );
    $highlight_rgba    = svgml_hex_to_rgba( $highlight_color, $highlight_opacity );

    $sel_base = implode( ', ', [
        "{$p} .svgml-poly-region",
        "{$p} .svgml-svg polygon[id]",
        "{$p} .svgml-svg path[id]",
    ] );
    $sel_hover = implode( ', ', [
        "{$p} .svgml-poly-region:hover",
        "{$p} .svgml-poly-region.svgml-region-hover",
        "{$p} .svgml-svg polygon[id]:hover",
        "{$p} .svgml-svg path[id]:hover",
    ] );
    $sel_active = implode( ', ', [
        "{$p} .svgml-poly-region.svgml-region-active",
        "{$p} .svgml-svg polygon[id].svgml-region-active",
        "{$p} .svgml-svg path[id].svgml-region-active",
    ] );

    $css_output .= "{$sel_base} { "
                 . "stroke: {$stroke_rgba_half} !important; "
                 . "stroke-width: {$sw}px !important; "
                 . "vector-effect: non-scaling-stroke !important; }\n";
    $css_output .= "{$sel_hover} { "
                 . "fill: {$highlight_rgba} !important; "
                 . "stroke: {$stroke_rgba_full} !important; "
                 . "stroke-width: {$sw_hover}px !important; "
                 . "vector-effect: non-scaling-stroke !important; }\n";
    $css_output .= "{$sel_active} { "
                 . "fill: {$highlight_rgba} !important; "
                 . "stroke: {$poly_stroke_color} !important; "
                 . "stroke-width: {$sw_hover}px !important; "
                 . "vector-effect: non-scaling-stroke !important; }\n";

    // Custom CSS (per-map)
    $custom_css = $get_meta( 'custom_css', '' );
    if ( ! empty( trim( $custom_css ) ) ) {
        $css_output .= "/* Map: " . esc_html( get_post_field( 'post_title', $map_id ) ) . " */\n";
        $css_output .= wp_strip_all_tags( $custom_css ) . "\n";
    }

    return $css_output;
}

/**
 * UTILITY: HEX TO RGBA COLOR CONVERSION
 * Converts hex color to rgba() string
 */
function svgml_hex_to_rgba( $hex, $alpha = 1.0 ) {
    $hex = ltrim( $hex, '#' );

    if ( strlen( $hex ) === 3 ) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    if ( strlen( $hex ) !== 6 ) return 'rgba(0,0,0,1)';

    $r = hexdec( substr( $hex, 0, 2 ) );
    $g = hexdec( substr( $hex, 2, 2 ) );
    $b = hexdec( substr( $hex, 4, 2 ) );
    $a = number_format( floatval( $alpha ), 2, '.', '' );

    return "rgba({$r}, {$g}, {$b}, {$a})";
}

/**
 * Get normalized JSON array from JSON data
 * Handles wrapped/nested JSON structures
 */
function svgml_get_json_array( $map_id = 0 ) {
    $raw = svgml_get_json_data( $map_id );

    if ( is_array( $raw ) && isset( $raw[0] ) ) {
        return $raw;
    }

    if ( ! is_array( $raw ) ) {
        return [];
    }

    // Manual override
    $array_key = ( $map_id ? get_post_meta( $map_id, '_svgml_json_array_key', true ) : get_option( 'svgml_json_array_key', '' ) );
    if ( ! empty( $array_key ) && isset( $raw[ $array_key ] ) && is_array( $raw[ $array_key ] ) ) {
        return $raw[ $array_key ];
    }

    // Auto-detection
    $known_keys = [ 'assets', 'data', 'items', 'results', 'objects', 'features',
                    'records', 'list', 'collection', 'entries', 'value', 'spaces',
                    'units', 'properties', 'lots', 'houses', 'apartments' ];

    foreach ( $known_keys as $key ) {
        if ( isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) && ! empty( $raw[ $key ] ) ) {
            return $raw[ $key ];
        }
    }

    $best      = [];
    $best_size = 0;
    foreach ( $raw as $value ) {
        if ( is_array( $value ) && count( $value ) > $best_size ) {
            if ( isset( $value[0] ) && is_array( $value[0] ) ) {
                $best      = $value;
                $best_size = count( $value );
            }
        }
    }
    if ( ! empty( $best ) ) {
        return $best;
    }

    return [];
}

/**
 * Get field names from JSON data
 */
function svgml_get_json_field_names( $map_id = 0 ) {
    $data = svgml_get_json_array( $map_id );

    if ( empty( $data ) || ! is_array( $data[0] ) ) {
        return [];
    }

    return array_keys( $data[0] );
}

/**
 * Get JSON data for a specific map
 * Uses per-map transient caching
 */
function svgml_get_json_data( $map_id = 0 ) {
    if ( ! $map_id ) {
        $json_url = get_option( 'svgml_json_url', '' );
    } else {
        $json_url = get_post_meta( $map_id, '_svgml_json_url', true );
    }

    if ( empty( $json_url ) ) {
        return [];
    }

    // Try to get from cache first
    $cache_key = $map_id ? 'svgml_json_cache_' . $map_id : 'svgml_json_cache';
    $cached = get_transient( $cache_key );
    if ( false !== $cached ) {
        return $cached;
    }

    // Fetch data via HTTP
    $response = wp_remote_get( $json_url, [
        'timeout'    => 15,
        'user-agent' => 'SVG Map Lite WordPress Plugin',
    ]);

    if ( is_wp_error( $response ) ) {
        return [];
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    if ( 200 !== $status_code ) {
        return [];
    }

    $body      = wp_remote_retrieve_body( $response );
    $json_data = json_decode( $body, true ) ?? [];

    // Cache for 5 minutes
    set_transient( $cache_key, $json_data, 5 * MINUTE_IN_SECONDS );

    return $json_data;
}
