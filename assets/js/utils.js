/**
 * SVG Map Lite — Shared Utilities
 *
 * Common helper functions used by multiple scripts (frontend.js, filters.js,
 * panel-renderer.js, admin.js). Extracted to avoid code duplication.
 *
 * This file MUST be enqueued before any script that calls these functions.
 * It attaches helpers to the global `window.svgml` namespace.
 */

(function() {

    'use strict';

    // Create global namespace
    window.svgml = window.svgml || {};

    /**
     * Normalise a JSON response into a usable array of objects.
     *
     * Handles four cases:
     *   1. Already an array — return as-is.
     *   2. Config key override — user set a specific key in Settings (e.g. 'spaces').
     *   3. Known wrapper keys — common API patterns like 'data', 'items', 'results'.
     *   4. Largest-array heuristic — pick the biggest non-empty object-array.
     *
     * Works in both admin (svgmlAdmin) and frontend (svgmlData) contexts by
     * checking whichever global is available for the jsonArrayKey setting.
     *
     * @param  {*}           raw — The raw JSON value (parsed response)
     * @returns {Array|null}     — A usable array, or null if none found
     */
    window.svgml.normalizeToArray = function( raw ) {

        // Case 1: already an array — return directly
        if ( Array.isArray(raw) ) {
            return raw;
        }

        // Not an object: nothing we can do
        if ( typeof raw !== 'object' || raw === null ) {
            console.warn('SVG Map Lite: JSON is not an array or object.', raw);
            return null;
        }

        // Case 2: manual override via settings (e.g. 'spaces')
        // Needed for feeds like: { "id": 7906, "spaces": [ {unit1}, {unit2} ] }
        var configKey = '';
        if ( typeof svgmlData !== 'undefined' && svgmlData && svgmlData.jsonArrayKey ) {
            configKey = svgmlData.jsonArrayKey;
        } else if ( typeof svgmlAdmin !== 'undefined' && svgmlAdmin && svgmlAdmin.jsonArrayKey ) {
            configKey = svgmlAdmin.jsonArrayKey;
        }
        if ( configKey && Array.isArray( raw[ configKey ] ) ) {
            console.log('SVG Map Lite: JSON array found via configured key "' + configKey + '".');
            return raw[ configKey ];
        }

        // Case 3: known wrapper keys — skip empty arrays
        // (e.g. 'subtypes: []' should not win over 'spaces: [{...}]')
        var knownKeys = [ 'assets', 'data', 'items', 'results', 'objects', 'features',
                          'records', 'list', 'collection', 'entries', 'value', 'spaces',
                          'units', 'properties', 'lots', 'houses', 'apartments' ];
        for ( var i = 0; i < knownKeys.length; i++ ) {
            var candidate = raw[ knownKeys[i] ];
            if ( Array.isArray( candidate ) && candidate.length > 0 ) {
                console.log('SVG Map Lite: JSON array found under known key "' + knownKeys[i] + '".');
                return candidate;
            }
        }

        // Case 4: pick the largest non-empty object-array (skip scalar arrays)
        var best = null;
        var bestSize = 0;
        var topKeys = Object.keys(raw);
        for ( var j = 0; j < topKeys.length; j++ ) {
            var val = raw[ topKeys[j] ];
            if ( Array.isArray(val) && val.length > bestSize ) {
                // Only arrays of objects, not arrays of strings/numbers
                if ( val.length > 0 && typeof val[0] === 'object' && val[0] !== null ) {
                    best     = val;
                    bestSize = val.length;
                    console.log('SVG Map Lite: JSON array auto-detected under key "' + topKeys[j] + '" (' + bestSize + ' items).');
                }
            }
        }
        if ( best ) return best;

        // Nothing found — log available keys for diagnostics
        console.warn('SVG Map Lite: No array found in JSON. Available top-level keys:', topKeys);
        return null;
    };

    /**
     * Escape HTML characters to prevent XSS when inserting user data into the DOM.
     *
     * @param  {string} text — Text to escape
     * @returns {string}     — Safe HTML string
     */
    window.svgml.escapeHtml = function( text ) {
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    };

})();
