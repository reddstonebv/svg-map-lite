# SVG Map Lite — Developer Reference

## Identity
- **Plugin slug:** `svg-map-lite`
- **CPT:** `svgml_map` (one post per map)
- **Post meta prefix:** `_svgml_`
- **Version constant:** `SVGML_VERSION`
- **Path constant:** `SVGML_PATH` / `SVGML_URL`
- **AJAX nonce action:** `svgml_admin_nonce`
- **AJAX action prefix:** `svgml_` (e.g. `wp_ajax_svgml_parse_svg`)

## Architecture — Two Map Modes

| Mode | `_svgml_source_type` | Regions come from |
|------|----------------------|-------------------|
| SVG File | `svg` | IDs extracted from SVG file via AJAX → saved to `_svgml_svg_ids` |
| Image + Polygons | `image` | Polygons drawn in Fabric.js → saved to `_svgml_layers` (and mirrored to `_svgml_svg_ids` on form save) |

Map data mode (JSON feed vs manual entry) is separate: `_svgml_map_mode` = `json` or `manual`.

## Admin Tabs → Files

| Page slug | PHP file | Purpose |
|-----------|----------|---------|
| `svgml-overview` | `svg-map-lite.php` | Card grid of all maps |
| `svgml-settings` | `includes/settings.php` | SVG/image upload, JSON URL, source type |
| `svgml-mapping` | `includes/mapping.php` | Region ↔ JSON ID linking ("Regio Koppeling") |
| `svgml-display` | `includes/display.php` | Visual display options |
| `svgml-panel-builder` | `includes/panel-builder.php` | Info panel field builder |
| `svgml-filters` | `includes/filters.php` | Filter configuration |
| `svgml-styles` | `includes/styles.php` | Custom CSS editor (CodeMirror) |
| `svgml-ai-assistant` | `includes/ai-assistant.php` | AI assistant tab |

All tabs share `svgml_render_editor_wrapper()` which reads `map_id` from `$_GET['map_id']`.

## Key Post Meta Fields

### Source / Regions
- `_svgml_source_type` — `svg` or `image`
- `_svgml_svg_attachment_id` — WP attachment ID of the SVG file
- `_svgml_svg_ids` — `array` of region IDs (from SVG parse AJAX or polygon save)
- `_svgml_image_attachment_id` — WP attachment ID of background image (image mode)
- `_svgml_layers` — `array` of layer objects `{name, image_attachment_id, polygons[], stroke_color, stroke_width}`
- `_svgml_polygons` — legacy single-layer polygons (backward compat, mirrors `layers[0].polygons`)

### JSON Feed
- `_svgml_json_url` — URL of the JSON data feed
- `_svgml_json_id_field` — field name used as ID key (default: `id`)
- `_svgml_json_array_key` — manual override for nested array key
- `_svgml_map_mode` — `json` or `manual`

### Region Mapping
- `_svgml_id_mapping` — `assoc array` svg_id → json_id
- `_svgml_excluded_ids` — `array` of SVG IDs excluded from display

### Panel & Display
- `_svgml_panel_position` — `right`, `left`, `bottom`, `shortcode`
- `_svgml_panel_blocks` — JSON config for info panel fields
- `_svgml_display_fields` — fields shown in panel

### Filters & Status
- `_svgml_filter_fields` — filter field config
- `_svgml_status_field` / `_svgml_status_colors` / `_svgml_status_hex_colors`

### Style
- `_svgml_custom_css` — scoped to `#svgml-wrap-{map_id}`
- `_svgml_poly_stroke_color` / `_svgml_poly_stroke_width`
- `_svgml_layer_switcher` — `buttons`, `dropdown`, or `tabs`

## JavaScript Globals (`svgmlAdmin`)

Localized via `wp_localize_script('svgml-admin-js', 'svgmlAdmin', ...)` in `svgml_admin_enqueue()`:

| Key | Value |
|-----|-------|
| `ajaxUrl` | `admin-ajax.php` URL |
| `nonce` | nonce for `svgml_admin_nonce` |
| `mapId` | current `$_GET['map_id']` (int) |
| `mapMode` | `json` or `manual` |
| `svgId` | current SVG attachment ID |
| `layers` | current layers array |
| `jsonData` | full JSON feed (mapping/panel-builder/display/filter pages only) |
| `jsonIdField` | ID field name |
| `strings` | UI string translations |

## Key JS Files

| File | Purpose |
|------|---------|
| `assets/js/admin.js` | SVG upload, AJAX parse, region mapping live-lookup |
| `assets/js/polygon-editor.js` | Fabric.js canvas editor for image+polygon mode |
| `assets/js/frontend.js` | Frontend hover-sync, click-to-activate |
| `assets/js/panel-renderer.js` | Renders info panel from JSON data |
| `assets/js/filters.js` | Frontend filter UI and region dimming |
| `assets/js/panel-builder.js` | Serializes panel builder config to hidden field |
| `assets/js/utils.js` | Shared helpers (JSON normalization, etc.) |
| `includes/admin-footer.php` | Inline JS: Ctrl+S save, scroll restoration, layer sync |

## Key PHP Files

| File | Key Functions |
|------|--------------|
| `svg-map-lite.php` | CPT registration, tab routing, `svgml_admin_enqueue()`, `svgml_render_editor_wrapper()` |
| `includes/ajax.php` | All `wp_ajax_svgml_*` handlers: parse SVG, create/delete map, get JSON, image CRUD |
| `includes/frontend.php` | `[svg_map]` shortcode, inline CSS generation scoped to `#svgml-wrap-{id}` |
| `includes/settings.php` | `svgml_render_settings_page()` — handles SVG upload, polygon save, source type |
| `includes/mapping.php` | `svgml_render_mapping_page()` — JSON mapping + manual data entry |

## SVG Parse Flow (SVG mode)

1. User selects SVG in media library → `parseSvg(attachmentId)` fires
2. AJAX `svgml_parse_svg` → `svgml_extract_ids_from_svg()` recursively reads `id` attributes
3. Skips: `''`, `svg`, `svg1`, `layer1`, `Layer_1`
4. Saves to `_svgml_svg_ids` (post meta) and `_svgml_svg_attachment_id`
5. Green popup shown; link to Regio Koppeling includes `&map_id=`

## Shortcodes

- `[svg_map id="X"]` — renders the interactive map
- `[svg_map_panel id="X"]` — renders the info panel standalone
- `[svg_map_filters id="X"]` — renders the filter UI standalone

Frontend CSS is scoped: `#svgml-wrap-{map_id}` for isolation between multiple maps on one page.

## Session Rules (token optimization)

- Zero fluff, no pleasantries, no summaries
- Code only — no explanations unless asked
- Always show filename above code block
- Acknowledge new sessions with: "Context loaded. Ready for the first task."
