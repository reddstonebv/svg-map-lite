<?php
/**
 * SVG Map Lite - AJAX Handlers
 * Endpoints for admin operations (CSS, SVG parsing, image URLs)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AJAX: GET DEFAULT CSS
 * Returns the built-in default CSS
 */
add_action( 'wp_ajax_svgml_get_default_css', 'svgml_ajax_get_default_css' );

function svgml_ajax_get_default_css() {
    check_ajax_referer( 'svgml_admin_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen rechten.' );
        return;
    }
    $default_css = <<<'CSS'
/* ── SVG Map Lite – Standaard panel-stijlen ────────────────────────────
   Pas deze CSS aan om het info-panel en de kaart op je eigen site te stylen.
   Alle beschikbare klassen staan in het menu Stijlen & CSS → Klassen Referentie.
──────────────────────────────────────────────────────────────────────── */

/* Accentkleur (knoppen, actieve regio rand, range-slider) */
:root {
    --svgml-accent: #cc0000;
}

/* Info-panel achtergrond en breedte */
.svgml-panel {
    background: #ffffff;
    border-radius: 10px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.10);
    overflow: hidden;
}

/* Panel-titel */
.svgml-panel-title {
    font-size: 14px;
    font-weight: 700;
    color: #2b2b2b;
    padding: 14px 18px 0;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

/* Koptekst-blok */
.svgml-block-heading h3 {
    font-size: 16px;
    font-weight: 700;
    margin: 0;
    color: #1a1a1a;
}

/* Prijs-blok */
.svgml-block-price .svgml-price {
    font-size: 18px;
    font-weight: 700;
    color: #1a1a1a;
}

/* Link-knop */
.svgml-block-link a {
    display: inline-block;
    background: var(--svgml-accent);
    color: #fff;
    padding: 8px 20px;
    border-radius: 50px;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
}

/* Overzicht-rij (lijst van alle objecten) */
.svgml-overview-item {
    border-bottom: 1px solid #f0f0f0;
    cursor: pointer;
    transition: background 0.15s;
}
.svgml-overview-item:hover {
    background: #fdf5f5;
}
CSS;
    wp_send_json_success( [ 'css' => $default_css ] );
}

/**
 * AJAX: GET IMAGE URL
 * Returns the URL of an attachment by ID
 */
add_action( 'wp_ajax_svgml_get_image_url', 'svgml_ajax_get_image_url' );

function svgml_ajax_get_image_url() {
    check_ajax_referer( 'svgml_admin_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen rechten.' );
        return;
    }

    $attachment_id = intval( $_POST['attachment_id'] ?? 0 );
    if ( ! $attachment_id ) {
        wp_send_json_error( 'Geen geldig attachment ID.' );
        return;
    }

    $url = wp_get_attachment_url( $attachment_id );
    if ( ! $url ) {
        wp_send_json_error( 'Afbeelding niet gevonden.' );
        return;
    }

    wp_send_json_success( [ 'url' => $url ] );
}

/**
 * AJAX: PARSE SVG
 * Extracts ID attributes from an uploaded SVG file
 * Now receives map_id to save IDs to post meta
 */
add_action( 'wp_ajax_svgml_parse_svg', 'svgml_ajax_parse_svg' );

function svgml_ajax_parse_svg() {

    check_ajax_referer( 'svgml_admin_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen rechten om dit uit te voeren.' );
        return;
    }

    $attachment_id = intval( $_POST['attachment_id'] ?? 0 );
    $map_id = intval( $_POST['map_id'] ?? 0 );

    if ( ! $attachment_id ) {
        wp_send_json_error( 'Geen geldig attachment ID meegestuurd.' );
        return;
    }

    $file_path = get_attached_file( $attachment_id );

    if ( ! $file_path || ! file_exists( $file_path ) ) {
        wp_send_json_error( 'SVG-bestand niet gevonden op de server.' );
        return;
    }

    $svg_content = file_get_contents( $file_path );

    if ( ! $svg_content ) {
        wp_send_json_error( 'SVG-bestand is leeg of kon niet worden gelezen.' );
        return;
    }

    $svg_xml = simplexml_load_string( $svg_content, 'SimpleXMLElement', LIBXML_NOERROR );

    if ( ! $svg_xml ) {
        wp_send_json_error( 'SVG kon niet worden verwerkt. Is het een geldig SVG-bestand?' );
        return;
    }

    $ids = [];
    svgml_extract_ids_from_svg( $svg_xml, $ids );

    if ( empty( $ids ) ) {
        wp_send_json_error( 'Geen elementen met een id-attribuut gevonden. Voeg id\'s toe aan je SVG-vlakken.' );
        return;
    }

    $ids = array_unique( $ids );
    $ids = array_values( $ids );

    // Save to post meta if map_id provided, otherwise use options (for backward compat)
    if ( $map_id ) {
        update_post_meta( $map_id, '_svgml_svg_attachment_id', $attachment_id );
        update_post_meta( $map_id, '_svgml_svg_ids', $ids );
    } else {
        update_option( 'svgml_svg_attachment_id', $attachment_id );
        update_option( 'svgml_svg_ids', $ids );
    }

    wp_send_json_success( [
        'ids'   => $ids,
        'count' => count( $ids ),
    ] );
}

/**
 * Helper function: extract ID attributes from SVG recursively
 */
function svgml_extract_ids_from_svg( $element, &$ids ) {

    $attrs = $element->attributes();

    if ( isset( $attrs['id'] ) ) {
        $id = trim( (string) $attrs['id'] );

        $skip = [ '', 'svg', 'svg1', 'layer1', 'Layer_1' ];

        if ( ! empty( $id ) && ! in_array( $id, $skip ) ) {
            $ids[] = $id;
        }
    }

    foreach ( $element->children() as $child ) {
        svgml_extract_ids_from_svg( $child, $ids );
    }
}

/**
 * AJAX: CREATE MAP
 * Creates a new svgml_map CPT post with default meta values
 */
add_action( 'wp_ajax_svgml_create_map', 'svgml_ajax_create_map' );

function svgml_ajax_create_map() {

    check_ajax_referer( 'svgml_admin_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen rechten.' );
        return;
    }

    // Create new post
    $map_id = wp_insert_post( [
        'post_type'   => 'svgml_map',
        'post_status' => 'publish',
        'post_title'  => 'Nieuwe kaart',
    ] );

    if ( is_wp_error( $map_id ) ) {
        wp_send_json_error( 'Kon kaart niet aanmaken: ' . $map_id->get_error_message() );
        return;
    }

    // Set default meta values
    $defaults = [
        'source_type'         => 'svg',
        'svg_attachment_id'   => '',
        'image_attachment_id' => '',
        'polygons'            => [],
        'json_url'            => '',
        'id_mapping'          => [],
        'display_fields'      => [],
        'panel_position'      => 'right',
        'panel_title'         => '',
        'json_id_field'       => 'id',
        'panel_blocks'        => [],
        'filter_fields'       => [],
        'status_field'        => '',
        'status_colors'       => [],
        'status_hex_colors'   => [],
        'status_opacity'      => [],
        'overview_enabled'    => false,
        'overview_blocks'     => [],
        'json_array_key'      => '',
        'layers'              => [],
        'layer_switcher'      => 'buttons',
        'excluded_ids'        => [],
        'poly_stroke_color'   => '#2a9d8f',
        'poly_stroke_width'   => '1',
        'filter_match_color'  => '',
        'filter_dim_color'    => '',
    ];

    foreach ( $defaults as $key => $value ) {
        update_post_meta( $map_id, '_svgml_' . $key, $value );
    }

    $edit_url = admin_url( 'post.php?post=' . $map_id . '&action=edit' );

    wp_send_json_success( [
        'map_id'   => $map_id,
        'edit_url' => $edit_url,
    ] );
}

/**
 * AJAX: DELETE MAP
 * Deletes a svgml_map post
 */
add_action( 'wp_ajax_svgml_delete_map', 'svgml_ajax_delete_map' );

function svgml_ajax_delete_map() {

    check_ajax_referer( 'svgml_admin_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen rechten.' );
        return;
    }

    $map_id = intval( $_POST['map_id'] ?? 0 );
    if ( ! $map_id ) {
        wp_send_json_error( 'Geen kaart ID opgegeven.' );
        return;
    }

    $result = wp_delete_post( $map_id, true ); // true = skip trash

    if ( ! $result ) {
        wp_send_json_error( 'Kon kaart niet verwijderen.' );
        return;
    }

    wp_send_json_success( [
        'message' => 'Kaart verwijderd',
    ] );
}
