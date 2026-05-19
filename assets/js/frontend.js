/**
 * SVG Map Lite – Frontend JavaScript (jQuery)
 *
 * This script:
 *  1. Listens for click events on SVG regions
 *  2. Looks up the corresponding JSON object via the mapping
 *  3. Displays the desired fields in the info panel
 *
 * Available global variables (passed via wp_add_inline_script in PHP):
 *   svgmlData.mapping        – { 'svg-id': 'json-object-id-value', ... }
 *   svgmlData.jsonData       – Complete JSON dataset (array of objects)
 *   svgmlData.jsonIdField    – Name of the ID field in the JSON (e.g. 'id')
 *   svgmlData.displayFields  – Array of field names to be displayed
 *   svgmlData.panelPosition  – 'right', 'bottom', or 'left'
 *   svgmlData.panelTitle     – Fixed title above the panel (can be empty)
 *   svgmlData.jsonArrayKey   – Manual sub-array key (e.g. 'spaces'), empty = auto-detect
 */

(function($) {
    "use strict";

    console.log('[SVGML] 📦 Script loaded and IIFE executing.');

    jQuery(document).ready(function() {

    console.log('[SVGML] ✅ DOM ready fired.');

    // ── CHECK: Is the data available? ────────────────────────────────────
    // If svgmlData does not exist, the shortcode probably is not on this page.
    if (typeof svgmlData === 'undefined') {
        console.warn('[SVGML] ⛔ svgmlData is undefined — shortcode not on this page. Script stopped.');
        return;
    }

    console.log('[SVGML] ✅ svgmlData found. mapMode:', svgmlData.mapMode, '| mapping keys:', Object.keys(svgmlData.mapping || {}).length);

    // Ensure excludedIds is always an array, even if the option is missing
    var excludedIds = svgmlData.excludedIds || [];

    // ── DOM REFERENCES ──────────────────────────────────────────────────────
    var $container    = $('.svgml-container');
    var $svg          = $('.svgml-svg');
    var $panel        = $('.svgml-panel');
    var $panelContent = $('.svgml-panel-content');
    var $closeBtn     = $('.svgml-panel-close');

    console.log('[SVGML] 🗺️ Map DOM — .svgml-svg found:', $svg.length, '| .svgml-panel found:', $panel.length, '| .svgml-wrap found:', $('.svgml-wrap').length);

    // Store the actively selected region so we can deselect it
    var $activeRegion = null;

    // ── MARK EXCLUDED REGIONS ─────────────────────────────────────────
    // Add a CSS class directly to excluded SVG elements.
    // This way you can style them differently in your theme (e.g. no pointer-cursor).
    if (excludedIds.length > 0) {
        $.each(excludedIds, function(i, svgId) {
            // Find the SVG element with this specific id
            // We search within the SVG so we don't affect other page elements
            $svg.find('#' + svgId).addClass('svgml-region-excluded');
        });
    }

    // ── STRIP HARDCODED STROKE ATTRIBUTES ────────────────────────────────
    // Raw uploaded SVGs may carry presentation attributes like stroke="green"
    // that take precedence over CSS. Remove them so the inline <style> rules win.
    $svg.find('polygon[id], path[id], .svgml-poly-region').each(function() {
        $(this).removeAttr('stroke stroke-width');
    });


    // ── CLICK ON SVG REGIONS ───────────────────────────────────────────────

    // We use event delegation: we listen on the SVG wrapper and catch
    // clicks on child elements. This also works for elements added later
    // and is more efficient than setting a listener on each element separately.
    //
    // '[id]' is a CSS selector that selects all elements with an id attribute.
    // ── CLICK HANDLER (capture phase) ─────────────────────────────────────
    // Gutenberg/theme scripts call stopPropagation() during bubbling, so jQuery
    // document delegation never fires. Capture phase runs before any of that.
    document.addEventListener('click', function(e) {

        // Log every click so we can confirm the capture listener is alive
        console.log('[SVGML] 🖱️ Capture click fired. Target:', e.target.tagName, e.target.id || '(no id)', '| in .svgml-wrap:', !!e.target.closest('.svgml-wrap'));

        if (e.target.closest('.svgml-panel-close')) {
            console.log('[SVGML T] Close button hit.');
            closePanel();
            return;
        }

        var wrap = e.target.closest('.svgml-wrap');
        if (!wrap) return;

        console.log('[SVGML T] 1. Click inside .svgml-wrap. Target:', e.target);

        var idNode = e.target.closest('[id]');
        console.log('[SVGML T] 2. Closest [id] node:', idNode ? idNode.id : 'NONE');

        if (!idNode || !idNode.closest('.svgml-svg')) {
            console.log('[SVGML T] 3. No mapped id node or outside .svgml-svg — background click.');
            if (e.target.closest('.svgml-svg')) closePanel();
            return;
        }

        var svgId = idNode.getAttribute('id');
        console.log('[SVGML T] 3. svgId:', svgId, '| mapMode:', svgmlData.mapMode);
        console.log('[SVGML T] 4. excludedIds:', excludedIds, '| mapping keys:', Object.keys(svgmlData.mapping || {}));

        if (excludedIds.indexOf(svgId) !== -1) {
            console.log('[SVGML T] 4a. ABORT — svgId is in excludedIds.');
            return;
        }

        var jsonObject = null;

        if (svgmlData.mapMode === 'manual') {
            var manualData = svgmlData.manualData || {};
            console.log('[SVGML T] 5. Manual mode. manualData keys:', Object.keys(manualData));
            if (!manualData.hasOwnProperty(svgId)) {
                console.log('[SVGML T] 5a. ABORT — svgId not in manualData.');
                return;
            }
            jsonObject = manualData[svgId];
        } else {
            console.log('[SVGML T] 5. JSON mode. mapping has svgId?', svgmlData.mapping.hasOwnProperty(svgId));
            if (!svgmlData.mapping.hasOwnProperty(svgId)) {
                console.log('[SVGML T] 5a. ABORT — svgId not in mapping. Closing panel.');
                closePanel();
                return;
            }
            var jsonObjectId = svgmlData.mapping[svgId];
            console.log('[SVGML T] 6. jsonObjectId from mapping:', jsonObjectId);
            jsonObject = findJsonObject(jsonObjectId);
            console.log('[SVGML T] 7. findJsonObject result:', jsonObject);
        }

        if (!jsonObject) {
            console.log('[SVGML T] 8. ABORT — jsonObject is null. Showing not-found message.');
            $panelContent.html(
                '<p class="svgml-panel-not-found">' +
                'No data found for ID: <em>' + svgId + '</em>' +
                '</p>'
            );
            openPanel();
            return;
        }

        console.log('[SVGML T] 9. All checks passed. Calling setActiveRegion / renderPanel / openPanel.');
        var $region = $(idNode);
        setActiveRegion($region);
        renderPanel(jsonObject, svgId);
        openPanel();

        $(document).trigger('svgmlRegionClick', [{
            jsonObject: jsonObject,
            svgId:      svgId,
            $region:    $region
        }]);

    }, true); // capture: true — fires before any stopPropagation() call


    // Escape key closes the panel
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            closePanel();
        }
    });


    // ── HELPER FUNCTIONS ─────────────────────────────────────────────────────────

    /**
     * Find an object in the JSON dataset based on its ID value.
     *
     * @param  {string|number} targetId – The ID value to search for
     * @returns {object|null}           – The found object, or null
     */
    function findJsonObject(targetId) {
        var idField = svgmlData.jsonIdField; // E.g. 'id', 'objectId', etc.

        // Normalize the JSON to an array (same logic as in admin.js)
        var data = svgml.normalizeToArray( svgmlData.jsonData );

        if (!data) {
            return null; // No usable array found
        }

        // Search the array for the object with the correct ID.
        // We compare as strings so that "42" and 42 both match.
        var found = null;
        $.each(data, function(index, obj) {
            if ( String(obj[idField]) === String(targetId) ) {
                found = obj;
                return false; // return false in $.each() stops the loop (like 'break')
            }
        });

        return found;
    }


    /**
     * Build the HTML content of the panel based on a JSON object.
     *
     * @param {object} obj   – The JSON object with the data
     * @param {string} svgId – The SVG ID of the clicked region (for debug/info)
     */
    function renderPanel(obj, svgId) {
        var fields = svgmlData.displayFields; // Array of field names to display
        var html   = '';

        // If no fields are configured, show ALL fields of the object
        if (!fields || fields.length === 0) {
            fields = Object.keys(obj);
        }

        // Build a <dl> (description list): term + definition per field
        html += '<dl class="svgml-data-list">';

        $.each(fields, function(i, fieldName) {
            // Check if the field exists in the object
            if (!obj.hasOwnProperty(fieldName)) {
                return true; // Skip this field (return true in $.each = 'continue')
            }

            var value = obj[fieldName];

            // Skip empty values
            if (value === null || value === undefined || value === '') {
                return true;
            }

            // Make the field name readable: replace underscores and hyphens with spaces
            // and capitalize the first letter
            var label = fieldName
                .replace(/[_-]/g, ' ')  // Replace _ and - with space
                .replace(/\b\w/g, function(c) { return c.toUpperCase(); }); // CamelCase

            // Build the HTML for this field
            html += '<div class="svgml-field">';
            html += '<dt class="svgml-field-label">' + svgml.escapeHtml(label) + '</dt>';
            html += '<dd class="svgml-field-value">' + formatValue(value) + '</dd>';
            html += '</div>';
        });

        html += '</dl>';

        // If no field produced any output
        if (html === '<dl class="svgml-data-list"></dl>') {
            html = '<p class="svgml-panel-empty">Geen velden om weer te geven. ' +
                   'Controleer de <em>Weergave</em>-instellingen.</p>';
        }

        // Put the content in the panel
        $panelContent.html(html);
    }

    /**
     * Format a value for display in the panel.
     * Detects URLs, numbers, etc. and formats them appropriately.
     *
     * @param  {*}      value – The value to format
     * @returns {string}      – HTML string
     */
    function formatValue(value) {
        // If it is an array, show as comma-separated list
        if (Array.isArray(value)) {
            return svgml.escapeHtml(value.join(', '));
        }

        // If it is an object, show as JSON (for debugging)
        if (typeof value === 'object' && value !== null) {
            return '<code>' + svgml.escapeHtml(JSON.stringify(value)) + '</code>';
        }

        var strValue = String(value);

        // If it is a URL (starts with http:// or https://), make a link
        if (/^https?:\/\//i.test(strValue)) {
            return '<a href="' + svgml.escapeHtml(strValue) + '" target="_blank" rel="noopener">' +
                   svgml.escapeHtml(strValue) + '</a>';
        }

        // Plain text: escape HTML characters to prevent XSS
        return svgml.escapeHtml(strValue);
    }

    /**
     * Mark the clicked region as active and remove the marking from the previous one.
     *
     * @param {jQuery} $region – The jQuery object of the clicked region
     */
    function setActiveRegion($region) {
        // Remove the active class from the previous region
        if ($activeRegion) {
            $activeRegion.removeClass('svgml-region-active');
        }
        // Add the active class to the new region
        $region.addClass('svgml-region-active');
        $activeRegion = $region;
    }

    /**
     * Open the info panel with an animation.
     */
    function openPanel() {
        console.log('[SVGML] 📋 openPanel called. $panel length:', $panel.length, '| currently visible:', $panel.is(':visible'));
        $panel
            .removeAttr('aria-hidden')
            .addClass('svgml-panel-open')
            .stop(true, true)
            .fadeIn(200);
    }

    /**
     * Close the info panel and remove the active marking from the region.
     */
    function closePanel() {
        $panel
            .attr('aria-hidden', 'true')
            .removeClass('svgml-panel-open')
            .fadeOut(200);

        // Remove the active class from the selected region
        if ($activeRegion) {
            $activeRegion.removeClass('svgml-region-active');
            $activeRegion = null;
        }
    }


    // ── SVG → SIDEBAR HOVER SYNC ────────────────────────────────────────────
    $svg.on('mouseenter', '[id]', function() {
        var svgId  = $(this).attr('id');
        console.log('[SVGML] 🟡 Hover enter. svgId:', svgId, '| in mapping:', svgmlData.mapping.hasOwnProperty(svgId));
        if (excludedIds.indexOf(svgId) !== -1) return;
        var jsonId = svgmlData.mapMode === 'manual'
            ? svgId
            : (svgmlData.mapping[svgId] || null);
        if (!jsonId) return;
        $('.svgml-overview-item[data-json-id="' + jsonId + '"]').addClass('svgml-item-hover');
    });

    $svg.on('mouseleave', '[id]', function() {
        $('.svgml-overview-item').removeClass('svgml-item-hover');
    });

    // ── MULTI-LAYER SUPPORT (Floor/viewpoint switching) ──────────────────────
    // Allow users to switch between different images and their polygons.

    // Button switcher
    $(document).on('click', '.svgml-layer-btn', function() {
        var layerIndex = $(this).data('layer');
        switchLayer(layerIndex);
        $('.svgml-layer-btn').removeClass('svgml-layer-btn-active');
        $(this).addClass('svgml-layer-btn-active');
    });

    // Dropdown switcher
    $(document).on('change', '.svgml-layer-select', function() {
        var layerIndex = $(this).val();
        switchLayer(parseInt(layerIndex, 10));
    });

    // Custom switcher option
    $(document).on('click', '.svgml-layer-option', function() {
        var layerIndex = $(this).data('layer');
        switchLayer(layerIndex);
        $('.svgml-layer-option').removeClass('svgml-layer-option-active');
        $(this).addClass('svgml-layer-option-active');
    });

    /**
     * Switch to another layer (hides current, shows the new one)
     * @param {number} index – The index of the layer to go to
     */
    function switchLayer(index) {
        var $target = $('.svgml-layer[data-layer="' + index + '"]');
        if ( ! $target.length || $target.is(':visible') ) {
            return;
        }

        var $current = $('.svgml-layer:visible').not($target);
        if ( $current.length ) {
            $current.stop(true, true).fadeOut(220, function() {
                $target.css('display', 'inline-block').hide().fadeIn(220);
            });
        } else {
            $target.css('display', 'inline-block').hide().fadeIn(220);
        }
    }

    }); // End jQuery(document).ready

})(jQuery);
