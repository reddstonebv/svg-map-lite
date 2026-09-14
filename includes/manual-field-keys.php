<?php
/**
 * Stabiele veldsleutels voor manual-mode panelblokken.
 *
 * ── WAAROM DIT BESTAND BESTAAT ──────────────────────────────────────────────
 * In manual-modus ('_svgml_map_mode' === 'manual') werden panelvelden vroeger
 * uitsluitend geïdentificeerd door hun POSITIE in de '_svgml_panel_blocks'-array:
 * de sleutel was altijd 'manual_field_' . $i. Zodra iemand in de Panel Builder
 * een blok versleepte of verwijderde, verschoven alle indices — en daarmee
 * werden stilzwijgend ALLE opgeslagen waarden (regiodata, filterkoppeling,
 * overzichtsveld) aan het verkeerde blok gekoppeld. Geen foutmelding, en na de
 * eerstvolgende opslag onomkeerbaar.
 *
 * De oplossing: elk manual-blok krijgt een stabiele, positie-onafhankelijke
 * sleutel, opgeslagen in de al bestaande 'field'-property van het blok (die
 * stond in manual-modus altijd op '' — er komt dus geen nieuwe property bij,
 * alleen een gevulde waarde in een bestaande).
 *
 * Dit bestand bundelt alle logica die met die sleutels te maken heeft, zodat
 * de vier plekken die er in het project mee werken (mapping.php, filters.php,
 * panel-builder.php, en de importers in ajax.php/ai-assistant.php) niet stuk
 * voor stuk hun eigen variant kunnen bouwen die uit elkaar loopt.
 *
 * ── WAAROM DIT BESTAND ALTIJD (ONVOORWAARDELIJK) GELADEN MOET ZIJN ─────────
 * De migratiefunctie hieronder draait op de 'admin_init'-hook, en dat vuurt
 * VOORDAT WordPress de render-callback van welke editor-tab dan ook aanroept
 * (zie svg-map-lite.php, svgml_render_editor_wrapper() en het bestaande
 * svgml_handle_overview_actions()-patroon op dezelfde hook). De tab-bestanden
 * zelf (mapping.php, filters.php, panel-builder.php, ...) worden pas lazy
 * geladen op het moment dat hun tab daadwerkelijk gerenderd wordt. Dit bestand
 * moet dus in het onvoorwaardelijke require_once-blok van svg-map-lite.php
 * staan — vóór includes/ajax.php, omdat ajax.php's import-handler de hulp-
 * functie svgml_ensure_manual_block_keys() hieronder aanroept.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// LEZEN: sleutel van één blok opvragen, met permanente positionele fallback
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Geeft de datasleutel van een manual-mode panelblok terug.
 *
 * Gebruikt de sleutel in $block['field'] als die er is (het normale geval na
 * migratie/opslaan met deze versie). Is 'field' leeg — een kaart die nog nooit
 * gemigreerd is, of blokken die via een importer binnenkwamen zonder sleutel —
 * dan valt deze functie terug op de OUDE, positionele sleutel 'manual_field_'.$i.
 *
 * BELANGRIJK: deze fallback blijft PERMANENT bestaan, ook lang nadat de
 * migratie-functie hieronder gedraaid heeft. Hij is de enige reden dat kaarten
 * die nooit (meer) bezocht worden — bijvoorbeeld op een read-only site, of een
 * site die deze plugin-update nooit binnenkrijgt via een handmatig beheerde
 * kopie — gewoon blijven werken zoals voorheen. Verwijder deze fallback dus
 * NOOIT, ook niet als de migratie "voltooid" lijkt: er is geen garantie dat
 * elke kaart ooit door de migratie is gelopen.
 *
 * @param array $block Eén element uit '_svgml_panel_blocks'.
 * @param int   $index De array-index van dat blok (gebruikt als fallback).
 * @return string De datasleutel waarmee dit blok's waarde is/wordt opgeslagen.
 */
function svgml_get_manual_field_key( $block, $index ) {
    return ! empty( $block['field'] ) ? $block['field'] : ( 'manual_field_' . $index );
}

// ─────────────────────────────────────────────────────────────────────────────
// SCHRIJVEN: nieuwe, unieke sleutel genereren
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Genereert een nieuwe, unieke manual-mode veldsleutel.
 *
 * Formaat: 'mf_' gevolgd door 8 hexadecimale tekens, dus uitsluitend de
 * tekens [a-z0-9_]. Dat is een harde eis, geen stijlkeuze:
 * - assets/js/filters.js bouwt er een DOM-id mee: $('#svgml-select-' + field).
 *   Die concatenatie is NIET CSS.escape()-veilig, dus de sleutel moet zelf al
 *   een geldig, veilig id-fragment zijn.
 * - includes/frontend.php haalt de opgeslagen filterwaarde door sanitize_key()
 *   (kleine letters, alleen a-z0-9_-) voor het de bijbehorende frontend-markup
 *   bouwt. Verandert sanitize_key() de sleutel, dan matcht niets meer. Hex in
 *   kleine letters overleeft sanitize_key() gegarandeerd ongewijzigd.
 *
 * @param array $bestaande_sleutels Sleutels die al in gebruik zijn (binnen
 *                                  dezelfde kaart/array) — nieuwe sleutel mag
 *                                  daar niet mee botsen.
 * @return string Een nieuwe sleutel, gegarandeerd niet voorkomend in $bestaande_sleutels.
 */
function svgml_generate_unique_manual_field_key( array $bestaande_sleutels ) {
    do {
        try {
            // random_bytes() kan in zeldzame gevallen (entropieproblemen) een
            // Exception gooien. Dit draait via de admin_init-hook, dus een
            // fatal error hier zou heel wp-admin platleggen — vandaar de
            // try/catch met een altijd-werkende fallback.
            $key = 'mf_' . bin2hex( random_bytes( 4 ) ); // 8 hex-tekens, [a-z0-9_]
        } catch ( Exception $e ) {
            // uniqid() met more_entropy geeft iets als "68f1a2b3c4d5e6.12345678";
            // de punt eruit halen zodat alleen [a-z0-9] overblijft.
            $key = 'mf_' . str_replace( '.', '', uniqid( '', true ) );
        }
    } while ( in_array( $key, $bestaande_sleutels, true ) );

    return $key;
}

/**
 * Kent aan elk manual-mode panelblok zonder sleutel een nieuwe, unieke sleutel toe.
 *
 * Blokken die al een niet-lege 'field'-waarde hebben blijven volledig ongemoeid
 * (ook als die sleutel toevallig van een andere kaart afkomstig is, bijvoorbeeld
 * via een import — zie de "bekende beperking" in het projectplan; dat wordt hier
 * bewust niet opgelost).
 *
 * Dit is de ENIGE plek die nieuwe sleutels toekent bij het opslaan van
 * '_svgml_panel_blocks'. Wordt aangeroepen vanuit:
 * - includes/panel-builder.php (beide opslagpaden: JSON en fallback parallel-arrays)
 * - includes/ajax.php (svgml_ajax_import_panel_settings(), bij import op een manual-kaart)
 * - includes/ai-assistant.php (panel_config-import, bij import op een manual-kaart)
 *
 * @param array $blocks De op te slaan '_svgml_panel_blocks'-array.
 * @param int   $map_id De kaart-ID (nog niet gebruikt binnen de functie zelf —
 *                       de uniciteitscheck werkt puur binnen $blocks — maar wel
 *                       meegegeven zodat toekomstige uitbreidingen, zoals logging
 *                       of een uniciteitscheck over meerdere kaarten heen, hier
 *                       niet alsnog de aanroepsignatuur hoeven te wijzigen).
 * @return array De bijgewerkte $blocks-array.
 */
function svgml_ensure_manual_block_keys( array $blocks, $map_id ) {
    $used_keys = [];
    foreach ( $blocks as $block ) {
        if ( ! empty( $block['field'] ) ) {
            $used_keys[] = $block['field'];
        }
    }

    foreach ( $blocks as &$block ) {
        if ( empty( $block['field'] ) ) {
            $new_key            = svgml_generate_unique_manual_field_key( $used_keys );
            $used_keys[]        = $new_key;
            $block['field']     = $new_key;
        }
    }
    unset( $block );

    return $blocks;
}

// ─────────────────────────────────────────────────────────────────────────────
// EENMALIGE MIGRATIE PER KAART
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Migreert een manual-mode kaart van positionele naar stabiele veldsleutels.
 *
 * Draait maar één keer per kaart (bewaakt door de '_svgml_manual_keys_v'-vlag).
 * Kent aan elk panelblok zonder sleutel een nieuwe stabiele sleutel toe, en
 * herschrijft vervolgens ALLE plekken die tot nu toe de oude positionele
 * sleutel 'manual_field_' . $i gebruikten, met behoud van waarde én volgorde:
 * - '_svgml_manual_data'    (per regio de veldsleutel-subarray)
 * - '_svgml_filter_fields'  ([].field)
 * - '_svgml_overview_blocks' ([].field)
 *
 * INVARIANT (geldt na een geslaagde migratie): in '_svgml_manual_data' komt
 * geen enkele 'manual_field_*'-sleutel meer voor — elke sleutel is dan
 * 'mf_...'. Dat is precies de reden dat de positionele fallback in
 * svgml_get_manual_field_key() (en de gelijknamige fallback in
 * assets/js/panel-renderer.js) nooit kan botsen met een gemigreerde kaart:
 * zodra 'field' gevuld is — en dat is na migratie altijd het geval — wordt de
 * fallback simpelweg nooit meer aangeroepen voor die kaart.
 *
 * Omdat 'field' in manual-modus vóór deze plugin-versie ALTIJD leeg was
 * (nergens in de oude code werd hij gevuld), geldt op een nog niet gemigreerde
 * kaart: als er iets te migreren valt, dan geldt dat voor ELK blok zonder
 * uitzondering (ook dividers — die kregen in de oude code ook al een eigen
 * manual_field_{i}-slot, ook al gebruikt panel-renderer.js divider-blokken
 * niet). Er bestaat dus geen praktisch scenario met een mix van oude en al-
 * stabiele sleutels op een nog niet eerder gemigreerde kaart.
 *
 * @param int $map_id
 * @return void
 */
function svgml_migrate_manual_block_keys( $map_id ) {
    $map_mode = get_post_meta( $map_id, '_svgml_map_mode', true ) ?: 'json';
    if ( 'manual' !== $map_mode ) {
        return; // JSON-modus gebruikt deze positionele sleutels nooit.
    }

    if ( get_post_meta( $map_id, '_svgml_manual_keys_v', true ) ) {
        return; // Al eerder gemigreerd — snelle vlag-check, geen her-scan nodig.
    }

    $blocks = get_post_meta( $map_id, '_svgml_panel_blocks', true );
    if ( ! is_array( $blocks ) || empty( $blocks ) ) {
        update_post_meta( $map_id, '_svgml_manual_keys_v', 1 ); // Niets te migreren.
        return;
    }

    // Al gemigreerd (bijv. via een eerdere opslag met deze plugin-versie, vóórdat
    // de vlag gezet werd)? Dan zet ieder blok al een eigen sleutel — niets te doen.
    $needs_migration = false;
    foreach ( $blocks as $block ) {
        if ( empty( $block['field'] ) ) {
            $needs_migration = true;
            break;
        }
    }
    if ( ! $needs_migration ) {
        update_post_meta( $map_id, '_svgml_manual_keys_v', 1 );
        return;
    }

    // Ken nieuwe sleutels toe en bouw tegelijk de hernoemtabel op. Alleen
    // blokken die HIER daadwerkelijk een nieuwe sleutel krijgen leveren een
    // hernoem-entry op — blokken die al een sleutel hadden blijven ongemoeid
    // en hebben dus ook niets om te hernoemen.
    $existing_keys = [];
    foreach ( $blocks as $block ) {
        if ( ! empty( $block['field'] ) ) {
            $existing_keys[] = $block['field'];
        }
    }

    $rename_table = []; // 'manual_field_{i}' => nieuwe stabiele sleutel
    foreach ( $blocks as $i => &$block ) {
        if ( ! empty( $block['field'] ) ) {
            continue;
        }
        $new_key         = svgml_generate_unique_manual_field_key( $existing_keys );
        $existing_keys[] = $new_key;
        $block['field']  = $new_key;
        $rename_table[ 'manual_field_' . $i ] = $new_key;
    }
    unset( $block );

    // ── _svgml_manual_data: per regio de veldsleutels hernoemen ────────────
    $manual_data = get_post_meta( $map_id, '_svgml_manual_data', true );
    if ( is_array( $manual_data ) ) {
        foreach ( $manual_data as $region_id => $fields ) {
            if ( ! is_array( $fields ) ) {
                continue;
            }
            $new_fields = [];
            foreach ( $fields as $field_key => $field_value ) {
                // Sleutels die niet in de hernoemtabel staan (kunnen niet meer
                // voorkomen bij een verse migratie, maar wel bij data die op
                // een andere manier is binnengekomen) blijven ongewijzigd staan.
                $new_fields[ $rename_table[ $field_key ] ?? $field_key ] = $field_value;
            }
            $manual_data[ $region_id ] = $new_fields;
        }
        update_post_meta( $map_id, '_svgml_manual_data', $manual_data );
    }

    // ── _svgml_filter_fields[].field hernoemen ──────────────────────────────
    $filter_fields = get_post_meta( $map_id, '_svgml_filter_fields', true );
    if ( is_array( $filter_fields ) ) {
        foreach ( $filter_fields as &$filter ) {
            if ( isset( $filter['field'], $rename_table[ $filter['field'] ] ) ) {
                $filter['field'] = $rename_table[ $filter['field'] ];
            }
        }
        unset( $filter );
        update_post_meta( $map_id, '_svgml_filter_fields', $filter_fields );
    }

    // ── _svgml_overview_blocks[].field hernoemen ────────────────────────────
    $overview_blocks = get_post_meta( $map_id, '_svgml_overview_blocks', true );
    if ( is_array( $overview_blocks ) ) {
        foreach ( $overview_blocks as &$ob ) {
            if ( isset( $ob['field'], $rename_table[ $ob['field'] ] ) ) {
                $ob['field'] = $rename_table[ $ob['field'] ];
            }
        }
        unset( $ob );
        update_post_meta( $map_id, '_svgml_overview_blocks', $overview_blocks );
    }

    update_post_meta( $map_id, '_svgml_panel_blocks', $blocks );

    // De gecachte HTML/JSON-output van deze kaart bevat nog de oude sleutels —
    // zonder deze te wissen zou het frontend-paneel tot de volgende cache-
    // vernieuwing de verkeerde/verouderde data tonen.
    delete_transient( 'svgml_html_' . $map_id );
    delete_transient( 'svgml_json_cache_' . $map_id );

    update_post_meta( $map_id, '_svgml_manual_keys_v', 1 );
}

// ─────────────────────────────────────────────────────────────────────────────
// HOOK: migratie draaien VOORDAT een tab z'n eigen POST verwerkt
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Draait de migratie voor de kaart uit de huidige request, vóór elke andere
 * verwerking op de editor-tabs.
 *
 * Zelfde bewezen patroon als het bestaande svgml_handle_overview_actions()
 * hierboven in svg-map-lite.php: gehaakt op 'admin_init', dat garandeerd
 * vuurt VOORDAT WordPress de render-callback van de aangevraagde admin-pagina
 * aanroept (svgml_render_editor_wrapper() in svg-map-lite.php) — en die
 * render-callback is precies het moment waarop het tab-bestand (mapping.php,
 * filters.php, panel-builder.php, ...) pas voor het eerst geladen wordt en
 * zijn eigen inline $_POST-verwerking pas kan starten. Draait de migratie
 * NA die POST-verwerking, dan wordt eerst de (mogelijk net versleepte) nieuwe
 * blokvolgorde opgeslagen en pas daarna gemigreerd — exact de scramble die we
 * willen voorkomen. Vandaar bewust de 'admin_init'-hook en niet ergens verderop.
 *
 * 'svgml-overview' staat hier bewust NIET in de lijst: die pagina heeft geen
 * per-kaart POST-verwerking, dus er is daar niets om vóór te lopen. Dat is een
 * geaccepteerde scope-grens, geen omissie.
 */
add_action( 'admin_init', 'svgml_maybe_run_manual_key_migration' );

function svgml_maybe_run_manual_key_migration() {
    if ( ! isset( $_GET['page'] ) ) {
        return;
    }

    // Zelfde 7 editor-tab-slugs als svgml_render_editor_wrapper() in svg-map-lite.php.
    $editor_tab_slugs = [
        'svgml-settings',
        'svgml-mapping',
        'svgml-panel-builder',
        'svgml-display',
        'svgml-filters',
        'svgml-styles',
        'svgml-ai-assistant',
    ];
    if ( ! in_array( $_GET['page'], $editor_tab_slugs, true ) ) {
        return;
    }

    $map_id = isset( $_GET['map_id'] ) ? intval( $_GET['map_id'] ) : 0;
    if ( ! $map_id ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    svgml_migrate_manual_block_keys( $map_id );
}
