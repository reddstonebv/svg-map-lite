<?php
/**
 * SVG Map Lite – Import / Export
 * Handles map configuration export (JSON download) and import (file upload).
 * Both actions run on admin_init before any HTML output.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── EXPORT ────────────────────────────────────────────────────────────────────
add_action( 'admin_init', 'svgml_handle_export' );

function svgml_handle_export() {
    if ( ! isset( $_GET['page'], $_GET['action'], $_GET['map_id'] ) ) {
        return;
    }
    if ( $_GET['page'] !== 'svgml-overview' || $_GET['action'] !== 'export' ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $map_id = intval( $_GET['map_id'] );

    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'svgml_export_map_' . $map_id ) ) {
        wp_die( 'Beveiligingsfout.' );
    }

    $post = get_post( $map_id );
    if ( ! $post || $post->post_type !== 'svgml_map' ) {
        wp_die( 'Kaart niet gevonden.' );
    }

    // Collect all _svgml_* meta
    $all_meta = get_post_meta( $map_id );
    $meta     = [];

    foreach ( $all_meta as $key => $values ) {
        if ( strpos( $key, '_svgml_' ) !== 0 ) {
            continue;
        }
        // get_post_meta returns serialized arrays as single-element array of the unserialized value
        $meta[ $key ] = maybe_unserialize( $values[0] );
    }

    // Annotate media IDs with their URLs for reference (IDs differ between servers)
    $svg_id = $meta['_svgml_svg_attachment_id'] ?? '';
    if ( $svg_id ) {
        $meta['_svgml_svg_attachment_url'] = wp_get_attachment_url( $svg_id ) ?: '';
    }

    $img_id = $meta['_svgml_image_attachment_id'] ?? '';
    if ( $img_id ) {
        $meta['_svgml_image_attachment_url'] = wp_get_attachment_url( $img_id ) ?: '';
    }

    // Annotate layer image URLs without mutating attachment IDs
    if ( ! empty( $meta['_svgml_layers'] ) && is_array( $meta['_svgml_layers'] ) ) {
        foreach ( $meta['_svgml_layers'] as &$layer ) {
            $layer_img_id = $layer['image_attachment_id'] ?? '';
            if ( $layer_img_id ) {
                $layer['image_attachment_url'] = wp_get_attachment_url( $layer_img_id ) ?: '';
            }
        }
        unset( $layer );
    }

    $payload = [
        'svgml_export' => true,
        'version'      => SVGML_VERSION,
        'title'        => $post->post_title,
        'meta'         => $meta,
    ];

    $slug     = sanitize_title( $post->post_title ) ?: $map_id;
    $filename = 'svgml-export-' . $slug . '.json';
    $json     = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

    nocache_headers();
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Content-Length: ' . strlen( $json ) );

    echo $json;
    exit;
}

// ── IMPORT ────────────────────────────────────────────────────────────────────
add_action( 'admin_init', 'svgml_handle_import' );

function svgml_handle_import() {
    if ( ! isset( $_GET['page'], $_GET['action'] ) ) {
        return;
    }
    if ( $_GET['page'] !== 'svgml-overview' || $_GET['action'] !== 'import' ) {
        return;
    }
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'svgml_import_map' ) ) {
        wp_die( 'Beveiligingsfout.' );
    }

    if ( ! isset( $_FILES['svgml_import_file'] ) || $_FILES['svgml_import_file']['error'] !== UPLOAD_ERR_OK ) {
        wp_die( 'Bestandsupload mislukt. Controleer het bestand en probeer opnieuw.' );
    }

    $tmp  = $_FILES['svgml_import_file']['tmp_name'];
    $raw  = file_get_contents( $tmp );
    $data = json_decode( $raw, true );

    if ( ! is_array( $data ) || empty( $data['svgml_export'] ) || ! isset( $data['meta'] ) || ! is_array( $data['meta'] ) ) {
        wp_die( 'Ongeldig exportbestand. Zorg dat je een SVG Map Lite exportbestand gebruikt.' );
    }

    $title  = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) . ' - Import' : 'Geïmporteerde kaart';
    $new_id = wp_insert_post( [
        'post_type'   => 'svgml_map',
        'post_status' => 'publish',
        'post_title'  => $title,
    ] );

    if ( ! $new_id || is_wp_error( $new_id ) ) {
        wp_die( 'Kon de kaart niet aanmaken.' );
    }

    // Keys to skip during import (annotation-only, not real meta)
    $skip_keys = [
        '_svgml_svg_attachment_url',
        '_svgml_image_attachment_url',
    ];

    // Keys whose values must be cleared (IDs are environment-specific)
    $clear_keys = [
        '_svgml_svg_attachment_id',
        '_svgml_image_attachment_id',
    ];

    foreach ( $data['meta'] as $key => $value ) {
        if ( ! is_string( $key ) || strpos( $key, '_svgml_' ) !== 0 ) {
            continue;
        }
        if ( in_array( $key, $skip_keys, true ) ) {
            continue;
        }
        if ( in_array( $key, $clear_keys, true ) ) {
            update_post_meta( $new_id, $key, '' );
            continue;
        }
        update_post_meta( $new_id, $key, $value );
    }

    // Clear layer image attachment IDs (environment-specific)
    $layers = get_post_meta( $new_id, '_svgml_layers', true );
    if ( is_array( $layers ) ) {
        foreach ( $layers as &$layer ) {
            $layer['image_attachment_id'] = '';
            unset( $layer['image_attachment_url'] );
        }
        unset( $layer );
        update_post_meta( $new_id, '_svgml_layers', $layers );
    }

    wp_redirect( admin_url( 'admin.php?page=svgml-settings&map_id=' . $new_id . '&imported=1' ) );
    exit;
}

// ── ADMIN NOTICE ──────────────────────────────────────────────────────────────
add_action( 'admin_notices', 'svgml_import_success_notice' );

function svgml_import_success_notice() {
    if ( ! isset( $_GET['imported'] ) || $_GET['imported'] !== '1' ) {
        return;
    }
    $page = $_GET['page'] ?? '';
    if ( strpos( $page, 'svgml-' ) !== 0 ) {
        return;
    }
    echo '<div class="notice notice-success is-dismissible"><p>'
        . esc_html__( 'Kaart succesvol geïmporteerd. Koppel je SVG/Afbeelding opnieuw.', 'svg-map-lite' )
        . '</p></div>';
}
