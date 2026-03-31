<?php
/**
 * SVG Map Lite - Admin Footer Scripts
 * Inline JavaScript for admin pages (Panel Builder, Display, Filters, Styles)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_footer', 'svgml_admin_footer_scripts' );

function svgml_admin_footer_scripts() {
    $screen = get_current_screen();
    $current_page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';

    $svgml_pages = [
        'svgml-panel-builder',
        'svgml-display',
        'svgml-filters',
        'svgml-styles',
    ];

    if ( ! in_array( $current_page, $svgml_pages, true ) ) {
        return;
    }

    // ── Display page (status colors) ───────────────────────────────────
    if ( 'svgml-display' === $current_page ) :
    ?>
    <script>
    jQuery(document).ready(function($) {

        // ── Add status row ────────────────────────────────────────────
        $('#svgml-add-status').on('click', function() {
            var $template = $('#svgml-status-row-template');
            var $newRow   = $( $template.html() );
            $('#svgml-status-tbody').append( $newRow );
        });

        // ── Remove status row ──────────────────────────────────────────
        $(document).on('click', '.svgml-remove-status', function() {
            $(this).closest('tr').remove();
        });

        // ── Live color + badge preview ──────────────────────────────────
        $(document).on('input change', '.svgml-color-input', function() {
            var hex      = $(this).val();
            var $row     = $(this).closest('tr');
            var $preview = $row.find('.svgml-status-preview');
            var $dot     = $row.find('.svgml-region-color-dot');
            var $val     = $row.find('.svgml-status-val-input');

            $preview.css({
                'background-color': hex + '1a',
                'color':            hex,
                'border-color':     hex
            });
            $dot.css('background', hex);
            $preview.text( $val.val() || 'Status' );
        });

        // ── Update badge text on input ──────────────────────────────────
        $(document).on('input', '.svgml-status-val-input', function() {
            var $row     = $(this).closest('tr');
            var $preview = $row.find('.svgml-status-preview');
            $preview.text( $(this).val() || 'Status' );
        });

        // ── Opacity slider live preview ─────────────────────────────
        $(document).on('input', '.svgml-opacity-slider', function() {
            var pct  = parseInt( $(this).val(), 10 );
            var $row = $(this).closest('tr');
            $row.find('.svgml-opacity-val').text( pct + '%' );
            $row.find('.svgml-region-color-dot').css('opacity', pct / 100);
        });
    });
    </script>
    <?php
    endif;

    // ── Panel Builder page ───────────────────────────────────────────────
    if ( 'svg-map-lite_page_svgml-panel-builder' === $screen->id ) :
    ?>
    <script>
    jQuery(document).ready(function($) {

        // ── Add block ────────────────────────────────────────────────
        $('.svgml-add-block').on('click', function() {
            var blockType = $(this).data('type');
            var $template = $('#svgml-block-row-template');
            var $newRow   = $( $template.html() );

            $newRow.find('.svgml-block-type-select').val( blockType );

            if ( 'divider' === blockType ) {
                $newRow.find('.svgml-block-field-select').closest('td').html(
                    '<input type="hidden" name="svgml_block_field[]" value=""><em style="color:#888">—</em>'
                );
            }

            $('#svgml-blocks-tbody').append( $newRow );
        });

        // ── Remove block ────────────────────────────────────────────
        $(document).on('click', '.svgml-remove-block', function() {
            $(this).closest('tr').remove();
        });

        // ── Change block type: hide/show field column ──────────────────────
        $(document).on('change', '.svgml-block-type-select', function() {
            var $row = $(this).closest('tr');
            var $fieldCell = $row.find('.svgml-block-field-select').closest('td');

            if ( $(this).val() === 'divider' ) {
                $fieldCell.data('original-html', $fieldCell.html());
                $fieldCell.html('<input type="hidden" name="svgml_block_field[]" value=""><em style="color:#888">—</em>');
            } else {
                var originalHtml = $fieldCell.data('original-html');
                if ( originalHtml ) {
                    $fieldCell.html( originalHtml );
                    $fieldCell.removeData('original-html');
                }
            }
        });

        // ── Add overview row ────────────────────────────────────────────
        $('.svgml-add-overview').on('click', function() {
            var ovType    = $(this).data('type');
            var $template = $('#svgml-overview-row-template');
            var $newRow   = $( $template.html() );
            $newRow.find('select[name="svgml_overview_type[]"]').val( ovType );
            $('#svgml-overview-tbody').append( $newRow );
        });

        // ── Remove overview row ────────────────────────────────────
        $(document).on('click', '.svgml-remove-overview', function() {
            $(this).closest('tr').remove();
        });

        // ─────────────────────────────────────────────────────────────────
        // DRAG-AND-DROP SORTING (jQuery UI Sortable)
        // ─────────────────────────────────────────────────────────────────
        $('#svgml-blocks-tbody').sortable({
            handle:      '.svgml-drag-handle',
            axis:        'y',
            placeholder: 'svgml-sort-placeholder',
            helper:      'clone',
            opacity:     0.85,
            tolerance:   'pointer',
            update: function() {
                svgml_refreshPreview();
            }
        }).disableSelection();

        $('#svgml-overview-tbody').sortable({
            handle:      '.svgml-drag-handle',
            axis:        'y',
            placeholder: 'svgml-sort-placeholder',
            helper:      'clone',
            opacity:     0.85,
            tolerance:   'pointer',
            update: function() {
                svgml_refreshOverviewPreview();
            }
        }).disableSelection();

        // ─────────────────────────────────────────────────────────────────
        // HTML CHECKBOX SYNC
        // ─────────────────────────────────────────────────────────────────
        $(document).on('change', '.svgml-block-html-cb', function() {
            $(this).prev('input[type="hidden"]').val( $(this).is(':checked') ? '1' : '0' );
            svgml_refreshPreview();
        });

        // ─────────────────────────────────────────────────────────────────
        // WIDTH INDICATOR
        // ─────────────────────────────────────────────────────────────────
        function svgml_injectWidthBars() {
            $('#svgml-blocks-tbody .svgml-block-row').each(function() {
                var $select = $(this).find('.svgml-block-width-select');
                if ( $select.length && !$select.next('.svgml-width-bar-wrap').length ) {
                    var pct = $select.val() || 100;
                    $select.after(
                        '<div class="svgml-width-bar-wrap">' +
                            '<div class="svgml-width-bar" style="width:' + pct + '%"></div>' +
                        '</div>'
                    );
                }
            });
        }
        svgml_injectWidthBars();

        $(document).on('change', '.svgml-block-width-select', function() {
            var pct  = $(this).val() || 100;
            var $bar = $(this).next('.svgml-width-bar-wrap').find('.svgml-width-bar');
            if ( !$bar.length ) {
                $(this).after(
                    '<div class="svgml-width-bar-wrap">' +
                        '<div class="svgml-width-bar" style="width:' + pct + '%"></div>' +
                    '</div>'
                );
            } else {
                $bar.css('width', pct + '%');
            }
            svgml_refreshPreview();
        });

        $(document).on('click', '.svgml-add-block', function() {
            setTimeout(function() {
                svgml_injectWidthBars();
                svgml_refreshPreview();
            }, 30);
        });

        // ─────────────────────────────────────────────────────────────────
        // LIVE PREVIEW PANEL
        // ─────────────────────────────────────────────────────────────────

        function svgml_previewNormalizeToArray(raw) {
            if ( Array.isArray(raw) ) return raw;
            if ( typeof raw !== 'object' || raw === null ) return null;
            var configKey = ( typeof svgmlAdmin !== 'undefined' && svgmlAdmin.jsonArrayKey )
                            ? svgmlAdmin.jsonArrayKey : '';
            if ( configKey && Array.isArray(raw[configKey]) ) return raw[configKey];
            var knownKeys = ['assets','data','items','results','objects','features',
                             'records','list','collection','entries','value','spaces',
                             'units','properties','lots','houses','apartments'];
            for (var i = 0; i < knownKeys.length; i++) {
                var c = raw[knownKeys[i]];
                if ( Array.isArray(c) && c.length > 0 ) return c;
            }
            var best = null, bestSize = 0, topKeys = Object.keys(raw);
            for (var j = 0; j < topKeys.length; j++) {
                var v = raw[topKeys[j]];
                if ( Array.isArray(v) && v.length > bestSize &&
                     typeof v[0] === 'object' && v[0] !== null ) {
                    best = v; bestSize = v.length;
                }
            }
            return best;
        }

        function svgml_previewEscape(text) {
            return String(text).replace(/[&<>"']/g, function(m) {
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m];
            });
        }

        function svgml_previewHumanize(n) {
            return n.replace(/[_-]/g, ' ')
                    .replace(/\b\w/g, function(c) { return c.toUpperCase(); });
        }

        function svgml_readCurrentBlocks() {
            var blocks = [];
            $('#svgml-blocks-tbody .svgml-block-row').each(function() {
                var $row  = $(this);
                var field = $row.find('[name="svgml_block_field[]"]').val()  || '';
                var type  = $row.find('[name="svgml_block_type[]"]').val()   || 'text';
                var label = $row.find('[name="svgml_block_label[]"]').val()  || '';
                var width = parseInt($row.find('[name="svgml_block_width[]"]').val() || 100, 10);
                var html  = ($row.find('[name="svgml_block_html[]"][type="hidden"]').val() === '1');
                blocks.push({ field:field, type:type, label:label, width:width, html:html });
            });
            return blocks;
        }

        function svgml_renderPreviewBlock(block, obj) {
            var type   = block.type  || 'text';
            var field  = block.field || '';
            var label  = block.label || (field ? svgml_previewHumanize(field) : '');
            var width  = block.width || 100;
            var isHtml = !!block.html;
            var flex   = (width === 33) ? '33.333%' : (width + '%');
            var style  = 'flex:0 0 ' + flex + ';max-width:' + flex + ';box-sizing:border-box;';

            if (type === 'divider') {
                return '<div style="' + style + 'padding:4px 0;width:100%;">' +
                           '<hr style="border:none;border-top:1px solid #eee;margin:0;">' +
                       '</div>';
            }

            var value = (field && obj.hasOwnProperty(field)) ? obj[field] : null;
            if (value === null || value === undefined || value === '') return '';
            var strVal = String(value);

            switch (type) {
                case 'thumbnail':
                    if (/^https?:\/\//i.test(strVal)) {
                        return '<div style="flex:0 0 100%;max-width:100%;">' +
                                   '<img src="' + svgml_previewEscape(strVal) + '" ' +
                                        'class="svgml-preview-img" alt="" loading="lazy">' +
                               '</div>';
                    }
                    return '';

                case 'heading':
                    return '<div class="svgml-preview-heading" style="' + style + '">' +
                               (isHtml ? strVal : svgml_previewEscape(strVal)) +
                           '</div>';

                case 'badge':
                    return '<div style="' + style + 'padding:8px 14px;">' +
                               '<span class="svgml-preview-badge">' +
                                   svgml_previewEscape(strVal) +
                               '</span>' +
                           '</div>';

                case 'price':
                    return '<div class="svgml-preview-block" style="' + style + '">' +
                               (label ? '<span class="svgml-preview-label-text">' +
                                            svgml_previewEscape(label) + '</span>' : '') +
                               '<div class="svgml-preview-value" style="font-weight:600;">' +
                                   (isHtml ? strVal : svgml_previewEscape(strVal)) +
                               '</div></div>';

                case 'link':
                    if (/^https?:\/\//i.test(strVal)) {
                        return '<div class="svgml-preview-block" style="' + style + '">' +
                                   '<a href="#" onclick="return false;" ' +
                                      'style="font-size:12px;color:#2271b1;">' +
                                       svgml_previewEscape(label || strVal) + ' ↗' +
                                   '</a></div>';
                    }
                    return '';

                case 'html':
                    return '<div class="svgml-preview-block" style="' + style + '">' +
                               (label ? '<span class="svgml-preview-label-text">' +
                                            svgml_previewEscape(label) + '</span>' : '') +
                               '<div class="svgml-preview-value">' + strVal + '</div>' +
                           '</div>';

                default:
                    return '<div class="svgml-preview-block" style="' + style + '">' +
                               (label ? '<span class="svgml-preview-label-text">' +
                                            svgml_previewEscape(label) + '</span>' : '') +
                               '<div class="svgml-preview-value">' +
                                   (isHtml ? strVal : svgml_previewEscape(strVal)) +
                               '</div></div>';
            }
        }

        function svgml_refreshPreview() {
            var $preview = $('#svgml-live-preview');
            if (!$preview.length) return;

            var raw = (typeof svgmlAdmin !== 'undefined') ? svgmlAdmin.jsonData : null;
            if (!raw) {
                $preview.html('<p class="svgml-preview-empty">Geen JSON-data geladen.<br>' +
                              '<small>Vul een JSON-URL in bij Instellingen.</small></p>');
                return;
            }
            var data = svgml_previewNormalizeToArray(raw);
            if (!data || data.length === 0) {
                $preview.html('<p class="svgml-preview-empty">Geen objecten gevonden in de feed.</p>');
                return;
            }
            var obj = data[0];

            var blocks = svgml_readCurrentBlocks();
            if (blocks.length === 0) {
                $preview.html('<p class="svgml-preview-empty">Voeg blokken toe om het voorbeeld te zien.</p>');
                return;
            }

            var html = '<div class="svgml-preview-blocks">';
            $.each(blocks, function(i, block) {
                html += svgml_renderPreviewBlock(block, obj);
            });
            html += '</div>';
            $preview.html(html);
        }

        // ── Preview triggers ───────────────────────────────────────────────────
        $(document).on('change', '#svgml-blocks-tbody select', svgml_refreshPreview);
        $(document).on('input',  '#svgml-blocks-tbody input[type="text"]', svgml_refreshPreview);
        $(document).on('click',  '.svgml-remove-block', function() {
            setTimeout(svgml_refreshPreview, 30);
        });

        svgml_refreshPreview();

        // ─────────────────────────────────────────────────────────────────
        // LIVE OVERVIEW PREVIEW
        // ─────────────────────────────────────────────────────────────────

        function svgml_readOverviewBlocks() {
            var blocks = [];
            $('#svgml-overview-tbody .svgml-overview-row').each(function() {
                var $row  = $(this);
                var field = $row.find('[name="svgml_overview_field[]"]').val()  || '';
                var type  = $row.find('[name="svgml_overview_type[]"]').val()   || 'text';
                var label = $row.find('[name="svgml_overview_label[]"]').val()  || '';
                var html  = ($row.find('[name="svgml_overview_html[]"][type="hidden"]').val() === '1');
                blocks.push({ field:field, type:type, label:label, html:html });
            });
            return blocks;
        }

        function svgml_renderOverviewPreviewBlock(block, obj) {
            var type   = block.type  || 'text';
            var field  = block.field || '';
            var label  = block.label || (field ? svgml_previewHumanize(field) : '');
            var isHtml = !!block.html;

            var value = (field && obj.hasOwnProperty(field)) ? obj[field] : null;
            if (value === null || value === undefined || value === '') return '';
            var strVal = String(value);

            switch (type) {
                case 'heading':
                    return '<span class="svgml-ov-preview-heading">' +
                               (isHtml ? strVal : svgml_previewEscape(strVal)) +
                           '</span> ';

                case 'badge':
                    return '<span class="svgml-ov-preview-badge">' +
                               svgml_previewEscape(strVal) +
                           '</span> ';

                case 'price':
                    return '<span class="svgml-ov-preview-price">' +
                               (isHtml ? strVal : svgml_previewEscape(strVal)) +
                           '</span> ';

                case 'link':
                    if (/^https?:\/\//i.test(strVal)) {
                        return '<a href="#" onclick="return false;" class="svgml-ov-preview-link">' +
                                   svgml_previewEscape(label || 'Link') + ' ↗</a> ';
                    }
                    return '';

                default:
                    return '<span class="svgml-ov-preview-text">' +
                               (isHtml ? strVal : svgml_previewEscape(strVal)) +
                           '</span> ';
            }
        }

        function svgml_refreshOverviewPreview() {
            var $preview = $('#svgml-overview-live-preview');
            if (!$preview.length) return;

            var raw = (typeof svgmlAdmin !== 'undefined') ? svgmlAdmin.jsonData : null;
            if (!raw) {
                $preview.html('<p class="svgml-preview-empty">Geen JSON-data geladen.<br>' +
                              '<small>Voer een JSON-URL in bij Instellingen.</small></p>');
                return;
            }
            var data = svgml_previewNormalizeToArray(raw);
            if (!data || data.length === 0) {
                $preview.html('<p class="svgml-preview-empty">Geen objecten gevonden in de feed.</p>');
                return;
            }

            var blocks = svgml_readOverviewBlocks();
            if (blocks.length === 0) {
                $preview.html('<p class="svgml-preview-empty">Voeg blokken toe om de preview te zien.</p>');
                return;
            }

            var maxItems = Math.min(data.length, 3);
            var html = '<div class="svgml-ov-preview-list">';
            for (var i = 0; i < maxItems; i++) {
                var obj = data[i];
                html += '<div class="svgml-ov-preview-item">';
                $.each(blocks, function(idx, block) {
                    html += svgml_renderOverviewPreviewBlock(block, obj);
                });
                html += '</div>';
            }
            if (data.length > 3) {
                html += '<div class="svgml-ov-preview-more">… en nog ' +
                        (data.length - 3) + ' objecten</div>';
            }
            html += '</div>';
            $preview.html(html);
        }

        $(document).on('change', '#svgml-overview-tbody select', svgml_refreshOverviewPreview);
        $(document).on('input',  '#svgml-overview-tbody input[type="text"]', svgml_refreshOverviewPreview);
        $(document).on('click',  '.svgml-remove-overview', function() {
            setTimeout(svgml_refreshOverviewPreview, 30);
        });
        $(document).on('click',  '.svgml-add-overview', function() {
            setTimeout(svgml_refreshOverviewPreview, 30);
        });
        $(document).on('change', '#svgml-overview-tbody .svgml-block-html-cb', function() {
            svgml_refreshOverviewPreview();
        });

        svgml_refreshOverviewPreview();

    });
    </script>
    <?php
    endif;

    // ── Filters page ─────────────────────────────────────────────────────
    if ( 'svgml-filters' === $current_page ) :
    ?>
    <script>
    jQuery(document).ready(function($) {

        $('#svgml-add-filter').on('click', function() {
            var $template = $('#svgml-filter-row-template');
            var $newRow   = $( $template.html() );
            $('#svgml-filters-tbody').append( $newRow );
        });

        $(document).on('click', '.svgml-remove-filter', function() {
            $(this).closest('tr').remove();
        });

        function svgml_toggleButtonsOptions( $row ) {
            var $typeSelect = $row.find('.svgml-filter-type-select');
            var $optionsDiv = $row.find('.svgml-buttons-options');
            var type = $typeSelect.val();

            if ( type === 'buttons' ) {
                $optionsDiv.show();
            } else {
                $optionsDiv.hide();
            }
        }

        $(document).on('change', '.svgml-filter-type-select', function() {
            var $row = $(this).closest('tr');
            svgml_toggleButtonsOptions( $row );
        });

        function svgml_syncButtonsValues( $row ) {
            var $optionsDiv = $row.find('.svgml-buttons-options');

            var source = $optionsDiv.find('.svgml-button-source-radio:checked').val() || 'auto';
            $row.find('.svgml-button-source-val').val( source );

            var showCount = $optionsDiv.find('.svgml-button-show-count-checkbox').is(':checked') ? '1' : '0';
            $row.find('.svgml-button-show-count-val').val( showCount );

            var customValues = $optionsDiv.find('.svgml-button-custom-values-textarea').val();
            $row.find('.svgml-button-custom-values-val').val( customValues );

            if ( source === 'custom' ) {
                $optionsDiv.find('.svgml-button-custom-values-textarea').show();
            } else {
                $optionsDiv.find('.svgml-button-custom-values-textarea').hide();
            }
        }

        $(document).on('change', '.svgml-button-source-radio, .svgml-button-show-count-checkbox', function() {
            var $row = $(this).closest('tr');
            svgml_syncButtonsValues( $row );
        });

        $(document).on('input', '.svgml-button-custom-values-textarea', function() {
            var $row = $(this).closest('tr');
            svgml_syncButtonsValues( $row );
        });

        $('#svgml-filters-tbody tr').each(function() {
            svgml_toggleButtonsOptions( $(this) );
            svgml_syncButtonsValues( $(this) );
        });

        function svgml_syncColorCheckbox( $checkbox, colorId, hiddenId ) {
            var enabled = $checkbox.is(':checked');
            $('#' + colorId).css('opacity', enabled ? 1 : 0.4);
            if ( enabled ) {
                $('#' + hiddenId).val( $('#' + colorId).val() );
            } else {
                $('#' + hiddenId).val('');
            }
        }

        svgml_syncColorCheckbox( $('#svgml_filter_match_enabled'), 'svgml_filter_match_color', 'svgml_filter_match_color_val' );
        svgml_syncColorCheckbox( $('#svgml_filter_dim_enabled'),   'svgml_filter_dim_color',   'svgml_filter_dim_color_val' );

        $('#svgml_filter_match_enabled').on('change', function() {
            svgml_syncColorCheckbox( $(this), 'svgml_filter_match_color', 'svgml_filter_match_color_val' );
        });
        $('#svgml_filter_dim_enabled').on('change', function() {
            svgml_syncColorCheckbox( $(this), 'svgml_filter_dim_color', 'svgml_filter_dim_color_val' );
        });

        $('#svgml_filter_match_color').on('input change', function() {
            $('#svgml-match-preview').css('background', $(this).val());
            if ( $('#svgml_filter_match_enabled').is(':checked') ) {
                $('#svgml_filter_match_color_val').val( $(this).val() );
            }
        });
        $('#svgml_filter_dim_color').on('input change', function() {
            $('#svgml-dim-preview').css('background', $(this).val());
            if ( $('#svgml_filter_dim_enabled').is(':checked') ) {
                $('#svgml_filter_dim_color_val').val( $(this).val() );
            }
        });

        $('input[name="svgml_filter_match_color"]').not('[type="hidden"]').removeAttr('name');
        $('input[name="svgml_filter_dim_color"]').not('[type="hidden"]').removeAttr('name');
    });
    </script>
    <?php
    endif;

    // ── Styles page: CodeMirror initialization + reset button ───────────────
    if ( 'svg-map-lite_page_svgml-styles' === $screen->id ) :
    ?>
    <script>
    jQuery(document).ready(function($) {
        var cmEditor = null;

        if ( typeof wp !== 'undefined' && wp.codeEditor ) {
            var editor = wp.codeEditor.initialize( $('#svgml_custom_css'), {
                codemirror: {
                    mode: 'css',
                    theme: 'tomorrow-night',
                    lineNumbers: true,
                    lineWrapping: true,
                    indentUnit: 2,
                    tabSize: 2,
                    autoCloseBrackets: true,
                    matchBrackets: true
                }
            });
            if (editor && editor.codemirror) {
                cmEditor = editor.codemirror;
            }
        }

        $('#svgml-reset-css').on('click', function() {
            if (!confirm('Standaard CSS herstellen? Dit overschrijft je huidige CSS in het editor.')) return;

            $.post(svgmlAdmin.ajaxUrl, {
                action: 'svgml_get_default_css',
                nonce:  svgmlAdmin.nonce
            }, function(response) {
                if (response.success && response.data.css) {
                    var defaultCss = response.data.css;
                    if (cmEditor) {
                        cmEditor.setValue(defaultCss);
                    } else {
                        $('#svgml_custom_css').val(defaultCss);
                    }
                }
            });
        });
    });
    </script>
    <?php
    endif;
}
