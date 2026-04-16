/**
 * SVG Map Lite – Filters (jQuery + noUiSlider)
 *
 * This script manages the filter bar above the SVG map.
 * It:
 *  1. Populates dropdown filters with unique values from the JSON dataset.
 *  2. Creates range sliders (noUiSlider) for numeric fields.
 *  3. Listens for filter changes and updates the map:
 *     - Regions that DO NOT match the filters get the class svgml-region-dimmed.
 *     - Regions that DO match the filters are visible normally.
 *  4. Provides a reset button to clear all filters.
 *
 * Requirements:
 *   - noUiSlider is loaded (via CDN, registered in PHP)
 *   - svgmlData is available (via wp_add_inline_script in PHP)
 *   - jQuery is available (WordPress standard)
 *
 * Global variables (from svgmlData):
 *   svgmlData.filterFields – Array of filter configurations: [{field, type, label}, ...]
 *   svgmlData.jsonData     – Complete JSON dataset
 *   svgmlData.jsonIdField  – ID field in the JSON
 *   svgmlData.mapping      – { 'svg-id': 'json-object-id', ... }
 */

jQuery(document).ready(function($) {

    'use strict';

    // ── CHECK ──────────────────────────────────────────────────────────────
    if (typeof svgmlData === 'undefined') return;
    if (!svgmlData.filterFields || svgmlData.filterFields.length === 0) return;

    // Check if noUiSlider is available
    var hasNoUiSlider = (typeof noUiSlider !== 'undefined');

    // ── PREPARE DATA ─────────────────────────────────────────────────────────
    var isManual     = (svgmlData.mapMode === 'manual');
    var $svg         = $('.svgml-svg');
    var idField      = svgmlData.jsonIdField || 'id';
    var mapping      = svgmlData.mapping     || {};
    var filterFields = svgmlData.filterFields;

    // dataObjects: flat array used by all value-collection loops (dropdown, range, search, buttons)
    var dataObjects  = [];
    // regionLookup: { svgId → dataObject } used by svgml_applyFilters()
    var regionLookup = {};

    if (isManual) {
        var manualData = svgmlData.manualData || {};
        $.each(manualData, function(svgId, obj) {
            dataObjects.push(obj);
            regionLookup[svgId] = obj;
        });
        if (dataObjects.length === 0) return; // No manual data → nothing to filter
    } else {
        var jsonData = svgml.normalizeToArray(svgmlData.jsonData);
        if (!jsonData) return; // No JSON data → nothing to filter
        var jsonLookup = {};
        $.each(jsonData, function(i, obj) {
            var objId = String(obj[idField] || '');
            if (objId) jsonLookup[objId] = obj;
            dataObjects.push(obj);
        });
        // Build regionLookup via mapping → jsonLookup
        $.each(mapping, function(svgId, jsonId) {
            var obj = jsonLookup[String(jsonId)];
            if (obj) regionLookup[svgId] = obj;
        });
    }

    // Store the current filter state: { fieldname: active-value }
    // For dropdowns the value is a string, for ranges it is an array [min, max].
    var filterState = {};


    // ── INITIALIZE FILTERS ─────────────────────────────────────────────────

    $.each(filterFields, function(i, filter) {
        var field = filter.field || '';
        var type  = filter.type  || 'dropdown';

        if (!field) return; // Skip if no field name

        if (type === 'dropdown') {
            svgml_initDropdown(field);
        } else if (type === 'range' && hasNoUiSlider) {
            svgml_initRangeSlider(field);
        } else if (type === 'range' && !hasNoUiSlider) {
            // Fallback if noUiSlider is not loaded: show a text input
            console.warn('SVG Map Lite: noUiSlider is not loaded. Range filter falls back to text input.');
            svgml_initRangeFallback(field);
        } else if (type === 'search') {
            svgml_initSearch(field);
        } else if (type === 'input') {
            svgml_initInput(field, filter);
        } else if (type === 'buttons') {
            svgml_initButtons(field, filter);
        }
    });


    // ── DROPDOWN FILTER ────────────────────────────────────────────────────────

    /**
     * Initialize a dropdown filter for a specific field.
     * Retrieves all unique values from the JSON data and populates the dropdown.
     *
     * @param {string} field – The field name in the JSON object
     */
    function svgml_initDropdown(field) {
        // Find the select that PHP has already created in the HTML
        var $select = $('#svgml-select-' + field);
        if (!$select.length) return;

        // Collect all unique non-empty values for this field
        var values = [];
        $.each(dataObjects, function(i, obj) {
            var val = obj[field];
            if (val !== null && val !== undefined && val !== '' && values.indexOf(String(val)) === -1) {
                values.push(String(val));
            }
        });

        // Sort the values alphabetically
        values.sort();

        // Add the options to the dropdown
        $.each(values, function(i, val) {
            $select.append('<option value="' + svgml.escapeHtml(val) + '">' + svgml.escapeHtml(val) + '</option>');
        });

        // Listen for changes
        $select.on('change', function() {
            var selected = $(this).val();

            // Empty or "All" selected → remove the filter for this field
            if (!selected) {
                delete filterState[field];
            } else {
                // Store the selected value as an active filter
                filterState[field] = { type: 'dropdown', value: selected };
            }

            // Apply the filters to the map
            svgml_applyFilters();
        });
    }


    // ── RANGE SLIDER FILTER ────────────────────────────────────────────────────

    /**
     * Initialize a noUiSlider range filter for a specific field.
     * Automatically detects the min and max values from the JSON data.
     *
     * @param {string} field – The field name in the JSON object
     */
    function svgml_initRangeSlider(field) {
        // Find the container that PHP has already created in the HTML
        var $container = $('#svgml-range-' + field);
        if (!$container.length) return;

        // Find the filter item (the parent) for the min/max labels
        var $filterItem = $container.closest('.svgml-filter-item');
        var $minLabel   = $filterItem.find('.svgml-range-min');
        var $maxLabel   = $filterItem.find('.svgml-range-max');

        // Read prefix/suffix from filterFields config
        var filterConfig = {};
        $.each(svgmlData.filterFields || [], function(i, fc) {
            if (fc.field === field) { filterConfig = fc; return false; }
        });
        var prefix = filterConfig.prefix || '';
        var suffix = filterConfig.suffix || '';

        function svgml_formatLabel(val) {
            return prefix + svgml_formatNumber(val) + suffix;
        }

        // Collect all numeric values for this field
        var values = [];
        $.each(dataObjects, function(i, obj) {
            var num = svgml_parseNumeric(obj[field]);
            if (!isNaN(num)) {
                values.push(num);
            }
        });

        // If there are no numeric values, do not create a slider
        if (values.length === 0) return;

        // Calculate min and max
        var minVal = Math.floor(Math.min.apply(null, values));
        var maxVal = Math.ceil(Math.max.apply(null, values));

        // If min and max are the same, a slider makes no sense
        if (minVal === maxVal) return;

        // Create the noUiSlider on the container element
        // noUiSlider.create() is the API of the noUiSlider library
        var sliderEl = $container[0]; // noUiSlider works with the native DOM element

        noUiSlider.create(sliderEl, {
            start:   [minVal, maxVal],   // Start values: the full range
            connect: true,               // Visually connect the two handles
            range: {
                'min': minVal,
                'max': maxVal
            },
            step: 1,                     // Step of 1 (adjustable)
            // Format the displayed values (rounded to whole numbers)
            tooltips: [
                { to: function(v) { return prefix + Math.round(v) + suffix; } },
                { to: function(v) { return prefix + Math.round(v) + suffix; } }
            ]
        });

        // Update the min/max labels with the start values
        $minLabel.text(svgml_formatLabel(minVal));
        $maxLabel.text(svgml_formatLabel(maxVal));

        // Store the absolute min/max for the reset function
        $container.data('absMin', minVal);
        $container.data('absMax', maxVal);

        // Listen for slider updates
        // 'update' is fired while the user drags
        // 'change' is fired when the user releases
        sliderEl.noUiSlider.on('change', function(values) {
            var currentMin = parseFloat(values[0]);
            var currentMax = parseFloat(values[1]);

            // Update the min/max labels
            $minLabel.text(svgml_formatLabel(currentMin));
            $maxLabel.text(svgml_formatLabel(currentMax));

            // If the slider is at the full range, the filter is not active
            if (currentMin <= minVal && currentMax >= maxVal) {
                delete filterState[field];
            } else {
                filterState[field] = { type: 'range', min: currentMin, max: currentMax };
            }

            svgml_applyFilters();
        });

        // Update labels while the user drags too (for immediate feedback)
        sliderEl.noUiSlider.on('update', function(values) {
            $minLabel.text(svgml_formatLabel(parseFloat(values[0])));
            $maxLabel.text(svgml_formatLabel(parseFloat(values[1])));
        });
    }

    /**
     * Fallback for range filter if noUiSlider is not available.
     * Shows a simple text input as a replacement.
     *
     * @param {string} field – The field name
     */
    function svgml_initRangeFallback(field) {
        var $container = $('#svgml-range-' + field);
        if (!$container.length) return;

        $container.replaceWith(
            '<input type="text" class="svgml-filter-fallback" ' +
            'placeholder="Search ' + svgml.escapeHtml(field) + '" ' +
            'id="svgml-range-' + svgml.escapeHtml(field) + '">'
        );

        // Get the new element after the replacement
        var $input = $('#svgml-range-' + field);

        $input.on('input', function() {
            var val = $(this).val().trim();
            if (!val) {
                delete filterState[field];
            } else {
                filterState[field] = { type: 'text', value: val };
            }
            svgml_applyFilters();
        });
    }


    // ── SEARCH FILTER (AUTOCOMPLETE) ──────────────────────────────────────────

    /**
     * Initialize a search filter with autocomplete for a specific field.
     * Provides suggestions based on all unique values from the JSON data.
     *
     * @param {string} field – The field name in the JSON object
     */
    function svgml_initSearch(field) {
        var $input = $('#svgml-search-' + field);
        var $list  = $('#svgml-autocomplete-' + field);
        if (!$input.length) return;

        // Collect all unique values for this field
        var allValues = [];
        $.each(dataObjects, function(i, obj) {
            var val = obj[field];
            if (val !== null && val !== undefined && val !== '' && allValues.indexOf(String(val)) === -1) {
                allValues.push(String(val));
            }
        });
        allValues.sort();

        // Input event: filter and show autocomplete
        $input.on('input', function() {
            var query = $(this).val().trim().toLowerCase();
            $list.empty();

            if (!query) {
                $list.hide();
                delete filterState[field];
                svgml_applyFilters();
                return;
            }

            var matches = $.grep(allValues, function(v) {
                return v.toLowerCase().indexOf(query) !== -1;
            });

            if (matches.length === 0) {
                $list.hide();
                return;
            }

            $.each(matches.slice(0, 10), function(i, val) {
                $list.append(
                    '<li class="svgml-autocomplete-item" data-value="' + svgml.escapeHtml(val) + '">' +
                    svgml_highlightMatch(val, query) +
                    '</li>'
                );
            });
            $list.show();
        });

        // Click on autocomplete item
        $list.on('click', '.svgml-autocomplete-item', function() {
            var val = $(this).data('value');
            $input.val(val);
            $list.hide();
            filterState[field] = { type: 'search', value: String(val) };
            svgml_applyFilters();
        });

        // Hide autocomplete on click outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.svgml-search-wrap').length) {
                $list.hide();
            }
        });

        // Enter: use current text as filter
        $input.on('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $list.hide();
                var val = $(this).val().trim();
                if (val) {
                    filterState[field] = { type: 'search', value: val };
                } else {
                    delete filterState[field];
                }
                svgml_applyFilters();
            }
        });
    }

    /**
     * Highlight the search term in a value (bold).
     * Used in the autocomplete suggestions.
     *
     * @param {string} text  – The full text
     * @param {string} query – The search term to highlight
     * @returns {string}     – HTML with <strong> around the match
     */
    function svgml_highlightMatch(text, query) {
        var idx = text.toLowerCase().indexOf(query);
        if (idx === -1) return svgml.escapeHtml(text);
        var before = text.substring(0, idx);
        var match  = text.substring(idx, idx + query.length);
        var after  = text.substring(idx + query.length);
        return svgml.escapeHtml(before) + '<strong>' + svgml.escapeHtml(match) + '</strong>' + svgml.escapeHtml(after);
    }


    // ── INPUT FILTER (single text / min-max) ──────────────────────────────────

    /**
     * Initialize an input filter.
     * mode 'single': single text field, exact/contains match.
     * mode 'minmax': two fields (Min, Max), numeric range match via svgml_parseNumeric.
     *
     * @param {string} field        – The field name in the JSON object
     * @param {object} filterConfig – Filter configuration from PHP (input_mode, etc.)
     */
    function svgml_initInput(field, filterConfig) {
        var mode = filterConfig.input_mode || 'single';
        var $wrap = $('#svgml-input-' + field);
        if (!$wrap.length) return;

        if (mode === 'minmax') {
            var $min = $wrap.find('.svgml-filter-input-min');
            var $max = $wrap.find('.svgml-filter-input-max');

            function updateMinMax() {
                var minVal = $min.val().trim();
                var maxVal = $max.val().trim();
                if (!minVal && !maxVal) {
                    delete filterState[field];
                } else {
                    filterState[field] = { type: 'input-minmax', min: minVal, max: maxVal };
                }
                svgml_applyFilters();
            }

            $min.on('input', updateMinMax);
            $max.on('input', updateMinMax);

            // Store reference for reset
            $wrap.data('inputMode', 'minmax');

        } else {
            $wrap.on('input', function() {
                var val = $(this).val().trim();
                if (!val) {
                    delete filterState[field];
                } else {
                    filterState[field] = { type: 'input-single', value: val };
                }
                svgml_applyFilters();
            });

            $wrap.data('inputMode', 'single');
        }
    }


    // ── BUTTONS FILTER ─────────────────────────────────────────────────────

    /**
     * Initialize a button filter for a specific field.
     * Shows a row of buttons with unique values as options.
     *
     * @param {string} field        – The field name in the JSON object
     * @param {object} filterConfig – Filter configuration from PHP (button_source, etc.)
     */
    function svgml_initButtons(field, filterConfig) {
        var $container = $('#svgml-buttons-' + field);
        if (!$container.length) return;

        var source     = $container.data('source') || 'auto';
        var showCount  = $container.data('show-count') === '1' || $container.data('show-count') === 1;
        var customVals = String($container.data('custom-values') || '');

        var values = [];

        if (source === 'custom' && customVals) {
            // Use the values specified by the administrator
            values = $.map(customVals.split(','), function(v) { return $.trim(v); });
            values = $.grep(values, function(v) { return v !== ''; });
        } else {
            // Auto: collect all unique values from the data
            $.each(dataObjects, function(i, obj) {
                var val = obj[field];
                if (val !== null && val !== undefined && val !== '' && values.indexOf(String(val)) === -1) {
                    values.push(String(val));
                }
            });
            values.sort();
        }

        // Count the number of objects per value (for the optional counter)
        var counts = {};
        if (showCount) {
            $.each(dataObjects, function(i, obj) {
                var val = String(obj[field] || '');
                if (val) counts[val] = (counts[val] || 0) + 1;
            });
        }

        // "All" button
        $container.append(
            '<button type="button" class="svgml-filter-btn svgml-filter-btn-active" data-value="">Alles</button>'
        );

        // Button per value
        $.each(values, function(i, val) {
            var label = svgml.escapeHtml(val);
            if (showCount && counts[val]) {
                label += ' <span class="svgml-btn-count">(' + counts[val] + ')</span>';
            }
            $container.append(
                '<button type="button" class="svgml-filter-btn" data-value="' + svgml.escapeHtml(val) + '">' +
                label + '</button>'
            );
        });

        // Click handler
        $container.on('click', '.svgml-filter-btn', function() {
            var val = $(this).data('value');
            $container.find('.svgml-filter-btn').removeClass('svgml-filter-btn-active');
            $(this).addClass('svgml-filter-btn-active');

            if (!val && val !== 0) {
                delete filterState[field];
            } else {
                filterState[field] = { type: 'buttons', value: String(val) };
            }
            svgml_applyFilters();
        });
    }


    // ── RESET BUTTON ──────────────────────────────────────────────────────────

    // Click on the reset button: clear all active filters
    $('#svgml-filter-reset').on('click', function() {
        svgml_resetAllFilters();
    });

    /**
     * Reset all filters to their initial values.
     */
    function svgml_resetAllFilters() {
        // Empty the filter state
        filterState = {};

        // Reset all dropdowns to "All"
        $('#svgml-filters-bar .svgml-filter-select').val('');

        // Reset all sliders to their original min/max
        $('#svgml-filters-bar .svgml-range-slider').each(function() {
            var sliderEl = this;
            if (sliderEl.noUiSlider) {
                var absMin = $(this).data('absMin');
                var absMax = $(this).data('absMax');
                sliderEl.noUiSlider.set([absMin, absMax]);
            }
        });

        // Reset input filters (single + minmax)
        $('#svgml-filters-bar .svgml-filter-input-single').val('');
        $('#svgml-filters-bar .svgml-input-minmax').each(function() {
            $(this).find('.svgml-filter-input-min, .svgml-filter-input-max').val('');
        });

        // Reset search fields
        $('#svgml-filters-bar .svgml-filter-search').val('');
        $('#svgml-filters-bar .svgml-autocomplete-list').hide().empty();

        // Reset button filters
        $('#svgml-filters-bar .svgml-filter-buttons').each(function() {
            $(this).find('.svgml-filter-btn').removeClass('svgml-filter-btn-active');
            $(this).find('.svgml-filter-btn').first().addClass('svgml-filter-btn-active');
        });

        // Apply filters to the map (remove all dimmings)
        svgml_applyFilters();
    }


    // ── APPLY FILTERS ─────────────────────────────────────────────────────

    /**
     * Apply the active filters to the SVG map.
     * Regions that do not match the filters are dimmed (svgml-region-dimmed).
     */
    function svgml_applyFilters() {
        var hasActiveFilters = Object.keys(filterState).length > 0;

        // Loop through all regions that have a data object (works for both JSON and manual mode)
        $.each(regionLookup, function(svgId, obj) {
            var $region = $svg.find('#' + svgId);
            if (!$region.length) return; // Region not in SVG – skip

            // Does not apply to excluded regions
            if ($region.hasClass('svgml-region-excluded')) return;

            if (!hasActiveFilters) {
                // No active filters → all regions visible normally
                $region.removeClass('svgml-region-dimmed');
                return;
            }

            if (!obj) {
                // No data object found → dim (because we cannot check)
                $region.addClass('svgml-region-dimmed');
                return;
            }

            // Check if the object matches all active filters
            var matchesAll = svgml_objectMatchesFilters(obj);

            if (matchesAll) {
                $region.removeClass('svgml-region-dimmed');
            } else {
                $region.addClass('svgml-region-dimmed');
            }
        });
    }

    /**
     * Check if a JSON object matches all active filters.
     *
     * @param {object} obj – The JSON object
     * @returns {boolean}  – true if the object matches all filters
     */
    function svgml_objectMatchesFilters(obj) {
        // Loop through all active filters
        for (var field in filterState) {
            if (!filterState.hasOwnProperty(field)) continue;

            var filter = filterState[field];
            var value  = obj[field];

            // If the field does not exist → does not match
            if (value === null || value === undefined) return false;

            if (filter.type === 'dropdown') {
                // Compare as string (case-insensitive for robustness)
                if (String(value).toLowerCase() !== String(filter.value).toLowerCase()) {
                    return false;
                }

            } else if (filter.type === 'range') {
                var num = svgml_parseNumeric(value);
                if (isNaN(num)) return false;
                if (num < filter.min || num > filter.max) return false;

            } else if (filter.type === 'search') {
                // Check if the value contains the search term (case-insensitive)
                if (String(value).toLowerCase().indexOf(String(filter.value).toLowerCase()) === -1) {
                    return false;
                }

            } else if (filter.type === 'buttons') {
                // Exact match (case-insensitive) like dropdown
                if (String(value).toLowerCase() !== String(filter.value).toLowerCase()) {
                    return false;
                }

            } else if (filter.type === 'text') {
                // Text fallback: check if the value contains the search term
                if (String(value).toLowerCase().indexOf(filter.value.toLowerCase()) === -1) {
                    return false;
                }

            } else if (filter.type === 'input-single') {
                // Contains match (case-insensitive)
                if (String(value).toLowerCase().indexOf(filter.value.toLowerCase()) === -1) {
                    return false;
                }

            } else if (filter.type === 'input-minmax') {
                var num = svgml_parseNumeric(value);
                if (isNaN(num)) return false;
                if (filter.min !== '' && num < svgml_parseNumeric(filter.min)) return false;
                if (filter.max !== '' && num > svgml_parseNumeric(filter.max)) return false;
            }
        }

        return true; // All filters pass
    }


    // ── HELPER FUNCTIONS ──────────────────────────────────────────────────────────

    /**
     * Parse a raw value (possibly with currency symbols, thousand separators,
     * or European comma-decimal format) into a JavaScript float.
     *
     * Handles:
     *   "€ 1.234,56"  → 1234.56  (EU: dot = thousands, comma = decimal)
     *   "$1,234.56"   → 1234.56  (US: comma = thousands, dot = decimal)
     *   "4921.00"     → 4921     (plain float)
     *   "45 m²"       → 45       (trailing unit)
     *
     * @param {*} raw – Any value (string, number, …)
     * @returns {number} – Parsed float, or NaN if not numeric
     */
    function svgml_parseNumeric(raw) {
        // Keep only digits, comma, dot and leading minus
        var s = String(raw).replace(/[^0-9.,-]/g, '');
        if (!s || s === '-') return NaN;

        var lastDot   = s.lastIndexOf('.');
        var lastComma = s.lastIndexOf(',');

        if (lastDot !== -1 && lastComma !== -1) {
            // Both separators present: whichever comes last is the decimal separator
            if (lastDot > lastComma) {
                // US format: "1,234.56" → remove commas
                s = s.replace(/,/g, '');
            } else {
                // EU format: "1.234,56" → remove dots, replace comma with dot
                s = s.replace(/\./g, '').replace(',', '.');
            }
        } else if (lastComma !== -1) {
            // Only comma: if exactly 3 digits follow, treat as thousands separator
            var afterComma = s.substring(lastComma + 1);
            if (/^\d{3}$/.test(afterComma)) {
                s = s.replace(/,/g, '');
            } else {
                s = s.replace(',', '.');
            }
        }
        // Only dot (or neither): parseFloat handles it directly

        return parseFloat(s);
    }

    /**
     * Format a number with periods as thousand separators.
     * E.g. 4921 → "4.921"
     *
     * @param {number} num – The number to format
     * @returns {string}   – Formatted string
     */
    function svgml_formatNumber(num) {
        return Math.round(num).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }


}); // End jQuery(document).ready
