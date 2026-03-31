/**
 * SVG Map Lite – Admin JavaScript (jQuery)
 *
 * This script handles:
 *  1. SVG upload via WordPress Media Library
 *  2. AJAX request to parse the SVG and extract IDs
 *  3. Live confirmation on the Region Mapping page: as soon as you type a JSON object ID,
 *     the script looks up the object and displays the "name" value.
 *
 * Available global variables (passed via wp_localize_script):
 *   svgmlAdmin.ajaxUrl    – URL of the WordPress AJAX endpoint
 *   svgmlAdmin.nonce      – Security code for the AJAX request
 *   svgmlAdmin.svgId      – Currently stored attachment ID (may be empty)
 *   svgmlAdmin.strings    – Texts for the UI
 *   svgmlAdmin.jsonData   – Full JSON dataset (only on mapping page)
 *   svgmlAdmin.jsonIdField – Name of the ID field in the JSON (only on mapping page)
 */

// jQuery(document).ready() ensures the code only runs after the page
// is fully loaded. We use $ as an alias for jQuery.
jQuery(document).ready(function($) {

    'use strict'; // Strict mode: prevents typical JavaScript errors

    // ── VARIABLES ────────────────────────────────────────────────────────────

    // wp.media is the WordPress Media Library API.
    // We create one frame and reuse it.
    var mediaFrame = null;

    // References to DOM elements ($ prevents repeated DOM searching)
    var $uploadBtn       = $('#svgml-upload-btn');
    var $removeBtn       = $('#svgml-remove-svg');
    var $attachmentInput = $('#svgml_svg_attachment_id');
    var $svgPreview      = $('#svgml-svg-preview');
    var $idsStatus       = $('#svgml-ids-status');


    // ── SELECT SVG VIA MEDIA LIBRARY ────────────────────────────────────────

    // Click on the "Select SVG" button
    $uploadBtn.on('click', function(e) {
        e.preventDefault(); // Prevent form submission

        // If the Media frame already exists, open it again without recreating
        if (mediaFrame) {
            mediaFrame.open();
            return;
        }

        // Create a new Media Library frame
        // wp.media() is the WordPress JavaScript API for the media uploader
        mediaFrame = wp.media({
            title:    svgmlAdmin.strings.selectSvg, // Title above the media window
            button:   { text: svgmlAdmin.strings.useSvg }, // Text of the "Use" button
            multiple: false,  // Only one file selectable
            library:  {
                // Filter the media library: show only SVGs
                // (this filters on mime-type in the search results)
                type: ['image/svg+xml']
            }
        });

        // Listen for the 'select' event: fires when the user
        // clicks the "Use this SVG" button
        mediaFrame.on('select', function() {

            // Get the selected file as a WordPress attachment object
            var attachment = mediaFrame.state().get('selection').first().toJSON();

            // Store the attachment ID in the hidden input field
            // This ID is sent when saving the form
            $attachmentInput.val(attachment.id);

            // Show a preview of the selected SVG
            updatePreview(attachment.url, attachment.filename);

            // Tell WordPress to parse the SVG and extract IDs
            parseSvg(attachment.id);
        });

        // Open the media window
        mediaFrame.open();
    });


    // ── REMOVE SVG ───────────────────────────────────────────────────────────

    $removeBtn.on('click', function(e) {
        e.preventDefault();

        // Clear the hidden input field
        $attachmentInput.val('');

        // Reset the preview
        $svgPreview.html(
            '<div class="svgml-no-svg">' +
            '<span class="dashicons dashicons-format-image"></span>' +
            '<p>Nog geen SVG geselecteerd</p>' +
            '</div>'
        );

        // Hide the IDs status
        $idsStatus.html('');

        // Adjust the button
        $uploadBtn.text('Selecteer SVG');
        $(this).hide();
    });


    // ── HELPER FUNCTIONS ────────────────────────────────────────────────────

    /**
     * Update the SVG preview with the newly selected image.
     *
     * @param {string} url      – The URL of the SVG in the media library
     * @param {string} filename – Filename for the alt text
     */
    function updatePreview(url, filename) {
        // Replace the contents of the preview div with an <img> element
        // We trust the URL because it comes from WordPress itself
        $svgPreview.html('<img src="' + url + '" alt="' + filename + '">');
        $uploadBtn.text('Wijzig SVG');

        // Make sure the "remove" button is visible
        if ($removeBtn.length) {
            $removeBtn.show();
        }
    }

    /**
     * Send an AJAX request to WordPress to parse the SVG.
     * WordPress then calls svgml_ajax_parse_svg() in PHP.
     *
     * @param {number} attachmentId – WordPress media attachment ID
     */
    function parseSvg(attachmentId) {

        // Show a loading indicator while we wait
        $idsStatus.html(
            '<div class="svgml-status-box svgml-status-loading">' +
            '<span class="spinner is-active"></span> ' +
            svgmlAdmin.strings.parsing +
            '</div>'
        );

        // $.ajax() is the jQuery method for asynchronous HTTP requests
        $.ajax({
            url:    svgmlAdmin.ajaxUrl,  // WordPress admin-ajax.php URL
            type:   'POST',
            data: {
                action:        'svgml_parse_svg',       // Name of the WP AJAX handler
                nonce:         svgmlAdmin.nonce,        // Security code
                attachment_id: attachmentId,            // Which file to parse
                map_id:        svgmlAdmin.mapId || 0    // Which map this belongs to
            },

            // success() is called if the AJAX request succeeded
            success: function(response) {

                if (response.success) {
                    // response.data.ids is an array of found SVG IDs
                    var ids   = response.data.ids;
                    var count = response.data.count;

                    // Build a success message with the found IDs
                    var html = '<div class="svgml-status-box svgml-status-success">' +
                        '<strong>✓ ' + count + ' regions found:</strong> ' +
                        '<code>' + ids.join(', ') + '</code>' +
                        '<br><a href="' + svgmlMappingUrl() + '">→ Go to Region Mapping</a>' +
                        '</div>';

                    $idsStatus.html(html);

                } else {
                    // response.data contains the error message from PHP
                    showError(response.data || svgmlAdmin.strings.noIds);
                }
            },

            // error() is called if the HTTP request itself failed
            // (e.g., server error or no internet connection)
            error: function(xhr, status, error) {
                showError('AJAX error: ' + error);
            }
        });
    }

    /**
     * Show an error message in the status div.
     *
     * @param {string} message – The error message to display
     */
    function showError(message) {
        $idsStatus.html(
            '<div class="svgml-status-box svgml-status-error">' +
            '✗ ' + message +
            '</div>'
        );
    }

    /**
     * Build the URL to the Region Mapping page.
     * We construct it here in JS so we don't need another PHP localize.
     *
     * @returns {string} URL to the mapping page
     */
    function svgmlMappingUrl() {
        // Get the base path of admin-ajax.php and replace 'admin-ajax.php'
        // with 'admin.php?page=svgml-mapping'
        return svgmlAdmin.ajaxUrl.replace('admin-ajax.php', 'admin.php?page=svgml-mapping');
    }


    // ── EXCLUDE CHECKBOXES ──────────────────────────────────────────────────
    //
    // When the admin checks a region as "excluded":
    //  - the entire table row is dimmed (CSS class svgml-row-excluded)
    //  - the JSON ID input field is disabled so it's clear
    //    that the mapping has no effect
    //  - the confirmation text is cleared
    //  - the label text toggles between "Exclude" and "Excluded"

    // Step 1: restore the correct state when the page loads
    // (PHP has already set the correct class and disabled attribute, but the
    //  label text is toggled below – on load it's already correct)

    // Step 2: listen for checkbox changes
    $(document).on( 'change', '.svgml-exclude-checkbox', function() {

        var $checkbox = $(this);
        var $row      = $checkbox.closest('tr');         // The entire table row
        var $input    = $row.find('.svgml-mapping-input'); // The JSON ID input field
        var $confirm  = $row.find('.svgml-confirm-name');  // The confirmation span
        var $label    = $checkbox.siblings('.svgml-exclude-text'); // The text next to the checkbox

        if ( $checkbox.is(':checked') ) {
            // Exclude region
            $row.addClass('svgml-row-excluded');
            $input.prop( 'disabled', true );  // .prop() sets a DOM property (not an attribute)
            $confirm.text('').removeClass('svgml-confirm-ok svgml-confirm-fail');
            $label.text('Excluded');
        } else {
            // Undo exclusion
            $row.removeClass('svgml-row-excluded');
            $input.prop( 'disabled', false );
            $label.text('Exclude');

            // Recalculate confirmation if an ID is already entered
            if ( $input.val().trim() ) {
                lookupAndShowName( $input );
            }
        }
    });


    // ── LIVE CONFIRMATION ON REGION MAPPING PAGE ───────────────────────────
    //
    // svgmlAdmin.jsonData is only available on the mapping page
    // (PHP only adds it there via wp_localize_script).
    // We check that first before registering the event listeners.

    if ( typeof svgmlAdmin.jsonData !== 'undefined' && svgmlAdmin.jsonData ) {

        // On load: check all fields that already have a value.
        // Skip excluded rows – they don't need confirmation.
        $('.svgml-mapping-input').each(function() {
            var $row = $(this).closest('tr');
            if ( $row.data('excluded') !== 1 && $row.data('excluded') !== '1' ) {
                lookupAndShowName( $(this) );
            }
        });

        // Listen for changes while the user types
        // 'input' event fires on every keystroke (also paste, delete, etc.)
        $(document).on( 'input', '.svgml-mapping-input', function() {
            lookupAndShowName( $(this) );
        });
    }




    /**
     * Look up the JSON object that corresponds to the typed ID value,
     * and show the "name" value as confirmation next to the input field.
     *
     * @param {jQuery} $input – The input element with the typed ID value
     */
    function lookupAndShowName( $input ) {

        // Get the typed value and remove whitespace at start/end
        var typedId  = $.trim( $input.val() );

        // Find the corresponding <span class="svgml-confirm-name"> next to the input.
        // siblings() searches for other elements with the same parent.
        // We search on td (the parent) and then the span within it.
        var $confirm = $input.closest('td').next('td').find('.svgml-confirm-name');

        // Empty field: reset the confirmation
        if ( ! typedId ) {
            $confirm
                .text('')
                .removeClass('svgml-confirm-ok svgml-confirm-fail');
            return;
        }

        // Normalize the JSON data to an array.
        // Some APIs return { "data": [...] }, others return an array directly [...].
        var idField = svgmlAdmin.jsonIdField || 'id';
        var data    = svgml.normalizeToArray( svgmlAdmin.jsonData );

        // If it's still not an array, we can't search
        if ( ! Array.isArray(data) ) {
            $confirm
                .text('Cannot read JSON structure – see console')
                .addClass('svgml-confirm-fail')
                .removeClass('svgml-confirm-ok');
            return;
        }

        // Search the array for the object with the matching ID.
        // We compare as strings so "42" and 42 both match.
        var found = null;
        $.each( data, function( index, obj ) {
            if ( String( obj[ idField ] ) === String( typedId ) ) {
                found = obj;
                return false; // return false in $.each() stops the loop (= break)
            }
        });

        if ( found ) {
            // Object found – show the "name" value as confirmation.
            // We try multiple field names for maximum compatibility.
            var nameValue = found['name']  ||
                            found['naam']  ||
                            found['title'] ||
                            found['titel'] ||
                            found['label'] ||
                            svgmlAdmin.strings.noNameField;

            $confirm
                .text( '✓ ' + nameValue )
                .addClass('svgml-confirm-ok')
                .removeClass('svgml-confirm-fail');

        } else {
            // No match found
            $confirm
                .text( svgmlAdmin.strings.notInJson )
                .addClass('svgml-confirm-fail')
                .removeClass('svgml-confirm-ok');
        }
    }

}); // End jQuery(document).ready
