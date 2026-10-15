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
/* ── SVG Map Lite – Standaard aanpasbare stijlen ───────────────────────
   Pas deze CSS aan om het info-panel en de kaart op je eigen site te stylen.
   Alle beschikbare klassen staan in het menu Stijlen & CSS → Klassen Referentie.
──────────────────────────────────────────────────────────────────────── */

/* ── CSS VARIABELEN ─────────────────────────────────────────────────── */
:root {
    --svgml-accent:      #cc0000;     /* Accentkleur (rode knoppen, actieve regio's) */
    --svgml-panel-bg:    #ffffff;     /* Achtergrond van het info-panel */
    --svgml-panel-w:     300px;       /* Breedte van het panel naast de kaart */
    --svgml-thumb-ratio: 56.25%;      /* Verhouding thumbnail (56.25% = 16:9) */

    /* Status kleuren – pas aan voor eigen statuskleuren */
    --svgml-status-available-color: #00a32a;
    --svgml-status-rented-color:    #cc0000;
    --svgml-status-reserved-color:  #f0a500;
}

/* ── INFO-PANEL ─────────────────────────────────────────────────────── */
.svgml-panel {
    background: var(--svgml-panel-bg);
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.12);
    overflow: hidden;
}

/* Panel-titel */
.svgml-panel-title {
    font-size: 14px;
    font-weight: 700;
    color: #1a1a1a;
    letter-spacing: -0.2px;
}

/* ── BLOK: KOPTEKST ─────────────────────────────────────────────────── */
.svgml-heading-value {
    font-size: 16px;
    font-weight: 700;
    color: #1a1a1a;
    line-height: 1.3;
}

/* ── BLOK: PRIJS ────────────────────────────────────────────────────── */
.svgml-price-value {
    font-size: 18px;
    font-weight: 700;
    color: #1a1a1a;
    line-height: 1.2;
}

/* ── BLOK: LINK-KNOP ────────────────────────────────────────────────── */
.svgml-link-btn {
    display: inline-block;
    background: var(--svgml-accent);
    padding: 8px 16px;
    border-radius: 4px;
    font-size: 13px;
    font-weight: 600;
    transition: background 0.15s;
}

.svgml-block-link .svgml-link-btn {
    color: #fff;
    text-decoration: none;
}

.svgml-block-link .svgml-link-btn:hover {
    background: #a80000;
}

/* ── STATUS BADGES ──────────────────────────────────────────────────── */
.svgml-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    background: #e8e8e8;
    color: #444;
}

.svgml-badge.svgml-badge-available,
.svgml-badge-beschikbaar {
    background: #e6f4ea;
    color: #00650d;
}

.svgml-badge.svgml-badge-rented,
.svgml-badge-verhuurd {
    background: #fce8e8;
    color: #8a1515;
}

.svgml-badge.svgml-badge-reserved,
.svgml-badge-gereserveerd {
    background: #fff4e5;
    color: #8a5c00;
}

.svgml-badge.svgml-badge-sold,
.svgml-badge-verkocht {
    background: #f0f0f0;
    color: #555;
}

/* ── OVERZICHT LIJST ────────────────────────────────────────────────── */
.svgml-overview-item {
    border-bottom: 1px solid #f0f0f0;
    cursor: pointer;
    transition: background 0.13s;
}

.svgml-overview-item:hover {
    background: #fdf5f5;
}

.svgml-ov-heading {
    font-weight: 600;
    font-size: 13px;
    color: #1a1a1a;
}

.svgml-ov-price {
    font-size: 12px;
    font-weight: 600;
    color: #333;
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
 * Parset één SVG-bestand uit de mediabibliotheek naar een lijst regio-ID's.
 * Gedeeld door de AJAX-handler hieronder en door de opslag van de SVG-lagen in
 * settings.php, zodat beide exact dezelfde regels gebruiken.
 *
 * @param  int $attachment_id  WP attachment ID van de SVG.
 * @return array|WP_Error      [ 'ids' => string[], 'ignorable' => string[] ] of een WP_Error
 *                             met een Nederlandse foutmelding. 'ignorable' zijn de ID's
 *                             binnen defs/clipPath/mask/gradients/pattern/symbol; die telt de
 *                             uniciteitscheck over lagen niet mee (zie svgml_find_duplicate_ids()).
 */
function svgml_parse_svg_ids( $attachment_id ) {
    // Alleen echte SVG-bijlagen accepteren (het plan eiste dit; de mediakiezer filtert
    // alleen aan de clientkant).
    if ( 'image/svg+xml' !== get_post_mime_type( $attachment_id ) ) {
        return new WP_Error( 'svgml_not_svg', 'Het gekozen bestand is geen SVG-bestand.' );
    }

    $file_path = get_attached_file( $attachment_id );

    if ( ! $file_path || ! file_exists( $file_path ) ) {
        return new WP_Error( 'svgml_missing', 'SVG-bestand niet gevonden op de server.' );
    }

    $svg_content = file_get_contents( $file_path );

    if ( ! $svg_content ) {
        return new WP_Error( 'svgml_empty', 'SVG-bestand is leeg of kon niet worden gelezen.' );
    }

    $svg_xml = simplexml_load_string( $svg_content, 'SimpleXMLElement', LIBXML_NOERROR );

    if ( ! $svg_xml ) {
        return new WP_Error( 'svgml_invalid', 'SVG kon niet worden verwerkt. Is het een geldig SVG-bestand?' );
    }

    $ids = [];
    svgml_extract_ids_from_svg( $svg_xml, $ids );

    if ( empty( $ids ) ) {
        return new WP_Error( 'svgml_no_ids', 'Geen elementen met een id-attribuut gevonden. Voeg id\'s toe aan je SVG-vlakken.' );
    }

    $ignorable = [];
    svgml_extract_defs_ids( $svg_xml, $ignorable );

    return [
        'ids'       => array_values( array_unique( $ids ) ),
        'ignorable' => array_values( array_unique( $ignorable ) ),
    ];
}

/**
 * AJAX: PARSE SVG
 * Extracts ID attributes from an uploaded SVG file
 * Now receives map_id to save IDs to post meta
 *
 * Optionele POST-param 'layer_index' (SVG-lagen): dan wordt er NIETS naar meta
 * geschreven en geeft de handler alleen de ID's terug (voorbeeld/duplicaatcontrole).
 * De definitieve opslag gebeurt bij het opslaan van het Instellingen-formulier.
 * Zonder 'layer_index' werkt de handler exact zoals vroeger.
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
    $is_layer_preview = isset( $_POST['layer_index'] ) && '' !== $_POST['layer_index'];

    if ( ! $attachment_id ) {
        wp_send_json_error( 'Geen geldig attachment ID meegestuurd.' );
        return;
    }

    $parsed = svgml_parse_svg_ids( $attachment_id );

    if ( is_wp_error( $parsed ) ) {
        wp_send_json_error( $parsed->get_error_message() );
        return;
    }

    $ids = $parsed['ids'];

    // Laag-voorbeeld: niets opslaan, alleen terugsturen.
    if ( $is_layer_preview ) {
        wp_send_json_success( [
            'ids'       => $ids,
            'ignorable' => $parsed['ignorable'],
            'count'     => count( $ids ),
        ] );
        return;
    }

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
 * Verzamelt de ID's van elementen die BINNEN een definitie-container staan
 * (defs, clipPath, mask, linearGradient, radialGradient, pattern, symbol),
 * inclusief die container zelf. Dat zijn geen klikbare regio's, maar
 * ontwerptools (Figma/Illustrator) geven ze in elke export dezelfde naam
 * (bijv. "clip0"). Alleen gebruikt door de uniciteitscheck over lagen; de lijst
 * regio-ID's (svgml_extract_ids_from_svg) blijft ongewijzigd.
 *
 * @param SimpleXMLElement $element
 * @param array            $ids      Wordt gevuld (by reference).
 * @param bool             $inside   True zodra we in een definitie-container zitten.
 */
function svgml_extract_defs_ids( $element, &$ids, $inside = false ) {
    $containers = [ 'defs', 'clippath', 'mask', 'lineargradient', 'radialgradient', 'pattern', 'symbol' ];

    // Lokale naam zonder namespace-prefix, kleine letters.
    $name   = strtolower( $element->getName() );
    $inside = $inside || in_array( $name, $containers, true );

    if ( $inside ) {
        $attrs = $element->attributes();
        if ( isset( $attrs['id'] ) ) {
            $id = trim( (string) $attrs['id'] );
            if ( '' !== $id ) $ids[] = $id;
        }
    }

    foreach ( $element->children() as $child ) {
        svgml_extract_defs_ids( $child, $ids, $inside );
    }
}

/**
 * Zoekt ID's die in 2 of meer lagen voorkomen (dezelfde regel als de
 * duplicaat-check bij polygonen in polygon-editor.js: tenzij Multi-View aan
 * staat is een dubbel ID over lagen heen niet toegestaan).
 * ID's uit definitie-containers (zie svgml_extract_defs_ids) tellen niet mee.
 *
 * @param  array $layers  Lijst van [ 'ids' => string[], 'ignorable' => string[] ].
 * @return string[]       De dubbele ID's (leeg = alles uniek).
 */
function svgml_find_duplicate_ids( $layers ) {
    $seen = []; // id => aantal lagen waarin het voorkomt
    foreach ( $layers as $layer ) {
        $relevant = array_diff( $layer['ids'] ?? [], $layer['ignorable'] ?? [] );
        foreach ( array_unique( $relevant ) as $id ) {
            $seen[ $id ] = ( $seen[ $id ] ?? 0 ) + 1;
        }
    }
    return array_keys( array_filter( $seen, function( $n ) { return $n > 1; } ) );
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
        'panel_position'      => 'shortcode',
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
        'panel_bg_color'      => '#ffffff',
        'panel_text_color'    => '#333333',
        'panel_border_radius' => '10',
        'panel_border_color'  => '#cccccc',
        'panel_border_width'  => '0',
        'filter_bg_color'     => '#f5f5f5',
        'filter_text_color'   => '#333333',
        'slider_accent_color' => '#cc0000',
        'input_bg_color'      => '#ffffff',
        'input_text_color'    => '#333333',
        'input_border_color'  => '#cccccc',
        'input_focus_color'   => '#cc0000',
        'status_hex_colors'   => [
            'Beschikbaar' => '#00a32a',
            'Verhuurd'    => '#cc0000',
            'Gereserveerd'=> '#f0a500',
        ],
        'status_colors'       => [
            'Beschikbaar'  => 'beschikbaar',
            'Verhuurd'     => 'verhuurd',
            'Gereserveerd' => 'gereserveerd',
        ],
        'status_opacity'      => [
            'Beschikbaar'  => 100,
            'Verhuurd'     => 100,
            'Gereserveerd' => 100,
        ],
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

/**
 * AJAX: EXPORT PANEL SETTINGS
 * Returns the panel builder meta fields for a given map as JSON.
 */
add_action( 'wp_ajax_svgml_export_panel_settings', 'svgml_ajax_export_panel_settings' );

function svgml_ajax_export_panel_settings() {
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

    $settings = [
        'panel_blocks'          => get_post_meta( $map_id, '_svgml_panel_blocks', true ) ?: [],
        'panel_title'           => get_post_meta( $map_id, '_svgml_panel_title', true ) ?: '',
        'overview_enabled'      => get_post_meta( $map_id, '_svgml_overview_enabled', true ) ?: '',
        'overview_blocks'       => get_post_meta( $map_id, '_svgml_overview_blocks', true ) ?: [],
        'overview_sort_field'   => get_post_meta( $map_id, '_svgml_overview_sort_field', true ) ?: '',
        'overview_sort_order'   => get_post_meta( $map_id, '_svgml_overview_sort_order', true ) ?: 'asc',
        'overview_clickable'    => get_post_meta( $map_id, '_svgml_overview_clickable', true ) ?: '',
    ];

    wp_send_json_success( [ 'settings' => $settings ] );
}

/**
 * AJAX: IMPORT PANEL SETTINGS
 * Applies exported panel settings JSON to a given map.
 */
add_action( 'wp_ajax_svgml_import_panel_settings', 'svgml_ajax_import_panel_settings' );

function svgml_ajax_import_panel_settings() {
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

    $raw = stripslashes( $_POST['settings'] ?? '' );
    $settings = json_decode( $raw, true );

    if ( ! is_array( $settings ) || ! isset( $settings['panel_blocks'] ) ) {
        wp_send_json_error( 'Ongeldig instellingenbestand.' );
        return;
    }

    $valid_types  = [ 'thumbnail', 'heading', 'badge', 'price', 'text', 'html', 'link', 'divider', 'static_html', 'static_button' ];
    $valid_widths = [ 25, 33, 50, 75, 100 ];

    $blocks = [];
    foreach ( (array) $settings['panel_blocks'] as $b ) {
        if ( ! is_array( $b ) ) continue;
        $type  = sanitize_text_field( $b['type'] ?? 'text' );
        $width = intval( $b['width'] ?? 100 );
        if ( ! in_array( $type, $valid_types, true ) ) continue;
        if ( ! in_array( $width, $valid_widths, true ) ) $width = 100;
        $blocks[] = [
            'field'        => sanitize_text_field( $b['field'] ?? '' ),
            'type'         => $type,
            'label'        => sanitize_text_field( $b['label'] ?? '' ),
            'width'        => $width,
            'html'         => ! empty( $b['html'] ),
            'static_value' => wp_kses_post( $b['static_value'] ?? '' ),
            'prefix'       => sanitize_text_field( $b['prefix'] ?? '' ),
            'suffix'       => sanitize_text_field( $b['suffix'] ?? '' ),
            'label_layout' => in_array( $b['label_layout'] ?? '', [ 'block', 'inline' ] ) ? $b['label_layout'] : 'block',
        ];
    }

    $overview_blocks = [];
    foreach ( (array) ( $settings['overview_blocks'] ?? [] ) as $ob ) {
        if ( ! is_array( $ob ) ) continue;
        $type = sanitize_text_field( $ob['type'] ?? 'text' );
        if ( ! in_array( $type, [ 'heading', 'badge', 'price', 'text', 'link' ], true ) ) continue;
        $overview_blocks[] = [
            'field' => sanitize_text_field( $ob['field'] ?? '' ),
            'type'  => $type,
            'label' => sanitize_text_field( $ob['label'] ?? '' ),
            'html'  => ! empty( $ob['html'] ),
        ];
    }

    // Bekende beperking (bewust niet volledig opgelost, zie projectplan): op een
    // manual-mode kaart kent svgml_ensure_manual_block_keys() een verse, unieke
    // sleutel toe aan elk geïmporteerd blok zonder 'field' — dat dekt het geval
    // waarin de export-JSON, net als het manual-mode-formulier zelf, 'field'
    // leeg liet. Bevat de geïmporteerde JSON echter al een NIET-lege 'field'
    // (bijv. een export van een andere, zelf al gemigreerde kaart), dan blijft
    // die sleutel letterlijk staan — svgml_ensure_manual_block_keys() raakt
    // niet-lege sleutels bewust nooit aan. Zo'n vreemde-kaart-sleutel matcht dan
    // niets in de lokale '_svgml_manual_data'. Het herkoppelen daarvan wordt
    // hier niet gebouwd.
    if ( 'manual' === ( get_post_meta( $map_id, '_svgml_map_mode', true ) ?: 'json' ) ) {
        $blocks = svgml_ensure_manual_block_keys( $blocks, $map_id );
    }

    update_post_meta( $map_id, '_svgml_panel_blocks',        $blocks );
    update_post_meta( $map_id, '_svgml_panel_title',         sanitize_text_field( $settings['panel_title'] ?? '' ) );
    update_post_meta( $map_id, '_svgml_overview_enabled',    ! empty( $settings['overview_enabled'] ) ? '1' : '' );
    update_post_meta( $map_id, '_svgml_overview_blocks',     $overview_blocks );
    update_post_meta( $map_id, '_svgml_overview_sort_field', sanitize_text_field( $settings['overview_sort_field'] ?? '' ) );
    update_post_meta( $map_id, '_svgml_overview_sort_order', in_array( $settings['overview_sort_order'] ?? '', [ 'asc', 'desc' ] ) ? $settings['overview_sort_order'] : 'asc' );
    update_post_meta( $map_id, '_svgml_overview_clickable',  ! empty( $settings['overview_clickable'] ) ? '1' : '' );

    wp_send_json_success();
}

/**
 * AJAX: DUPLICATE MAP
 * Maakt een volledige kopie van een kaart: alle _svgml_-meta (inclusief
 * afbeelding, vlakken, koppeling, uitsluitingen en regiodata) gaat mee.
 * Alleen de titel verschilt ("Kopie van …").
 */
add_action( 'wp_ajax_svgml_duplicate_map', 'svgml_ajax_duplicate_map' );

function svgml_ajax_duplicate_map() {

    check_ajax_referer( 'svgml_admin_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen rechten.' );
        return;
    }

    $source_id = intval( $_POST['map_id'] ?? 0 );
    if ( ! $source_id ) {
        wp_send_json_error( 'Geen kaart ID opgegeven.' );
        return;
    }

    $source = get_post( $source_id );
    if ( ! $source || $source->post_type !== 'svgml_map' ) {
        wp_send_json_error( 'Kaart niet gevonden.' );
        return;
    }

    $new_id = wp_insert_post( [
        'post_type'   => 'svgml_map',
        'post_status' => 'publish',
        'post_title'  => 'Kopie van ' . $source->post_title,
    ] );

    if ( ! $new_id || is_wp_error( $new_id ) ) {
        wp_send_json_error( 'Kon de kaart niet aanmaken.' );
        return;
    }

    // ── Alle _svgml_-meta 1-op-1 kopiëren ──────────────────────────────────
    // Sinds 2.5.4 wordt een duplicaat een VOLLEDIGE kopie: afbeelding/SVG,
    // lagen en getekende vlakken, regio-ID's, koppeling, uitsluitingen en
    // handmatige regiodata komen allemaal mee.
    //
    // Tot en met 2.5.3 stond hier een $clear_keys-lijst die die velden juist
    // leegmaakte. Die redenering ("attachment-ID's zijn niet geldig") hoort bij
    // IMPORT vanaf een andere site — daar verwijst een attachment-ID naar een
    // bestand dat op deze site niet bestaat. Dupliceren gebeurt altijd binnen
    // dezelfde site, dus de ID's zijn hier gewoon geldig. Het leegmaken leverde
    // een vrijwel lege, onbruikbare kaart op.
    //
    // Let op: includes/import-export.php (svgml_handle_import) heeft zijn
    // EIGEN clear-logica. Die blijft bewust staan en deelt geen code met dit.
    //
    // Ook '_svgml_manual_keys_v' (de migratievlag uit manual-field-keys.php)
    // komt via deze prefix-loop mee. Dat is nodig: de gekopieerde blokken
    // hebben al stabiele sleutels, dus de migratie mag op het duplicaat niet
    // opnieuw draaien.
    //
    // Gedeelde referenties zijn er niet: get_post_meta() geeft een kopie van
    // de waarde terug en update_post_meta() schrijft een eigen rij voor
    // $new_id. Een vlak verslepen of een stijl aanpassen in het duplicaat
    // raakt het origineel dus niet. (De afbeelding zelf is wél hetzelfde
    // mediabestand — dat is precies de bedoeling.)
    $all_meta = get_post_meta( $source_id );
    foreach ( $all_meta as $key => $values ) {
        // Alleen meta van deze plugin; WP-interne meta (_edit_lock e.d.) niet.
        if ( strpos( $key, '_svgml_' ) !== 0 ) {
            continue;
        }
        // get_post_meta() zonder key geeft geserialiseerde strings terug;
        // maybe_unserialize() maakt er weer arrays van.
        $value = maybe_unserialize( $values[0] );
        // update_post_meta() haalt intern backslashes weg (wp_unslash). Nu ook
        // regiodata en vlakken meekomen, zou een '\' in tekst of CSS daardoor
        // verdwijnen. wp_slash() vooraf zorgt dat de waarde exact gelijk blijft.
        update_post_meta( $new_id, $key, wp_slash( $value ) );
    }

    wp_send_json_success( [
        'redirect' => admin_url( 'admin.php?page=svgml-settings&map_id=' . $new_id ),
    ] );
}

// ─────────────────────────────────────────────────────────────────────────────
// TOGGLE EXCLUDE (regio uitsluiten/herstellen in "Data per vlak")
// ─────────────────────────────────────────────────────────────────────────────
// Sinds 2.5.3 loopt dit via AJAX. Voorheen postte de knop in de regio-lijst
// (includes/mapping.php) via een eigen <form method="post"> naar dezelfde
// pagina, wat bij elke klik de volledige pagina herlaadde — bij dertig
// vlakken achter elkaar uitsluiten dus dertig herladingen. De knop is nu een
// gewoon <button type="button"> (zie mapping.php), en assets/js/admin.js
// stuurt de klik hierheen. De server-side logica hieronder is verder
// ongewijzigd t.o.v. de oude POST-afhandeling: _svgml_excluded_ids ophalen,
// het svg-id erin of eruit halen, en wegschrijven — de vorm van deze meta
// verandert dus niet.
add_action( 'wp_ajax_svgml_toggle_exclude', 'svgml_ajax_toggle_exclude' );

function svgml_ajax_toggle_exclude() {

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

    $svg_id = sanitize_text_field( $_POST['svg_id'] ?? '' );
    if ( '' === $svg_id ) {
        wp_send_json_error( 'Geen regio ID opgegeven.' );
        return;
    }

    $excluded = get_post_meta( $map_id, '_svgml_excluded_ids', true ) ?: [];
    if ( ! is_array( $excluded ) ) {
        $excluded = [];
    }

    if ( in_array( $svg_id, $excluded, true ) ) {
        // Stond al in de lijst → weer terugzetten (niet meer uitgesloten).
        $excluded    = array_values( array_diff( $excluded, [ $svg_id ] ) );
        $is_excluded = false;
    } else {
        // Stond er nog niet in → nu uitsluiten.
        $excluded[]  = $svg_id;
        $is_excluded = true;
    }

    update_post_meta( $map_id, '_svgml_excluded_ids', $excluded );

    // Zelfde cache-invalidatie als bij andere wijzigingen die de gerenderde
    // frontend-HTML of de JSON-cache van deze kaart raken.
    delete_transient( 'svgml_html_' . $map_id );
    delete_transient( 'svgml_json_cache_' . $map_id );

    // Stuur terug of de regio nu wél/niet uitgesloten is, zodat de JS weet
    // welke classes/tekst het op de knop en het regio-item moet zetten.
    wp_send_json_success( [
        'excluded' => $is_excluded,
    ] );
}
