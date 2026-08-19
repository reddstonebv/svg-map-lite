/**
 * SVG Map Lite – Panel Renderer (jQuery)
 *
 * This script:
 *  1. Listens for the custom 'svgmlRegionClick' event fired by frontend.js
 *     when a visitor clicks on an SVG region.
 *  2. Renders the panel content based on the configured Panel Blocks.
 *  3. Processes block types: thumbnail, heading, badge, price, text, link, divider.
 *  4. Adds status CSS classes to SVG regions based on the status field.
 *  5. Supports the standalone [svg_map_panel] shortcode (svgml-panel-standalone).
 *  6. Optionally shows an overview of all objects when the page loads.
 *
 * Works together with frontend.js: that script manages the SVG clicks and
 * emits the event. This script catches it and builds the HTML.
 *
 * Global variables (via wp_add_inline_script in PHP):
 *   svgmlData.panelBlocks     – Array of blocks: [{field, type, label, width}, ...]
 *   svgmlData.overviewEnabled – Boolean: show overview on load
 *   svgmlData.overviewBlocks  – Array of blocks for overview rows
 *   svgmlData.statusField     – Name of the JSON field with the status
 *   svgmlData.statusColors    – Object: { 'Available': 'available', ... }
 *   svgmlData.jsonData        – Full JSON dataset
 *   svgmlData.jsonIdField     – Name of the ID field in the JSON
 *   svgmlData.mapping         – { 'svg-id': 'json-object-id', ... }
 */

(function($) {
    "use strict";

    jQuery(document).ready(function() {

    // ── CHECK: Is the data available? ────────────────────────────────────────
    if (typeof svgmlData === 'undefined') {
        return; // No svgmlData available – stop
    }

    // ── DOM REFERENCES ───────────────────────────────────────────────────────
    var $panel = $('.svgml-panel');   // Single panel instance
    var $svg   = $('.svgml-svg');

    // ── MULTI-LAYER: TRACK THE ACTIVE LAYER FOR THE OVERVIEW LIST ───────────
    // svgmlData has no notion of "layers" at all (that's PHP/_svgml_layers
    // post meta, never localized to JS) — the only place that knows which
    // regions belong to which layer is the DOM: each
    // <div class="svgml-layer" data-layer="N"> wraps its own <svg class=
    // "svgml-svg"> with that layer's <polygon id="..."> elements. So we read
    // laag membership from the DOM instead of from svgmlData.
    //
    // $allLayers is cached once here because the layer markup itself is
    // static after page load (only visibility toggles, elements don't move).
    var $allLayers = $('.svgml-layer');

    // svgml_activeLayerIndex holds the currently active layer's data-layer
    // value, used by svgml_getLayerRegionIds() below to know which layer to
    // read regions from.
    //
    // We can ONLY read this from ":visible" right here, at page-ready time —
    // frontend.php renders layer 0 with display:inline-block and every other
    // layer with display:none, so at this exact moment (nothing has switched
    // yet, no animation is running) ":visible" reliably identifies the
    // active layer. Once switchLayer() in frontend.js starts fading layers
    // in/out, ":visible" briefly lies (both layers, or neither, can appear
    // visible mid-animation) — so after this point we never use ":visible"
    // again. Instead we update this variable from the layer index that
    // frontend.js passes us directly in the 'svgmlLayerChange' event (see
    // the listener further down), which is exact regardless of animation
    // state.
    var svgml_activeLayerIndex = $allLayers.filter(':visible').first().data('layer');
    if (svgml_activeLayerIndex === undefined) {
        svgml_activeLayerIndex = null; // no layers at all (SVG-mode map) — fine, the helper below no-ops on this
    }

    // ── STATUS CLASSES ON SVG REGIONS ────────────────────────────────────────
    // When the page loads, we assign status to each SVG region right away.
    // This way the colors are visible before anyone clicks anywhere.
    svgml_applyStatusClasses();

    // ── OVERVIEW: LIST OF ALL OBJECTS ON LOAD ───────────────────────────────
    // If overview is enabled, we render a list of all
    // mapped JSON objects in the panel right away, before any region is clicked.
    if (svgmlData.overviewEnabled) {
        svgml_renderOverview();
    }

    /**
     * Loop through all JSON objects and add the correct status CSS class
     * to the corresponding SVG regions. Works based on svgmlData.mapping
     * and svgmlData.statusField.
     */
    function svgml_applyStatusClasses() {
        var statusField  = svgmlData.statusField  || '';
        var statusColors = svgmlData.statusColors || {};

        // Nothing to do if no status field is set
        if (!statusField) return;

        if (svgmlData.mapMode === 'manual') {
            // ── Manual mode: loop over manualData keyed by svgId ─────────────
            var manualData = svgmlData.manualData || {};
            if (!manualData || typeof manualData !== 'object') return;

            $.each(manualData, function(svgId, obj) {
                if (!obj) return;
                var statusValue = String(obj[statusField] || '');
                if (!statusValue) return;
                var cssClass = statusColors[statusValue] || '';
                if (!cssClass) return;
                $svg.find('#' + svgId).addClass('svgml-status-' + cssClass);
            });
            return;
        }

        // ── JSON mode ────────────────────────────────────────────────────────
        var data = svgml.normalizeToArray(svgmlData.jsonData);
        if (!data) return;

        var idField  = svgmlData.jsonIdField || 'id';
        var mapping  = svgmlData.mapping || {};

        // Build a fast lookup table: json-object-id → object
        var jsonLookup = {};
        $.each(data, function(i, obj) {
            var objId = String(obj[idField] || '');
            if (objId) {
                jsonLookup[objId] = obj;
            }
        });

        // Loop through all SVG→JSON mappings
        $.each(mapping, function(svgId, jsonId) {
            var obj = jsonLookup[String(jsonId)];
            if (!obj) return;

            var statusValue = String(obj[statusField] || '');
            if (!statusValue) return;

            var cssClass = statusColors[statusValue] || '';
            if (!cssClass) return;

            $svg.find('#' + svgId).addClass('svgml-status-' + cssClass);
        });
    }


    // ── LISTEN FOR REGION CLICK EVENTS ──────────────────────────────────────
    //
    // frontend.js fires a custom event 'svgmlRegionClick' on the document
    // when a visitor clicks on an SVG region. The event contains the data
    // of the clicked object.
    //
    // This system is loosely coupled: panel-renderer.js doesn't need to know
    // how the click works; it just responds to the event.
    $(document).on('svgmlRegionClick', function(e, eventData) {
        var jsonObject = eventData.jsonObject; // The JSON object of the clicked region
        var svgId      = eventData.svgId;      // The SVG ID of the clicked region

        if (!jsonObject) return; // No object – nothing to render

        // Build the panel content
        var html = svgml_buildPanelHtml(jsonObject, svgId);

        if ($panel.length) {
            $panel.find('.svgml-panel-content').html(html);
            $panel
                .removeAttr('aria-hidden')
                .stop(true, true)
                .fadeIn(200);
            $panel.find('.svgml-panel-close-standalone').css('display', 'flex');
        }
    });


    // ── CLOSE BUTTON INLINE PANEL ───────────────────────────────────────────
    // If overview is enabled, return to overview when closing.
    // Otherwise hide the panel.
    $(document).on('click', '#svgml-panel-close', function() {
        if (svgmlData.overviewEnabled) {
            svgml_renderOverview();
        }
    });

    // ── MULTI-LAYER: REFRESH OVERVIEW ON LAYER SWITCH ───────────────────────
    // frontend.js fires 'svgmlLayerChange' (with the new layer's index) right
    // after an actual layer switch. We update our tracked active layer index
    // and, when the overview list is enabled, re-render it so it reflects
    // only the new layer's regions. This reuses the exact same "go back to
    // overview" behavior the close buttons below already use — a layer
    // switch resets to a (freshly filtered) overview, same as closing a
    // detail panel does.
    $(document).on('svgmlLayerChange', function(e, layerIndex) {
        svgml_activeLayerIndex = layerIndex;
        if (svgmlData.overviewEnabled) {
            svgml_renderOverview();
        }
    });

    // ── CLOSE BUTTON STANDALONE PANEL ───────────────────────────────────────

    // Click on the close button of the standalone panel
    $(document).on('click', '.svgml-panel-close-standalone', function() {
        $(this).hide();
        if (svgmlData.overviewEnabled) {
            $panel.find('.svgml-panel-content').html(svgml_buildOverviewHtml());
        } else {
            $panel.attr('aria-hidden', 'true').fadeOut(200);
        }
    });


    // ── BUILD PANEL HTML ────────────────────────────────────────────────────

    /**
     * Build the HTML content of the panel based on the configured blocks.
     * Each block type has its own render function.
     *
     * @param {object} obj   – The JSON object of the clicked region
     * @param {string} svgId – The SVG ID (for debugging)
     * @returns {string}     – HTML string
     */
    function svgml_buildPanelHtml(obj, svgId) {
        var blocks = svgmlData.panelBlocks || [];
        var html   = '<div class="svgml-blocks-wrap">';

        // If there are no Panel Builder blocks, fall back to legacy displayFields
        if (blocks.length === 0) {
            return svgml_buildLegacyPanelHtml(obj, svgId);
        }

        // Loop through each configured block
        $.each(blocks, function(i, block) {
            var type        = block.type         || 'text';
            var field       = block.field        || '';
            var label       = block.label        || '';
            var width       = parseInt(block.width || 100, 10);
            var staticValue = block.static_value || '';

            var bPrefix      = block.prefix       || '';
            var bSuffix      = block.suffix       || '';
            var labelLayout  = block.label_layout || 'block';

            // Static blocks don't need a JSON field — pass static_value directly
            if (type === 'static_html' || type === 'static_button') {
                html += svgml_renderBlock(type, field, staticValue, label, obj, width, false, bPrefix, bSuffix, labelLayout);
                return; // $.each continue
            }

            // In manual mode the block has no JSON field key; derive it from
            // the block's array index, which matches the backend storage key.
            if (svgmlData.mapMode === 'manual' && field === '') {
                field = 'manual_field_' + i;
            }

            // Get the value from the JSON object
            // Divider doesn't need a field value
            var value = (field && obj.hasOwnProperty(field)) ? obj[field] : null;

            // Generate the block HTML based on the type, with the correct width.
            // isHtml = true means the value contains raw HTML and should not be escaped.
            var isHtml = !!(block.html);
            html += svgml_renderBlock(type, field, value, label, obj, width, isHtml, bPrefix, bSuffix, labelLayout);
        });

        html += '</div>'; // .svgml-blocks-wrap
        return html;
    }

    /**
     * Render one panel block based on the type.
     *
     * @param {string}  type   – Block type: thumbnail/heading/badge/price/text/link/divider
     * @param {string}  field  – Field name in the JSON object
     * @param {*}       value  – The value of the field
     * @param {string}  label  – Optional label override
     * @param {object}  obj    – The full JSON object (for status lookup)
     * @param {number}  width  – Width as percentage (25/33/50/75/100)
     * @param {boolean} isHtml – If true, render the value as raw HTML (no escaping)
     * @returns {string}       – HTML string for this block
     */
    function svgml_renderBlock(type, _field, value, label, _obj, width, isHtml, prefix, suffix, labelLayout) {
        prefix      = prefix      || '';
        suffix      = suffix      || '';
        width       = width       || 100;
        labelLayout = labelLayout || 'block';

        // Calculate the CSS flex-basis.
        // For 33% we use 33.333% for correct rendering.
        var flexBasis = (width === 33) ? '33.333%' : (width + '%');

        // The data-width attribute stores the width for CSS
        // so the flex-basis is applied via inline style.
        var widthStyle = 'flex: 0 0 ' + flexBasis + '; max-width: ' + flexBasis + ';';

        // Divider doesn't need a value but does get the width
        if (type === 'divider') {
            return '<div class="svgml-block svgml-block-divider" style="' + widthStyle + '"><hr></div>';
        }

        // Static HTML — render stored markup as-is (no JSON lookup)
        if (type === 'static_html') {
            return '<div class="svgml-block svgml-block-static-html" style="' + widthStyle + '">' +
                String(value || '') +
                '</div>';
        }

        // Static Button — render a link using stored URL + label as button text
        if (type === 'static_button') {
            var sbUrl = String(value || '').trim();
            if (!sbUrl) return '';
            return '<div class="svgml-block svgml-block-static-button" style="' + widthStyle + '">' +
                '<a href="' + svgml.escapeHtml(sbUrl) + '" ' +
                   'target="_blank" rel="noopener noreferrer" ' +
                   'class="svgml-link-btn">' +
                svgml.escapeHtml(label || sbUrl) +
                ' ↗</a>' +
                '</div>';
        }

        // Skip empty values (except divider and static types handled above)
        if (value === null || value === undefined || value === '') {
            return '';
        }

        // Build reusable class+style attribute for each block div
        var layoutClass = labelLayout === 'inline' ? ' svgml-layout-inline' : ' svgml-layout-block';
        var blockStyle  = ' style="' + widthStyle + '"';
        function bDiv(typeClass) {
            return '<div class="svgml-block ' + typeClass + layoutClass + '"' + blockStyle + '>';
        }

        var blockHtml = '';

        switch (type) {

            // ── THUMBNAIL ──────────────────────────────────────────────────
            // Shows an image at the top of the panel (no label).
            // Width is always 100% for thumbnails to fit properly,
            // regardless of the set width.
            case 'thumbnail':
                var imgUrl = svgml.escapeHtml(String(value));
                if (/^https?:\/\//i.test(imgUrl)) {
                    // Thumbnail ignores flex-width and always takes full width
                    blockHtml = '<div class="svgml-block svgml-block-thumbnail" style="flex: 0 0 100%; max-width: 100%;">' +
                        '<img src="' + imgUrl + '" alt="' + svgml.escapeHtml(label) + '" loading="lazy">' +
                        '</div>';
                }
                break;

            // ── HEADING ────────────────────────────────────────────────────
            case 'heading':
                // isHtml = true: render raw (e.g., text with <b> or <em>)
                var headRaw = (prefix ? prefix + ' ' : '') + String(value) + (suffix ? ' ' + suffix : '');
                var headVal = isHtml ? headRaw : svgml.escapeHtml(headRaw);
                blockHtml = bDiv('svgml-block-heading') +
                    '<h3 class="svgml-heading-value">' + headVal + '</h3>' +
                    '</div>';
                break;

            // ── BADGE ──────────────────────────────────────────────────────
            case 'badge':
                var badgeVal   = svgml.escapeHtml(String(value));
                var badgeClass = String(value).toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '');
                var statusColors = svgmlData.statusColors || {};
                var configClass  = statusColors[String(value)] || '';
                if (configClass) { badgeClass = configClass; }

                blockHtml = bDiv('svgml-block-badge') +
                    '<span class="svgml-badge svgml-badge-' + badgeClass + '">' + badgeVal + '</span>' +
                    '</div>';
                break;

            // ── PRICE ──────────────────────────────────────────────────────
            case 'price':
                var priceRaw = (prefix ? prefix + ' ' : '') + String(value) + (suffix ? ' ' + suffix : '');
                var priceVal = isHtml ? priceRaw : svgml.escapeHtml(priceRaw);
                blockHtml = bDiv('svgml-block-price') +
                    (label ? '<span class="svgml-block-label">' + svgml.escapeHtml(label) + '</span>' : '') +
                    '<span class="svgml-price-value">' + priceVal + '</span>' +
                    '</div>';
                break;

            // ── LINK ───────────────────────────────────────────────────────
            case 'link':
                var linkUrl = String(value);
                if (/^https?:\/\//i.test(linkUrl)) {
                    blockHtml = bDiv('svgml-block-link') +
                        '<a href="' + svgml.escapeHtml(linkUrl) + '" ' +
                           'target="_blank" rel="noopener noreferrer" ' +
                           'class="svgml-link-btn">' +
                        svgml.escapeHtml(label || linkUrl) +
                        ' ↗</a>' +
                        '</div>';
                }
                break;

            // ── HTML (raw) ─────────────────────────────────────────────────
            // For fields that contain HTML formatting (e.g., description with <div>/<p>).
            // The value is NOT escaped — HTML tags are rendered as-is.
            // Use this only for fields you're sure contain HTML
            // from a trusted source (your own JSON feed).
            case 'html':
                var rawHtml = String(value);
                blockHtml = bDiv('svgml-block-html') +
                    (label ? '<span class="svgml-block-label">' + svgml.escapeHtml(label) + '</span>' : '') +
                    '<div class="svgml-block-html-content">' + rawHtml + '</div>' +
                    '</div>';
                break;

            // ── TEXT (default) ─────────────────────────────────────────────
            default:
                // isHtml = true: render the value as raw HTML (e.g., description with <p> tags)
                // isHtml = false (default): use svgml_formatValue() with HTML escaping
                var textRaw  = (prefix ? prefix + ' ' : '') + String(value) + (suffix ? ' ' + suffix : '');
                var textVal  = isHtml ? textRaw : svgml_formatValue(textRaw);
                blockHtml = bDiv('svgml-block-text') +
                    (label ? '<span class="svgml-block-label">' + svgml.escapeHtml(label) + '</span>' : '') +
                    '<span class="svgml-block-value">' + textVal + '</span>' +
                    '</div>';
                break;
        }

        return blockHtml;
    }

    /**
     * Fallback: show all displayFields (legacy mode, for when Panel Blocks are empty).
     * This is the original display method.
     *
     * @param {object} obj   – The JSON object
     * @param {string} svgId – The SVG ID
     * @returns {string}     – HTML string
     */
    function svgml_buildLegacyPanelHtml(obj, svgId) {
        var fields = svgmlData.displayFields || [];
        var html   = '';

        // If no fields are set, show everything
        if (!fields || fields.length === 0) {
            fields = Object.keys(obj);
        }

        html += '<dl class="svgml-data-list">';

        $.each(fields, function(i, fieldName) {
            if (!obj.hasOwnProperty(fieldName)) return true; // continue
            var value = obj[fieldName];
            if (value === null || value === undefined || value === '') return true;

            var label = svgml_humanizeFieldName(fieldName);

            html += '<div class="svgml-field">';
            html += '<dt class="svgml-field-label">' + svgml.escapeHtml(label) + '</dt>';
            html += '<dd class="svgml-field-value">' + svgml_formatValue(value) + '</dd>';
            html += '</div>';
        });

        html += '</dl>';

        if (html === '<dl class="svgml-data-list"></dl>') {
            html = '<p class="svgml-panel-empty">Geen velden om weer te geven.</p>';
        }

        return html;
    }


    // ── OVERVIEW FUNCTIONS ──────────────────────────────────────────────────

    /**
     * Look up which region id's belong to one layer, by reading the DOM
     * (see the svgml_activeLayerIndex comment above for why: svgmlData has
     * no layer info at all).
     *
     * Returns null — meaning "don't filter, show everything, exactly like
     * before this feature existed" — in every case where filtering isn't
     * safely applicable:
     *  - fewer than 2 .svgml-layer elements: single-layer image map, or an
     *    SVG-mode map (no layers concept at all). Nothing to filter by.
     *  - layerIndex not known yet (null/undefined): fail-open rather than
     *    risk hiding every region because we guessed wrong.
     *  - layerIndex doesn't match any actual .svgml-layer element: same
     *    fail-open reasoning — better to show too much than to silently
     *    show an empty list.
     *
     * NOTE: defined here inside jQuery(document).ready(), in the very same
     * scope as $allLayers above. If this function were moved outside that
     * ready() callback, $allLayers would be undefined wherever this runs
     * and the filter would silently produce an empty list instead of an
     * obvious error — so keep it here, next to the other svgml_* helpers.
     *
     * @param {number|null} layerIndex – the layer's data-layer value
     * @returns {Object|null} – lookup object { 'svg-id': true, ... }, or null
     */
    function svgml_getLayerRegionIds(layerIndex) {
        if ($allLayers.length < 2) return null;
        if (layerIndex === null || layerIndex === undefined) return null;

        var $target = $allLayers.filter('[data-layer="' + layerIndex + '"]');
        if (!$target.length) return null;

        var ids = {};
        // Same selector as frontend.js:66 (stripping hardcoded stroke
        // attributes) — deliberately the same "these are real region
        // elements" selector, so we don't accidentally pick up some other
        // element that happens to carry an id (a wrapping <svg>/<img>/div
        // with an id that has nothing to do with a clickable region).
        $target.find('.svgml-svg polygon[id], .svgml-svg path[id]').each(function() {
            ids[this.id] = true;
        });
        return ids;
    }

    /**
     * Render the overview (list of all mapped objects) in all panels.
     * Called on page load if overview is enabled,
     * and when closing a detail panel.
     */
    function svgml_renderOverview() {
        var html = svgml_buildOverviewHtml();

        if ($panel.length) {
            $panel.find('.svgml-panel-content').html(html);
            // .stop(true, true) before .show(): svgml_renderOverview() is now
            // also called right after a layer switch (see the
            // 'svgmlLayerChange' listener below), and frontend.js's
            // closePanel() — fired just before that event — starts a
            // fadeOut(200) on this same $panel without stopping it first. If
            // that fadeOut is still running when we call .show() here, jQuery
            // would keep animating opacity toward 0 and hide the panel again
            // right after we just showed it. .stop(true, true) cancels any
            // in-flight animation and jumps straight to the end state, so
            // .show() always wins. No-op when nothing is animating, so this
            // is safe for every existing caller too (page load, close button).
            $panel.stop(true, true).removeAttr('aria-hidden').show();
            $panel.find('.svgml-panel-close-standalone').hide();
        }
    }

    /**
     * Build the HTML for the overview panel: a list of all
     * JSON objects that are mapped in the Region Mapping.
     * Clicking on a row triggers the svgmlRegionClick event.
     *
     * @returns {string} – HTML string
     */
    function svgml_buildOverviewHtml() {
        var overviewBlocks    = svgmlData.overviewBlocks || [];
        var hasOverviewBlocks = (overviewBlocks.length > 0);
        var isClickable       = (svgmlData.overviewClickable !== false);
        var itemClass         = 'svgml-overview-item' + (isClickable ? '' : ' svgml-overview-item--no-click');
        var html              = '<div class="svgml-overview-list">';

        // Sort helper: returns a new key array ordered by the configured field.
        // If no field is configured, auto-selects a "name" field (case-insensitive)
        // when present on the items; otherwise preserves the original order.
        function sortKeys(keys, getObj) {
            var field = svgmlData.overviewSortField || '';
            var order = (svgmlData.overviewSortOrder === 'desc') ? -1 : 1;

            if (!field && keys.length > 0) {
                var sample = getObj(keys[0]);
                if (sample && typeof sample === 'object') {
                    var nameKey = Object.keys(sample).find(function(k) {
                        return k.toLowerCase() === 'name';
                    });
                    if (nameKey) field = nameKey;
                }
            }
            if (!field) return keys;

            return keys.slice().sort(function(a, b) {
                var oa = getObj(a), ob = getObj(b);
                var va = (oa && oa[field] != null) ? oa[field] : '';
                var vb = (ob && ob[field] != null) ? ob[field] : '';
                var na = parseFloat(String(va).replace(',', '.'));
                var nb = parseFloat(String(vb).replace(',', '.'));
                if (!isNaN(na) && !isNaN(nb)) return (na - nb) * order;
                return String(va).localeCompare(String(vb), undefined, { numeric: true, sensitivity: 'base' }) * order;
            });
        }

        if (svgmlData.mapMode === 'manual') {
            // ── Manual mode: iterate manualData keyed by svgId ───────────────
            var manualData = svgmlData.manualData || {};
            if (!manualData || typeof manualData !== 'object' || Object.keys(manualData).length === 0) {
                return '<p class="svgml-panel-empty">Geen objecten beschikbaar.</p>';
            }

            // Multi-layer filter: only list regions that actually live on the
            // active layer. layerIds is null on single-layer/SVG maps (see
            // svgml_getLayerRegionIds() above), in which case we skip
            // filtering entirely and keep the exact pre-existing behavior.
            var layerIds           = svgml_getLayerRegionIds(svgml_activeLayerIndex);
            var manualKeysAll      = Object.keys(manualData);
            var manualKeysFiltered = layerIds
                ? manualKeysAll.filter(function(id) { return layerIds.hasOwnProperty(id); })
                : manualKeysAll;

            var manualKeys = sortKeys(manualKeysFiltered, function(id) { return manualData[id]; });
            $.each(manualKeys, function(_, svgId) {
                var obj = manualData[svgId];
                if (!obj) return;
                var rowHtml = '';
                if (hasOverviewBlocks) {
                    $.each(overviewBlocks, function(i, block) {
                        var field = block.field || '';
                        var type  = block.type  || 'text';
                        var label = block.label || '';
                        var value = (field && obj.hasOwnProperty(field)) ? obj[field] : null;
                        if (value === null || value === undefined || value === '') return;
                        var isHtml = !!(block.html);
                        rowHtml += svgml_renderOverviewBlock(type, field, value, label, obj, isHtml);
                    });
                } else {
                    // Fallback: first non-empty value in the object
                    var firstVal = '';
                    $.each(obj, function(k, v) {
                        if (!firstVal && typeof v === 'string' && v.length > 0) {
                            firstVal = v;
                        }
                    });
                    rowHtml = '<span class="svgml-overview-title">' + svgml.escapeHtml(firstVal || svgId) + '</span>';
                }

                // data-json-id = svgId in manual mode (no separate JSON ID)
                html += '<div class="' + itemClass + '" ' +
                        'data-svg-id="' + svgml.escapeHtml(svgId) + '" ' +
                        'data-json-id="' + svgml.escapeHtml(svgId) + '">' +
                        rowHtml +
                        '</div>';
            });

        } else {
            // ── JSON mode ────────────────────────────────────────────────────
            var mapping = svgmlData.mapping || {};
            var data    = svgml.normalizeToArray(svgmlData.jsonData);
            var idField = svgmlData.jsonIdField || 'id';

            if (!data || data.length === 0) {
                return '<p class="svgml-panel-empty">Geen objecten beschikbaar.</p>';
            }

            var jsonLookup = {};
            $.each(data, function(i, obj) {
                var objId = String(obj[idField] || '');
                if (objId) jsonLookup[objId] = obj;
            });

            // Same multi-layer filter as the manual branch above: only list
            // regions that live on the active layer, skipped (layerIds ===
            // null) on single-layer/SVG maps.
            var layerIds         = svgml_getLayerRegionIds(svgml_activeLayerIndex);
            var mappingKeysAll   = Object.keys(mapping);
            var mappingKeysFiltered = layerIds
                ? mappingKeysAll.filter(function(id) { return layerIds.hasOwnProperty(id); })
                : mappingKeysAll;

            var svgIdsSorted = sortKeys(mappingKeysFiltered, function(sid) {
                return jsonLookup[String(mapping[sid])];
            });
            $.each(svgIdsSorted, function(_, svgId) {
                var jsonId = mapping[svgId];
                var obj = jsonLookup[String(jsonId)];
                if (!obj) return;

                var rowHtml = '';
                if (hasOverviewBlocks) {
                    $.each(overviewBlocks, function(i, block) {
                        var field = block.field || '';
                        var type  = block.type  || 'text';
                        var label = block.label || '';
                        var value = (field && obj.hasOwnProperty(field)) ? obj[field] : null;
                        if (value === null || value === undefined || value === '') return;
                        var isHtml = !!(block.html);
                        rowHtml += svgml_renderOverviewBlock(type, field, value, label, obj, isHtml);
                    });
                } else {
                    var firstVal = '';
                    $.each(obj, function(k, v) {
                        if (!firstVal && typeof v === 'string' && v.length > 0 && k !== idField) {
                            firstVal = v;
                        }
                    });
                    rowHtml = '<span class="svgml-overview-title">' + svgml.escapeHtml(firstVal || String(jsonId)) + '</span>';
                }

                html += '<div class="' + itemClass + '" ' +
                        'data-svg-id="' + svgml.escapeHtml(svgId) + '" ' +
                        'data-json-id="' + svgml.escapeHtml(String(jsonId)) + '">' +
                        rowHtml +
                        '</div>';
            });
        }

        html += '</div>';

        if (html === '<div class="svgml-overview-list"></div>') {
            html = '<p class="svgml-panel-empty">Geen objecten om weer te geven.</p>';
        }

        return html;
    }

    /**
     * Render one overview block (lighter version of svgml_renderBlock, without width).
     */
    /**
     * Render a single overview block.
     *
     * @param {string}  type   - Block type (heading, badge, price, link, text)
     * @param {string}  field  - JSON field name
     * @param {*}       value  - The value from the JSON object
     * @param {string}  label  - Optional label
     * @param {object}  obj    - The full JSON object (for any extra fields)
     * @param {boolean} isHtml - If true, the value is shown as raw HTML (no escaping)
     */
    function svgml_renderOverviewBlock(type, field, value, label, obj, isHtml) {
        // Helper: escape unless HTML mode is active
        var safe = isHtml ? String(value) : svgml.escapeHtml(String(value));

        // Only show a label prefix when the user explicitly filled it in
        var labelHtml = (label && type !== 'badge' && type !== 'link')
            ? '<span class="svgml-ov-label">' + svgml.escapeHtml(label) + '</span>'
            : '';

        switch (type) {
            case 'heading':
                return '<span class="svgml-ov-heading">' + labelHtml + safe + '</span>';

            case 'badge':
                var badgeVal   = svgml.escapeHtml(String(value)); // always escape badges (short text)
                var statusColors = svgmlData.statusColors || {};
                var bClass     = statusColors[String(value)] || String(value).toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '');
                return '<span class="svgml-badge svgml-badge-' + bClass + ' svgml-ov-badge">' + badgeVal + '</span>';

            case 'price':
                return '<span class="svgml-ov-price">' + labelHtml + safe + '</span>';

            case 'link':
                if (/^https?:\/\//i.test(String(value))) {
                    return '<a href="' + svgml.escapeHtml(String(value)) + '" class="svgml-ov-link" target="_blank" rel="noopener" onclick="event.stopPropagation()">' +
                           svgml.escapeHtml(label || svgml_humanizeFieldName(field) || 'Link') + ' ↗</a>';
                }
                return '';

            default: // text
                return '<span class="svgml-ov-text">' + labelHtml + safe + '</span>';
        }
    }

    // ── SIDEBAR → SVG HOVER SYNC ────────────────────────────────────────────
    $(document).on('mouseenter', '.svgml-overview-item', function() {
        var svgId = $(this).data('svg-id');
        if (!svgId) return;
        // Support multi-view: find all SVG elements sharing this json-id via the mapping
        var jsonId  = String($(this).data('json-id') || svgId);
        var mapping = (typeof svgmlData !== 'undefined') ? (svgmlData.mapping || {}) : {};
        if (svgmlData.mapMode === 'manual') {
            $('[id="' + svgId + '"]').addClass('svgml-region-hover');
        } else {
            $.each(mapping, function(sid, jid) {
                if (String(jid) === jsonId) {
                    $('[id="' + sid + '"]').addClass('svgml-region-hover');
                }
            });
        }
    });

    $(document).on('mouseleave', '.svgml-overview-item', function() {
        $('.svgml-region-hover').removeClass('svgml-region-hover');
    });

    // ── CLICK ON OVERVIEW ROW ────────────────────────────────────────────────
    // When a visitor clicks on a row in the overview, we simulate a
    // click on the corresponding SVG region so the detail panel is shown.
    $(document).on('click', '.svgml-overview-item', function() {
        if (svgmlData.overviewClickable === false) return;
        var svgId  = $(this).data('svg-id');
        var jsonId = $(this).data('json-id');
        var obj    = null;

        if (svgmlData.mapMode === 'manual') {
            // ── Manual mode: look up directly by svgId ────────────────────────
            var manualData = svgmlData.manualData || {};
            obj = manualData[svgId] || null;
        } else {
            // ── JSON mode: search jsonData array ─────────────────────────────
            var data    = svgml.normalizeToArray(svgmlData.jsonData);
            var idField = svgmlData.jsonIdField || 'id';
            if (data) {
                $.each(data, function(i, item) {
                    if (String(item[idField] || '') === String(jsonId)) {
                        obj = item;
                        return false; // break
                    }
                });
            }
        }

        if (!obj) return;

        // Trigger the same event as a click on the SVG region
        $(document).trigger('svgmlRegionClick', [{
            jsonObject: obj,
            svgId:      svgId,
            $region:    $svg.find('#' + svgId)
        }]);

        // Mark the corresponding SVG region as active
        $svg.find('[id]').removeClass('svgml-region-active');
        $svg.find('#' + svgId).addClass('svgml-region-active');
    });

    // ── HELPER FUNCTIONS ────────────────────────────────────────────────────

    /**
     * Make a field name human-readable.
     * Replaces underscores and hyphens with spaces,
     * and capitalizes the first letter of each word.
     *
     * @param {string} fieldName – The technical field name
     * @returns {string}         – Readable name
     */
    function svgml_humanizeFieldName(fieldName) {
        return fieldName
            .replace(/[_-]/g, ' ')
            .replace(/\b\w/g, function(c) { return c.toUpperCase(); });
    }

    /**
     * Format a value for display in the panel.
     * Detects URLs and arrays.
     *
     * @param {*} value – The value to format
     * @returns {string} – HTML string
     */
    function svgml_formatValue(value) {
        if (Array.isArray(value)) {
            return svgml.escapeHtml(value.join(', '));
        }
        if (typeof value === 'object' && value !== null) {
            return '<code>' + svgml.escapeHtml(JSON.stringify(value)) + '</code>';
        }
        var strValue = String(value);
        if (/^https?:\/\//i.test(strValue)) {
            return '<a href="' + svgml.escapeHtml(strValue) + '" target="_blank" rel="noopener">' +
                   svgml.escapeHtml(strValue) + '</a>';
        }
        return svgml.escapeHtml(strValue);
    }


    }); // End jQuery(document).ready

})(jQuery);
