/**
 * SVG Map Lite – Panel Builder: submit serializer
 *
 * Before the Panel Builder form POSTs to the server, this script walks every
 * visible block row, builds a plain array of objects, and writes the result
 * as a JSON string into the hidden #svgml_panel_blocks field.
 *
 * This approach gives us one canonical payload field that is:
 *   • order-safe  – reflects the current DOM order after drag-and-drop
 *   • mode-safe   – runs in both 'json' feed mode and 'manual' mode
 *   • debug-friendly – visible as a single field in the Network tab
 *
 * There are deliberately NO jsonUrl / mapMode guards here.
 * The serializer always runs, regardless of the map mode.
 */
jQuery( document ).ready( function ( $ ) {

    // ── Locate the Panel Builder form via its unique nonce field ──────────────
    // Avoids a hard-coded form ID and stays consistent with how svgmlSavePage()
    // targets forms across the plugin.
    var $pbForm = $( '[name="svgml_panelbuilder_nonce"]' ).closest( 'form' );

    if ( ! $pbForm.length ) {
        // Not the panel builder page – nothing to do.
        return;
    }

    // ── intercept submit ──────────────────────────────────────────────────────
    $pbForm.on( 'submit', function () {

        var blocks = [];

        $( '#svgml-blocks-tbody .svgml-block-row' ).each( function () {
            var $row = $( this );

            // The hidden input in the html-flag cell is always kept in sync with
            // the checkbox by the .svgml-block-html-cb change handler in
            // admin-footer.php, so reading it here is always accurate.
            var htmlFlag = ( $row.find( '[name="svgml_block_html[]"][type="hidden"]' ).val() === '1' );

            blocks.push( {
                // 'field' is '' for divider rows – that is intentional.
                field : $row.find( '[name="svgml_block_field[]"]' ).val()  || '',
                type  : $row.find( '[name="svgml_block_type[]"]' ).val()   || 'text',
                label : $row.find( '[name="svgml_block_label[]"]' ).val()  || '',
                width : parseInt( $row.find( '[name="svgml_block_width[]"]' ).val() || 100, 10 ),
                html  : htmlFlag
            } );
        } );

        // Write the JSON into the hidden field so it is included in $_POST.
        // We do NOT call e.preventDefault() – the native POST continues.
        $pbForm.find( '#svgml_panel_blocks' ).val( JSON.stringify( blocks ) );
    } );

} );
