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

jQuery(document).ready(function($) {

    'use strict';

    // ── CHECK: Is the data available? ────────────────────────────────────
    // If svgmlData does not exist, the shortcode probably is not on this page.
    if (typeof svgmlData === 'undefined') {
        return; // Stop the script
    }

    // Ensure excludedIds is always an array, even if the option is missing
    var excludedIds = svgmlData.excludedIds || [];

    // ── DOM REFERENCES ──────────────────────────────────────────────────────
    var $container    = $('.svgml-container');   // The outer wrapper
    var $svg          = $('.svgml-svg');          // The SVG element itself
    var $panel        = $('#svgml-panel');        // The info panel
    var $panelContent = $('#svgml-panel-content');// The content of the panel
    var $closeBtn     = $('#svgml-panel-close'); // The close button

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


    // ── CLICK ON SVG REGIONS ───────────────────────────────────────────────

    // We use event delegation: we listen on the SVG wrapper and catch
    // clicks on child elements. This also works for elements added later
    // and is more efficient than setting a listener on each element separately.
    //
    // '[id]' is a CSS selector that selects all elements with an id attribute.
    $svg.on('click', '[id]', function(e) {

        e.stopPropagation(); // Prevent the click event from bubbling to the SVG itself

        var $clicked  = $(this);
        var svgId     = $clicked.attr('id'); // The id attribute of the clicked element

        // Check if this SVG id is excluded from interaction.
        // indexOf() returns -1 if the value is not in the array.
        if (excludedIds.indexOf(svgId) !== -1) {
            return; // Excluded – do not show panel, do nothing
        }

        // Check if this SVG id has a mapping
        if (!svgmlData.mapping.hasOwnProperty(svgId)) {
            // No mapping for this element – do nothing
            return;
        }

        // Get the JSON object ID from the mapping
        var jsonObjectId = svgmlData.mapping[svgId];

        // Look up the corresponding JSON object in the dataset
        var jsonObject = findJsonObject(jsonObjectId);

        if (!jsonObject) {
            // No JSON object found for this ID
            $panelContent.html(
                '<p class="svgml-panel-not-found">' +
                'No data found for ID: <em>' + svgId + '</em>' +
                '</p>'
            );
            openPanel();
            return;
        }

        // Mark the active region visually
        setActiveRegion($clicked);

        // Build the panel content and show the panel (legacy renderer)
        renderPanel(jsonObject, svgId);
        openPanel();

        // Emit a custom event so panel-renderer.js (and other scripts)
        // can respond to the click without direct coupling.
        // $.Event() creates a jQuery event object; trigger() fires it.
        $(document).trigger('svgmlRegionClick', [{
            jsonObject: jsonObject,  // The complete JSON object of the region
            svgId:      svgId,       // The SVG id of the clicked region
            $region:    $clicked     // The jQuery element of the region
        }]);
    });


    // ── CLOSE PANEL ────────────────────────────────────────────────────────

    // Click on the close button
    $closeBtn.on('click', function() {
        closePanel();
    });

    // Click outside the panel (on the SVG or the container) closes the panel
    $svg.on('click', function() {
        // This event reaches the SVG itself only if there was NO click on an [id] element
        // (because stopPropagation() is called above)
        closePanel();
    });

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
            html = '<p class="svgml-panel-empty">No fields to display. ' +
                   'Check the <em>Display</em> settings.</p>';
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
        $panel
            .removeAttr('aria-hidden') // Accessibility: panel is now visible
            .addClass('svgml-panel-open')
            .stop(true, true)          // Cancel running jQuery animations
            .fadeIn(200);              // Fade in over 200ms
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


    // ── MULTI-LAYER SUPPORT (Floor/viewpoint switching) ──────────────────────
    // Allow users to switch between different images and their polygons.

    // Button switcher
    $('.svgml-layer-btn').on('click', function() {
        var layerIndex = $(this).data('layer');
        switchLayer(layerIndex);
        $('.svgml-layer-btn').removeClass('svgml-layer-btn-active');
        $(this).addClass('svgml-layer-btn-active');
    });

    // Dropdown switcher
    $('.svgml-layer-select').on('change', function() {
        var layerIndex = $(this).val();
        switchLayer(parseInt(layerIndex, 10));
    });

    // Custom switcher option
    $('.svgml-layer-option').on('click', function() {
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
