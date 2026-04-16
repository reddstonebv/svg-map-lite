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

    // ── Show/hide static_value input based on block type ─────────────────────
    var STATIC_TYPES = [ 'static_html', 'static_button' ];

    function svgml_toggleStaticField( $row ) {
        var type     = $row.find( '[name="svgml_block_type[]"]' ).val();
        var $static  = $row.find( '.svgml-block-static-val' );
        var $field   = $row.find( '[name="svgml_block_field[]"]' );
        var isStatic = STATIC_TYPES.indexOf( type ) !== -1;

        if ( isStatic ) {
            $static.show().attr( 'placeholder', type === 'static_button' ? 'https://...' : 'HTML inhoud...' );
            // Field dropdown is irrelevant for static types – hide it gracefully
            if ( $field.is( 'select' ) ) $field.closest( 'td' ).css( 'opacity', '0.35' );
        } else {
            $static.hide();
            if ( $field.is( 'select' ) ) $field.closest( 'td' ).css( 'opacity', '' );
        }
    }

    // Init existing rows
    $( '#svgml-blocks-tbody .svgml-block-row' ).each( function () {
        svgml_toggleStaticField( $( this ) );
    } );

    // Delegate for newly added rows
    $( '#svgml-blocks-tbody' ).on( 'change', '.svgml-block-type-select', function () {
        svgml_toggleStaticField( $( this ).closest( 'tr' ) );
    } );

    // ── intercept submit ──────────────────────────────────────────────────────
    $pbForm.on( 'submit', function () {

        var blocks = [];

        $( '#svgml-blocks-tbody .svgml-block-row' ).each( function () {
            var $row = $( this );

            var htmlFlag = ( $row.find( '[name="svgml_block_html[]"][type="hidden"]' ).val() === '1' );

            blocks.push( {
                field        : $row.find( '[name="svgml_block_field[]"]' ).val()        || '',
                type         : $row.find( '[name="svgml_block_type[]"]' ).val()         || 'text',
                label        : $row.find( '[name="svgml_block_label[]"]' ).val()        || '',
                width        : parseInt( $row.find( '[name="svgml_block_width[]"]' ).val() || 100, 10 ),
                html         : htmlFlag,
                static_value : $row.find( '[name="svgml_block_static_value[]"]' ).val() || '',
                prefix       : $row.find( '[name="svgml_block_prefix[]"]' ).val()       || '',
                suffix       : $row.find( '[name="svgml_block_suffix[]"]' ).val()       || ''
            } );
        } );

        $pbForm.find( '#svgml_panel_blocks' ).val( JSON.stringify( blocks ) );
    } );

    // Also init rows added via the "+ Add" buttons (delegated via MutationObserver
    // is overkill; a simple event on the tbody after the template clone works fine
    // because admin-footer.php fires a custom svgmlBlockAdded event).
    $( document ).on( 'svgmlBlockAdded', function ( e, $row ) {
        if ( $row ) svgml_toggleStaticField( $row );
    } );

} );
