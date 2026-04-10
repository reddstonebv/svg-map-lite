<?php
/**
 * Plugin Name: SVG Map Lite
 * Plugin URI:  https://reddstone.nl/svg-map-lite
 * Description: Interactieve kaart plugin. Upload een afbeelding of SVG, teken polygonen,
 *              koppel ze aan een JSON feed, en toon data in een info-panel.
 *              Ondersteunt meerdere kaarten per site.
 * Version:     2.0.0
 * Author:      REDDSTONE
 * Author URI:  https://reddstone.nl
 * License:     GPL v2 or later
 * Text Domain: svg-map-lite
 */

// ─────────────────────────────────────────────────────────────────────────────
// SECURITY CHECK
// ─────────────────────────────────────────────────────────────────────────────
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// CONSTANTS
// ─────────────────────────────────────────────────────────────────────────────
define( 'SVGML_VERSION', '2.0.0' );
define( 'SVGML_PATH',    plugin_dir_path( __FILE__ ) );
define( 'SVGML_URL',     plugin_dir_url( __FILE__ ) );

// ─────────────────────────────────────────────────────────────────────────────
// INCLUDE FILES
// Each include handles one responsibility: admin pages, AJAX, frontend, etc.
// ─────────────────────────────────────────────────────────────────────────────
require_once SVGML_PATH . 'includes/ajax.php';
require_once SVGML_PATH . 'includes/frontend.php';
require_once SVGML_PATH . 'includes/admin-footer.php';
// Admin page files are loaded on-demand inside the render wrapper functions below.

// ─────────────────────────────────────────────────────────────────────────────
// ACTIVATION HOOK
// Sets up the Custom Post Type and flushes rewrite rules so the CPT works
// immediately after activation.
// ─────────────────────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'svgml_activate' );

function svgml_activate() {
    // Register the CPT first so WordPress knows about it before flushing
    svgml_register_map_cpt();
    flush_rewrite_rules();
    // Store plugin version for future migration checks
    update_option( 'svgml_version', SVGML_VERSION );
}

// ─────────────────────────────────────────────────────────────────────────────
// CUSTOM POST TYPE: svgml_map
// Each "map" is a separate post with its own settings stored as post_meta.
// This allows multiple maps on one WordPress site.
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'init', 'svgml_register_map_cpt' );

function svgml_register_map_cpt() {
    // Labels in Dutch for the admin UI
    $labels = [
        'name'               => 'Kaarten',
        'singular_name'      => 'Kaart',
        'add_new'            => 'Nieuwe kaart',
        'add_new_item'       => 'Nieuwe kaart toevoegen',
        'edit_item'          => 'Kaart bewerken',
        'new_item'           => 'Nieuwe kaart',
        'view_item'          => 'Kaart bekijken',
        'search_items'       => 'Kaarten zoeken',
        'not_found'          => 'Geen kaarten gevonden',
        'not_found_in_trash' => 'Geen kaarten in prullenbak',
    ];

    register_post_type( 'svgml_map', [
        'labels'       => $labels,
        'public'       => false,       // Not publicly queryable
        'show_ui'      => false,       // We build our own UI
        'show_in_menu' => false,       // We add our own menu
        'supports'     => [ 'title' ], // Only need a title (= map name)
        'capability_type' => 'post',
    ] );
}

// ─────────────────────────────────────────────────────────────────────────────
// EARLY REDIRECT HANDLER
// Handles create/delete map actions BEFORE any HTML output is sent.
// This runs on admin_init, which fires before WordPress renders the page.
// Without this, wp_redirect() would fail with "headers already sent".
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'admin_init', 'svgml_handle_overview_actions' );

function svgml_handle_overview_actions() {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'svgml-overview' ) {
        return;
    }
    if ( ! isset( $_GET['action'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // ── STAP 1: Alleen doorsturen naar de keuzepagina ───────────────────────
    if ( $_GET['action'] === 'new' ) {
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'svgml_create_map' ) ) {
            wp_die( 'Beveiligingsfout.' );
        }
        
        // We maken hier NOG GEEN post aan. We sturen de gebruiker naar de selectie.
        wp_redirect( admin_url( 'admin.php?page=svgml-selection' ) );
        exit;
    }

    // ── STAP 2: De kaart echt aanmaken NADAT de mode is gekozen ─────────────
    if ( $_GET['action'] === 'create_with_mode' && isset( $_GET['mode'] ) ) {
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'svgml_create_map_with_mode' ) ) {
            wp_die( 'Beveiligingsfout.' );
        }

        $mode = sanitize_text_field( $_GET['mode'] );
        $new_id = wp_insert_post( [
            'post_type'   => 'svgml_map',
            'post_title'  => 'Nieuwe kaart (' . ( $mode === 'json' ? 'JSON' : 'Manual' ) . ')',
            'post_status' => 'publish',
        ] );

        if ( $new_id && ! is_wp_error( $new_id ) ) {
            update_post_meta( $new_id, '_svgml_map_mode', $mode ); // Hier slaan we de keuze op!
            svgml_set_default_map_meta( $new_id );
            wp_redirect( admin_url( 'admin.php?page=svgml-settings&map_id=' . $new_id ) );
            exit;
        }
    }

    // DELETE logica (ongewijzigd laten)
    if ( $_GET['action'] === 'delete' && isset( $_GET['map_id'] ) ) {
        $del_id = intval( $_GET['map_id'] );
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'svgml_delete_map_' . $del_id ) ) {
            wp_die( 'Beveiligingsfout.' );
        }
        $post = get_post( $del_id );
        if ( $post && $post->post_type === 'svgml_map' ) {
            wp_delete_post( $del_id, true );
        }
        wp_redirect( admin_url( 'admin.php?page=svgml-overview&deleted=1' ) );
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ADMIN MENU
// Top-level menu "SVG Map Lite" with:
//   - "Alle kaarten" overview (card grid of all maps)
//   - Hidden editor pages (null parent = not visible in sidebar at all)
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'admin_menu', 'svgml_add_admin_menu' );

function svgml_add_admin_menu() {

    // Top-level menu item in the sidebar
    add_menu_page(
        'SVG Map Lite',
        'SVG Map Lite',
        'manage_options',
        'svgml-overview',                // Slug for the overview page
        'svgml_render_overview_page',    // Render function
        'dashicons-location-alt',
        30
    );

    // Submenu: "Alle kaarten" (same slug as parent → replaces auto-generated first item)
    add_submenu_page(
        'svgml-overview',
        'Alle kaarten',
        'Alle kaarten',
        'manage_options',
        'svgml-overview',
        'svgml_render_overview_page'
    );

    // ── HIDDEN EDITOR PAGES ─────────────────────────────────────────────────
    // By using null as parent_slug, these pages are registered in WordPress
    // (so admin.php?page=xxx works) but they do NOT appear in the sidebar.
    // This prevents the empty <li> items that showed up with '' as menu title.
$editor_pages = [
        'svgml-selection'      => [ 'Modus selectie',    'svgml_render_selection_page' ], // VOEG DEZE TOE
        'svgml-settings'      => [ 'Instellingen',    'svgml_render_editor_wrapper' ],
        'svgml-mapping'       => [ 'Regio Koppeling',  'svgml_render_editor_wrapper' ],
        'svgml-display'       => [ 'Weergave',         'svgml_render_editor_wrapper' ],
        'svgml-panel-builder' => [ 'Panel Builder',     'svgml_render_editor_wrapper' ],
        'svgml-filters'       => [ 'Filters',           'svgml_render_editor_wrapper' ],
        'svgml-styles'        => [ 'Stijlen & CSS',     'svgml_render_editor_wrapper' ],
        'svgml-ai-assistant'  => [ 'AI Assistent',      'svgml_render_editor_wrapper' ],
    ];

    foreach ( $editor_pages as $slug => $info ) {
        // null parent = hidden page, accessible via direct URL only
        add_submenu_page(
            null,                        // null = completely hidden from sidebar
            $info[0],
            $info[0],
            'manage_options',
            $slug,
            $info[1]
        );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// OVERVIEW PAGE: Card grid showing all maps
// ─────────────────────────────────────────────────────────────────────────────

function svgml_render_overview_page() {
    // NOTE: Create/delete actions are handled in svgml_handle_overview_actions()
    // on the admin_init hook (runs BEFORE any HTML output, preventing
    // the "headers already sent" error with wp_redirect).

    // Query all maps
    $maps = get_posts( [
        'post_type'      => 'svgml_map',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'post_status'    => 'any',
    ] );

    // Build the "New map" URL with nonce
    $new_map_url = wp_nonce_url(
        admin_url( 'admin.php?page=svgml-overview&action=new' ),
        'svgml_create_map'
    );

    ?>
    <div class="wrap svgml-admin-wrap">
        <div class="svgml-overview-header">
            <h1>
                <span class="dashicons dashicons-location-alt"></span>
                SVG Map Lite
            </h1>
            <a href="<?php echo esc_url( $new_map_url ); ?>" class="button button-primary svgml-new-map-btn">
                <span class="dashicons dashicons-plus-alt2"></span>
                Nieuwe kaart
            </a>
        </div>

        <?php if ( isset( $_GET['deleted'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>Kaart verwijderd.</p></div>
        <?php endif; ?>

        <?php if ( empty( $maps ) ) : ?>
            <!-- Empty state: no maps yet -->
            <div class="svgml-empty-state">
                <span class="dashicons dashicons-location-alt"></span>
                <h2>Nog geen kaarten</h2>
                <p>Maak je eerste interactieve kaart aan om te beginnen.</p>
                <a href="<?php echo esc_url( $new_map_url ); ?>" class="button button-primary">
                    <span class="dashicons dashicons-plus-alt2"></span>
                    Eerste kaart aanmaken
                </a>
            </div>
        <?php else : ?>
            <!-- Card grid of all maps -->
            <div class="svgml-maps-grid">
                <?php foreach ( $maps as $map ) :
                    $map_id    = $map->ID;
                    $layers    = get_post_meta( $map_id, '_svgml_layers', true );
                    if ( ! is_array( $layers ) ) $layers = [];
                    $poly_count = 0;
                    $thumb_url  = '';

                    // Count polygons and get first layer thumbnail
                    foreach ( $layers as $layer ) {
                        $poly_count += count( $layer['polygons'] ?? [] );
                        if ( empty( $thumb_url ) && ! empty( $layer['image_attachment_id'] ) ) {
                            $thumb_url = wp_get_attachment_image_url( $layer['image_attachment_id'], 'medium' );
                        }
                    }

                    // Fallback: check for SVG source
                    if ( empty( $thumb_url ) ) {
                        $svg_id = get_post_meta( $map_id, '_svgml_svg_attachment_id', true );
                        if ( $svg_id ) {
                            $thumb_url = wp_get_attachment_url( $svg_id );
                        }
                    }

                    $source_type = get_post_meta( $map_id, '_svgml_source_type', true ) ?: 'image';
                    $json_url    = get_post_meta( $map_id, '_svgml_json_url', true );
                    $edit_url    = admin_url( 'admin.php?page=svgml-settings&map_id=' . $map_id );
                    $delete_url  = wp_nonce_url(
                        admin_url( 'admin.php?page=svgml-overview&action=delete&map_id=' . $map_id ),
                        'svgml_delete_map_' . $map_id
                    );
                ?>
                    <div class="svgml-map-card">
                        <!-- Card thumbnail -->
                        <div class="svgml-map-card-thumb">
                            <?php if ( $thumb_url ) : ?>
                                <img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( $map->post_title ); ?>">
                            <?php else : ?>
                                <div class="svgml-map-card-no-thumb">
                                    <span class="dashicons dashicons-format-image"></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Card body -->
                        <div class="svgml-map-card-body">
                            <h3 class="svgml-map-card-title"><?php echo esc_html( $map->post_title ); ?></h3>

                            <div class="svgml-map-card-meta">
                                <?php if ( count( $layers ) > 0 ) : ?>
                                    <span class="svgml-map-card-badge">
                                        <?php echo count( $layers ); ?> <?php echo count( $layers ) === 1 ? 'laag' : 'lagen'; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ( $poly_count > 0 ) : ?>
                                    <span class="svgml-map-card-badge">
                                        <?php echo $poly_count; ?> regio's
                                    </span>
                                <?php endif; ?>
                                <?php if ( $json_url ) : ?>
                                    <span class="svgml-map-card-badge svgml-badge-active">JSON ✓</span>
                                <?php endif; ?>
                            </div>

                            <!-- Shortcode to copy -->
                            <div class="svgml-map-card-shortcode">
                                <code>[svg_map id="<?php echo $map_id; ?>"]</code>
                            </div>
                        </div>

                        <!-- Card actions -->
                        <div class="svgml-map-card-actions">
                            <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-primary button-small">
                                <span class="dashicons dashicons-edit"></span> Bewerken
                            </a>
                            <a href="<?php echo esc_url( $delete_url ); ?>"
                               class="button button-link-delete button-small"
                               onclick="return confirm('Weet je zeker dat je deze kaart wilt verwijderen?');">
                                <span class="dashicons dashicons-trash"></span> Verwijder
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Set default meta values for a newly created map.
 * Called once when a map is first created.
 *
 * @param int $map_id  The post ID of the new svgml_map.
 */
function svgml_set_default_map_meta( $map_id ) {
    $defaults = [
        '_svgml_source_type'         => 'image',
        '_svgml_svg_attachment_id'   => '',
        '_svgml_svg_ids'             => [],
        '_svgml_image_attachment_id' => '',
        '_svgml_polygons'            => [],
        '_svgml_poly_stroke_color'   => '#2a9d8f',
        '_svgml_poly_stroke_width'   => '1',
        '_svgml_layers'              => [],
        '_svgml_layer_switcher'      => 'buttons',
        '_svgml_json_url'            => '',
        '_svgml_json_id_field'       => 'id',
        '_svgml_json_array_key'      => '',
        '_svgml_id_mapping'          => [],
        '_svgml_excluded_ids'        => [],
        '_svgml_display_fields'      => [],
        '_svgml_panel_position'      => 'right',
        '_svgml_panel_title'         => '',
        '_svgml_panel_blocks'        => [],
        '_svgml_filter_fields'       => [],
        '_svgml_status_field'        => '',
        '_svgml_status_colors'       => [
            'Beschikbaar' => 'available',
            'Onder optie' => 'option',
            'Verkocht'    => 'sold',
            'Verhuurd'    => 'rented',
        ],
        '_svgml_status_hex_colors'   => [
            'Beschikbaar' => '#2e9e3c',
            'Onder optie' => '#f0a500',
            'Verkocht'    => '#cc0000',
            'Verhuurd'    => '#cc0000',
        ],
        '_svgml_status_opacity'      => [],
        '_svgml_custom_css'          => '',
        '_svgml_filter_match_color'  => '',
        '_svgml_filter_dim_color'    => '',
        '_svgml_overview_enabled'    => false,
        '_svgml_overview_blocks'     => [],
    ];

    foreach ( $defaults as $key => $value ) {
        update_post_meta( $map_id, $key, $value );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MAP EDITOR WRAPPER
// This function wraps all the sub-page renders. It reads map_id from the URL,
// shows the map title + tab navigation, and then loads the correct include
// file to render the active tab's content.
// ─────────────────────────────────────────────────────────────────────────────

function svgml_render_editor_wrapper() {
    $map_id = isset( $_GET['map_id'] ) ? intval( $_GET['map_id'] ) : 0;
    $map    = $map_id ? get_post( $map_id ) : null;

    // If no valid map, redirect to overview
    if ( ! $map || $map->post_type !== 'svgml_map' ) {
        echo '<div class="wrap"><div class="notice notice-error"><p>';
        echo 'Kaart niet gevonden. <a href="' . esc_url( admin_url( 'admin.php?page=svgml-overview' ) ) . '">Terug naar overzicht</a>';
        echo '</p></div></div>';
        return;
    }

    // Determine which tab/page we're on based on the page slug
    $current_page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : 'svgml-settings';

    // Get map mode to conditionally set tab labels
    $map_mode = get_post_meta( $map_id, '_svgml_map_mode', true ) ?: 'json';

    // Tab definitions: slug => [ label, include_file, render_function ]
    $tabs = [
        'svgml-settings'      => [ 'Afbeelding & Koppeling', 'settings.php',      'svgml_render_settings_page' ],
        'svgml-mapping'       => [ 'Data per Vlak',          'mapping.php',       'svgml_render_mapping_page' ],
        'svgml-panel-builder' => [ 'Paneel Bouwer',          'panel-builder.php', 'svgml_render_panel_builder_page' ],
        'svgml-display'       => [ 'Weergave',               'display.php',       'svgml_render_display_page' ],
        'svgml-filters'       => [ 'Filters',                'filters.php',       'svgml_render_filters_page' ],
        'svgml-styles'        => [ 'Stijlen & CSS',          'styles.php',        'svgml_render_styles_page' ],
        'svgml-ai-assistant'  => [ '🤖 AI Assistent',        'ai-assistant.php',  'svgml_render_ai_assistant_page' ],
    ];

    // Handle inline title editing (quick rename via the header)
    if ( isset( $_POST['svgml_map_title'] ) && isset( $_POST['svgml_rename_nonce'] ) ) {
        if ( wp_verify_nonce( $_POST['svgml_rename_nonce'], 'svgml_rename_map_' . $map_id ) ) {
            $new_title = sanitize_text_field( $_POST['svgml_map_title'] );
            if ( ! empty( $new_title ) ) {
                wp_update_post( [ 'ID' => $map_id, 'post_title' => $new_title ] );
                $map->post_title = $new_title; // Update local variable
            }
        }
    }

    ?>
    <div class="wrap svgml-admin-wrap">
        <!-- Editor header: map name + back button -->
        <div class="svgml-editor-header">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=svgml-overview' ) ); ?>"
               class="svgml-back-link" title="Terug naar overzicht">
                ← Alle kaarten
            </a>

            <!-- Inline editable map name -->
            <form method="post" class="svgml-map-name-form" style="display:inline-flex; align-items:center; gap:8px;">
                <?php wp_nonce_field( 'svgml_rename_map_' . $map_id, 'svgml_rename_nonce' ); ?>
                <input type="text" name="svgml_map_title"
                       value="<?php echo esc_attr( $map->post_title ); ?>"
                       class="svgml-map-name-input">
                <button type="submit" class="button button-small" title="Naam opslaan">✓</button>
            </form>

        </div>

        <!-- Tab navigation (sticky) -->
        <nav class="svgml-editor-tabs" id="svgml-editor-tabs">
            <div class="svgml-editor-tabs-left">
                <?php foreach ( $tabs as $slug => $tab_info ) :
                    $tab_url = admin_url( 'admin.php?page=' . $slug . '&map_id=' . $map_id );
                    $is_active = ( $current_page === $slug );
                ?>
                    <a href="<?php echo esc_url( $tab_url ); ?>"
                       class="svgml-editor-tab <?php echo $is_active ? 'svgml-editor-tab-active' : ''; ?>">
                        <?php echo esc_html( $tab_info[0] ); ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="svgml-editor-tabs-right">
                <code class="svgml-header-shortcode">[svg_map id="<?php echo $map_id; ?>"]</code>
                <button type="button" id="svgml-save-btn" class="button button-primary svgml-save-btn">
                    <span class="dashicons dashicons-saved"></span> Opslaan
                </button>
                <span class="svgml-save-hint">⌘/Ctrl + S</span>
            </div>
        </nav>

        <?php
        // Load the correct include file and call the render function
        if ( isset( $tabs[ $current_page ] ) ) {
            $include_file    = $tabs[ $current_page ][1];
            $render_function = $tabs[ $current_page ][2];

            require_once SVGML_PATH . 'includes/' . $include_file;

            if ( function_exists( $render_function ) ) {
                // Call the render function with the map_id
                $render_function( $map_id );
            }
        }
        ?>
    </div>
    <?php
}

// ─────────────────────────────────────────────────────────────────────────────
// ADMIN SCRIPTS & STYLES
// Only loads assets on our own admin pages.
// Now reads map data from post_meta instead of options.
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'admin_enqueue_scripts', 'svgml_admin_enqueue' );

function svgml_admin_enqueue( $hook ) {

    // Determine which of our pages we're on by checking $_GET['page'].
    // This is more reliable than comparing $hook strings, because WordPress
    // generates different hook names depending on whether the submenu has a
    // parent (svg-map-lite_page_xxx) or uses null parent (admin_page_xxx).
    $current_page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';

    // List of all our page slugs
    $svgml_pages = [
        'svgml-overview',
        'svgml-selection',
        'svgml-settings',
        'svgml-mapping',
        'svgml-display',
        'svgml-panel-builder',
        'svgml-filters',
        'svgml-styles',
        'svgml-ai-assistant',
    ];

    // Stop if we're not on one of our pages
    if ( ! in_array( $current_page, $svgml_pages, true ) ) {
        return;
    }

    // The overview page only needs basic admin CSS
    wp_enqueue_style(
        'svgml-admin-css',
        SVGML_URL . 'assets/css/admin.css',
        [],
        SVGML_VERSION
    );

    // Teal background for our admin pages
    wp_add_inline_style( 'svgml-admin-css', 'body.wp-admin { background: #edf5f4 !important; }' );

    // Overview page doesn't need the editor scripts
    if ( 'svgml-overview' === $current_page ) {
        return;
    }

    // ── Editor pages: load full asset stack ──────────────────────────────────
    wp_enqueue_media();

    // Shared utilities
    wp_enqueue_script(
        'svgml-utils-js',
        SVGML_URL . 'assets/js/utils.js',
        [],
        SVGML_VERSION,
        true
    );

    // Admin JS
    wp_enqueue_script(
        'svgml-admin-js',
        SVGML_URL . 'assets/js/admin.js',
        [ 'jquery', 'jquery-ui-sortable', 'svgml-utils-js' ],
        SVGML_VERSION,
        true
    );

    // Fabric.js + Polygon Editor (only on Settings page)
    if ( 'svgml-settings' === $current_page ) {
        if ( file_exists( SVGML_PATH . 'assets/js/vendor/fabric.min.js' ) ) {
            $fabric_url = SVGML_URL . 'assets/js/vendor/fabric.min.js';
        } else {
            $fabric_url = 'https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js';
        }

        wp_enqueue_script( 'fabricjs', $fabric_url, [], '5.3.1', true );
        wp_enqueue_script(
            'svgml-polygon-editor',
            SVGML_URL . 'assets/js/polygon-editor.js',
            [ 'jquery', 'fabricjs', 'svgml-admin-js' ],
            SVGML_VERSION,
            true
        );
    }

    // CodeMirror on Styles page
    if ( 'svgml-styles' === $current_page ) {
        $cm_settings = wp_enqueue_code_editor( [ 'type' => 'text/css' ] );
    }

    // Panel Builder submit serializer (populates #svgml_panel_blocks before POST)
    if ( 'svgml-panel-builder' === $current_page ) {
        wp_enqueue_script(
            'svgml-panel-builder-js',
            SVGML_URL . 'assets/js/panel-builder.js',
            [ 'jquery' ],         // only jQuery needed – no Fabric, no utils
            SVGML_VERSION,
            true                  // load in footer, after DOM is ready
        );
    }

    // ── Build localize data from post_meta ───────────────────────────────────
    $map_id = isset( $_GET['map_id'] ) ? intval( $_GET['map_id'] ) : 0;

    $localize_data = [
        'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
        'nonce'         => wp_create_nonce( 'svgml_admin_nonce' ),
        'mapId'         => $map_id,
        'mapMode'       => $map_id ? ( get_post_meta( $map_id, '_svgml_map_mode', true ) ?: 'json' ) : 'json',
        'svgId'         => $map_id ? get_post_meta( $map_id, '_svgml_svg_attachment_id', true ) : '',
        'jsonArrayKey'  => $map_id ? ( get_post_meta( $map_id, '_svgml_json_array_key', true ) ?: '' ) : '',
        'layers'        => $map_id ? ( get_post_meta( $map_id, '_svgml_layers', true ) ?: [] ) : [],
        'layerSwitcher' => $map_id ? ( get_post_meta( $map_id, '_svgml_layer_switcher', true ) ?: 'buttons' ) : 'buttons',
        'strings'       => [
            'selectSvg'   => 'Selecteer SVG',
            'useSvg'      => 'Gebruik deze SVG',
            'parsing'     => 'SVG wordt verwerkt…',
            'noIds'       => 'Geen ID\'s gevonden in deze SVG.',
            'notInJson'   => '✗ Niet gevonden in JSON',
            'noNameField' => '(geen name-veld)',
        ],
    ];

    // Pages that need JSON data for live lookups
    $pages_needing_json = [
        'svgml-mapping',
        'svgml-panel-builder',
        'svgml-display',
        'svgml-filters',
    ];

    if ( in_array( $current_page, $pages_needing_json, true ) && $map_id ) {
        $localize_data['jsonData']    = svgml_get_json_data( $map_id );
        $localize_data['jsonIdField'] = get_post_meta( $map_id, '_svgml_json_id_field', true ) ?: 'id';
    }

    // CodeMirror settings for Styles page
    if ( 'svgml-styles' === $current_page ) {
        $localize_data['codemirrorSettings'] = isset( $cm_settings ) && $cm_settings ? $cm_settings : false;
    }

    wp_localize_script( 'svgml-admin-js', 'svgmlAdmin', $localize_data );
}

// ─────────────────────────────────────────────────────────────────────────────
// FRONTEND SCRIPTS & STYLES
// Loads assets on public pages that contain our shortcodes.
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', 'svgml_frontend_enqueue' );

function svgml_frontend_enqueue() {
    // Register all frontend assets — enqueued on demand when shortcode renders.

    // noUiSlider (local vendor preferred, CDN fallback)
    if ( file_exists( SVGML_PATH . 'assets/css/vendor/nouislider.min.css' ) ) {
        $noui_css = SVGML_URL . 'assets/css/vendor/nouislider.min.css';
    } else {
        $noui_css = 'https://cdn.jsdelivr.net/npm/nouislider@15.8.1/dist/nouislider.min.css';
    }
    if ( file_exists( SVGML_PATH . 'assets/js/vendor/nouislider.min.js' ) ) {
        $noui_js = SVGML_URL . 'assets/js/vendor/nouislider.min.js';
    } else {
        $noui_js = 'https://cdn.jsdelivr.net/npm/nouislider@15.8.1/dist/nouislider.min.js';
    }

    wp_register_style(  'nouislider-css',         $noui_css, [], '15.8.1' );
    wp_register_script( 'nouislider-js',           $noui_js,  [], '15.8.1', true );
    wp_register_style(  'svgml-frontend-css',      SVGML_URL . 'assets/css/frontend.css',       [ 'nouislider-css' ],                                       SVGML_VERSION );
    wp_register_script( 'svgml-utils-js',          SVGML_URL . 'assets/js/utils.js',            [],                                                         SVGML_VERSION, true );
    wp_register_script( 'svgml-frontend-js',       SVGML_URL . 'assets/js/frontend.js',         [ 'jquery', 'svgml-utils-js' ],                             SVGML_VERSION, true );
    wp_register_script( 'svgml-panel-renderer-js', SVGML_URL . 'assets/js/panel-renderer.js',   [ 'jquery', 'svgml-utils-js', 'svgml-frontend-js' ],        SVGML_VERSION, true );
    wp_register_script( 'svgml-filters-js',        SVGML_URL . 'assets/js/filters.js',          [ 'jquery', 'nouislider-js', 'svgml-utils-js', 'svgml-frontend-js' ], SVGML_VERSION, true );
}

// ─────────────────────────────────────────────────────────────────────────────
// SVG UPLOAD SUPPORT
// ─────────────────────────────────────────────────────────────────────────────
add_filter( 'upload_mimes', 'svgml_allow_svg_upload' );

function svgml_allow_svg_upload( $mimes ) {
    $mimes['svg'] = 'image/svg+xml';
    return $mimes;
}

add_filter( 'wp_check_filetype_and_ext', 'svgml_fix_svg_mime', 10, 4 );

function svgml_fix_svg_mime( $data, $file, $filename, $mimes ) {
    $filetype = wp_check_filetype( $filename, $mimes );
    if ( 'svg' === $filetype['ext'] ) {
        $data['ext']  = 'svg';
        $data['type'] = 'image/svg+xml';
    }
    return $data;
}



// ─────────────────────────────────────────────────────────────────────────────
// Render de selectiepagina voor het kaarttype (JSON vs Handmatig).
// ─────────────────────────────────────────────────────────────────────────────
function svgml_render_selection_page() {
    ?>
    <div class="wrap svgml-admin-wrap">
        <div class="svgml-selection-container">         
            <header class="svgml-selection-header">
                <h1>Kies een kaarttype</h1>
                <p>Hoe wil je de informatie voor deze kaart beheren?</p>
            </header>
            <div class="svgml-selection-grid">               
                <div class="svgml-selection-card">
                    <span class="dashicons dashicons-cloud"></span>
                    <h2>JSON Feed (Ally)</h2>
                    <p>Koppel je kaart aan een externe JSON bron. Ideaal voor automatische updates van prijzen en statussen.</p>
                    <a href="<?php echo wp_nonce_url( admin_url('admin.php?page=svgml-overview&action=create_with_mode&mode=json'), 'svgml_create_map_with_mode' ); ?>" 
                       class="button button-primary button-large">Kies JSON Modus</a>
                </div>

                <div class="svgml-selection-card">
                    <span class="dashicons dashicons-edit"></span>
                    <h2>Handmatige invoer</h2>
                    <p>Voer per regio zelf de teksten en statussen in. Perfect voor statische kaarten zonder externe koppeling.</p>
                    <a href="<?php echo wp_nonce_url( admin_url('admin.php?page=svgml-overview&action=create_with_mode&mode=manual'), 'svgml_create_map_with_mode' ); ?>" 
                       class="button button-primary button-large">Kies Handmatige Modus</a>
                </div>
            </div>
            <footer class="svgml-selection-footer">
                <a href="<?php echo admin_url('admin.php?page=svgml-overview'); ?>">← Terug naar overzicht</a>
            </footer>
        </div>
    </div>
    <?php
}