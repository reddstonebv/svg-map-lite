# SVG Map Lite — Developer Reference

## Identity
- **Plugin slug:** `svg-map-lite`
- **Plugin folder / main file:** `svg-map-lite/svg-map-lite.php`
- **GitHub repo:** `reddstonebv/svg-map-lite` (branch `main`)
- **CPT:** `svgml_map` (one post per map)
- **Post meta prefix:** `_svgml_`
- **Version constant:** `SVGML_VERSION` (must always equal the `Version:` header — see Releases)
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
- `_svgml_custom_css` — free CSS from the Styles tab, output as-is (**not** auto-scoped; only loaded on pages that render this map, see CSS loading)
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
| `svg-map-lite.php` | CPT registration, tab routing, `svgml_admin_enqueue()`, `svgml_render_editor_wrapper()`, update-checker bootstrap |
| `includes/ajax.php` | All `wp_ajax_svgml_*` handlers: parse SVG, create/delete map, get JSON, image CRUD |
| `includes/frontend.php` | Shortcodes, per-map CSS (`svgml_get_map_css()`, `svgml_get_map_root_css()`, `svgml_get_map_style_tag()`), all-maps CSS (`svgml_output_custom_css()`) |
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

Each of the three shortcodes prepends the map's CSS (`<style id="svgml-custom-css-{id}">`) to
its return value, once per request per map — see CSS loading. The `$map_id` used is the one
after the `wpml_object_id` swap, i.e. the same ID as in `#svgml-wrap-{id}`.

Standalone panel markup (since 2.6.0):
`<div class="svgml-panel svgml-panel-standalone svgml-panel-map-{id}" id="svgml-panel-standalone" data-map-id="{id}">`.
`svgml-panel-map-{id}` / `data-map-id` are only added when a map ID resolved. The fixed IDs
`svgml-panel-standalone` / `svgml-panel-content-standalone` and the existing classes must stay —
customer CSS and `panel-renderer.js` (`$('.svgml-panel')`) depend on them. Use
`.svgml-panel-map-{id}` to style one map's panel; the panel is outside `#svgml-wrap-{id}`, so the
generated `#svgml-wrap-{id} .svgml-panel-inner` rules never reach it (known, intentionally not
changed).

Link arrow (since 2.6.1): the `↗` after frontend link buttons (`.svgml-link-btn`, `.svgml-ov-link`)
in `panel-renderer.js` is wrapped in `<span class="svgml-link-arrow" aria-hidden="true">`, so
customer CSS can hide or replace it. The admin previews in `admin-footer.php` keep the bare `↗`.

## CSS loading (since 2.6.0)

Generated rules are scoped to `#svgml-wrap-{map_id}`; the free Custom CSS is not.

**Default:** a page only gets the CSS of maps it actually renders. `svgml_get_map_style_tag( $map_id )`
returns `<style id="svgml-custom-css-{id}">` = `:root` vars + generated rules + Custom CSS, and
the shortcode puts it in front of its HTML. Rules:
- A static list makes it one tag per map per request (map + filters + panel → one style).
- Only returned when `( did_action('wp_head') && ! doing_action('wp_head') ) || wp_doing_ajax()
  || Elementor editor->is_edit_mode()`. Not during `wp_head` (SEO plugins rendering content for
  og-tags), not in other wp-admin requests (HTML export).
- Never inside the `svgml_html_{id}` transient — it is prepended after the cache, so saved CSS
  changes show immediately.
- The tag lands in the `<body>`, so it comes after all head CSS (incl. Customizer Extra CSS,
  `wp_head` 101): at equal specificity map CSS now wins over Customizer CSS (before 2.6.0 it lost).
- Known limits: a shortcode rendered before `wp_head` gets no CSS; a render after `wp_head` that
  is thrown away (excerpt/schema) uses up the once-per-request slot. Escape hatch: the filter.
- `:root` vars (`--svgml-accent`, `--svgml-panel-bg`): with two maps on one page the last
  rendered map wins.

**Filter `svgml_load_all_map_css`** (default `false`): `true` restores the pre-2.6.0 behaviour —
`svgml_output_custom_css()` prints the CSS of all published maps in `wp_head`, and the shortcodes
print nothing.
```php
add_filter( 'svgml_load_all_map_css', '__return_true' );
```

**`svgml_output_custom_css()` (no arguments) must not change.** It stays hooked on `wp_head`
(so existing `remove_action()` calls keep working) but returns early only while *in* `wp_head`
with the filter off. `svg-map-lite-html-export/includes/inline-css.php` calls it directly via
`ob_start()` and relies on exactly one `<style id="svgml-custom-css">`, the header comment, the
`:root` block first, then all maps' CSS (it filters out other maps itself).

## Vendor Assets (Fabric.js / noUiSlider)

- `assets/js/vendor/` and `assets/css/vendor/` are **committed to the repo**. The blanket
  `vendor/` rule in `.gitignore` (matches any folder named `vendor`, at any depth) is
  explicitly un-ignored for both via `!/assets/js/vendor/` and `!/assets/css/vendor/`,
  placed directly below the blanket rule, same style as the PUC exception below. Negating
  loose files inside `vendor/` would **not** work — git never descends into an excluded
  folder to see the files in the first place — so the negation must target the folder itself
  with a leading slash.
- **Why this matters:** these three files ship inside the update ZIP that PUC pulls from the
  GitHub tag. If they are git-ignored, WordPress's next auto-update replaces the plugin folder
  with one that lacks them entirely — this happened once already (`fabric.min.js`,
  `nouislider.min.js`, `nouislider.min.css`, and `download-vendor.sh` all vanished from every
  client site in one update cycle).
- `svg-map-lite.php` still checks `file_exists()` for each vendor file and falls back to a CDN
  URL when it is missing. That fallback now exists **only as a safety net** (e.g. a manually
  stripped-down deploy) — it is not the intended delivery path, and normal operation always
  serves the local files from the repo.
- `assets/js/vendor/download-vendor.sh` re-downloads the three files from CDN. It is only
  needed for a fresh checkout where these files are somehow missing; under normal operation
  they are already present because they're tracked in git.
- **Exception:** `plugin-update-checker/vendor/` (Parsedown + PucReadmeParser, bundled inside PUC itself) is explicitly un-ignored via `!/plugin-update-checker/vendor/` in `.gitignore`, placed directly below the blanket `vendor/` rule. Unlike Fabric.js/noUiSlider there is **no CDN fallback** for it: PUC calls `Parsedown::instance()` while rendering a GitHub Release's body as changelog HTML (`Puc/v5p7/Vcs/GitHubApi.php`), and a missing class there is a fatal error, not a graceful skip. If this exception is ever removed, every client site fatal-errors on its next update check as soon as a release has a non-empty description.

## Releases & Auto-Update (Plugin Update Checker v5 + GitHub)

The plugin is installed on sites we do not manage, so it updates itself from GitHub
via **Plugin Update Checker (PUC) v5** by Yahnis Elsts.

### Setup
- Library lives at `plugin-update-checker/` in the plugin root — **committed to the repo**,
  installed by hand (download the v5 ZIP from GitHub), *not* via Composer, because
  `.gitignore` ignores `vendor/`.
- Bootstrapped in `svg-map-lite.php` with the fully-qualified class name
  `\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker()`
  (no `use` statement — avoids the "must be at top of file" trap).
- Repo URL `https://github.com/reddstonebv/svg-map-lite/`, `__FILE__`, slug `svg-map-lite`.
- Branch is pinned with `->setBranch('main')`.
- The repo is **public**, so **no access token is used or stored** anywhere. Never add one.
- The bootstrap is wrapped in an `is_admin() || wp_doing_cron()` guard so it costs nothing
  on the frontend.

### How updates are found
PUC asks the GitHub API for the newest **tag / release** on the repo, reads the
`Version:` header from `svg-map-lite.php` inside that tag, and compares it to the installed
version. WordPress then shows the normal "update available" notice in Plugins.
PUC caches the result for ~12 h; `?puc_debug=1` (as admin) forces a re-check.

### Release checklist — follow every time
1. Bump the `Version:` header in `svg-map-lite.php`.
2. Bump `SVGML_VERSION` to **exactly the same string**. A mismatch has bitten this repo
   before (`e7385b5 Fixed version mismatch`) — PUC reads the header, the enqueue cache-buster
   reads the constant.
3. Commit and push to `main`.
4. Create a git tag with the same number, e.g. `git tag 2.0.4 && git push origin 2.0.4`
   (tags are plain numbers here — no `v` prefix; keep it consistent).
5. Optionally publish a GitHub Release for that tag so the changelog shows up.
6. On a test site: Dashboard → Updates → Check again, confirm the new version appears and
   updates cleanly.

### Gotchas
- **Never** add a token, `.env`, or credentials to this repo — public repo, and the updater
  does not need one.
- Only tracked files end up in the update ZIP. Fabric.js/noUiSlider vendor assets are now
  tracked (see Vendor Assets above) specifically because of this — anything still actually
  git-ignored (zips, OS/IDE cruft) stays absent on the client site, so do not start depending
  on a git-ignored file at runtime.
- Do not rename the plugin folder or the main file: PUC's slug and WordPress's folder
  renaming both key off `svg-map-lite`.

## Session Rules (token optimization)

- Zero fluff, no pleasantries, no summaries
- Code only — no explanations unless asked
- Always show filename above code block
- Acknowledge new sessions with: "Context loaded. Ready for the first task."

## Coding preferences (for any Claude working in this repo)

- WordPress plugin code. The author is **not strong in PHP** → write **detailed PHP comments**
  explaining what each block does and why.
- Prefer **jQuery** over plain JS.
- **Backward compatible by default:** never change existing option/meta names, defaults, or
  sanitize callbacks unless that is the explicit task. New options default to off/empty.
- Be economical: show **diffs, not whole files**; don't re-read files you've already read.
- Finish with `php -l` on changed PHP files and `node --check` on changed JS.
