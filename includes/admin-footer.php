<?php
/**
 * SVG Map Lite - Admin Footer Scripts
 * Inline JavaScript for admin pages (Panel Builder, Display, Filters, Styles)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// CTRL+S / CMD+S SAVE SHORTCUT + FORM SUBMISSION
// Works on ALL editor pages.
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'admin_footer', 'svgml_save_shortcut_script' );

function svgml_save_shortcut_script() {
    $current_page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';

    // All editor pages (not the overview)
    $editor_pages = [
        'svgml-settings', 'svgml-mapping', 'svgml-display',
        'svgml-panel-builder', 'svgml-filters', 'svgml-styles', 'svgml-ai-assistant',
    ];

    if ( ! in_array( $current_page, $editor_pages, true ) ) {
        return;
    }
    ?>
    <script>
    jQuery(document).ready(function($) {

        // ── SCROLL RESTORE ────────────────────────────────────────────────────
        var _mapId = (typeof svgmlAdmin !== 'undefined') ? svgmlAdmin.mapId : 0;
        var _scrollKey = _mapId ? 'svgml_scroll_' + _mapId : null;
        if (_scrollKey) {
            var _savedScroll = sessionStorage.getItem(_scrollKey);
            if (_savedScroll !== null) {
                sessionStorage.removeItem(_scrollKey);
                window.scrollTo(0, parseInt(_savedScroll, 10));
            }
        }

        // ── SAVE FUNCTIONS ────────────────────────────────────────────────────
        function svgmlSavePage() {
            // Force any focused input to blur first — this synchronously fires the
            // 'change' event so pending ID edits are committed to the JS state
            // before we call syncLayersToHiddenField or submit the form.
            if (document.activeElement && document.activeElement.tagName === 'INPUT') {
                document.activeElement.blur();
            }

            // Save scroll position so it can be restored after the reload
            var _mid = (typeof svgmlAdmin !== 'undefined') ? svgmlAdmin.mapId : 0;
            if (_mid) {
                sessionStorage.setItem('svgml_scroll_' + _mid, window.scrollY);
            }

            // Find the page-specific form by its nonce field, not by DOM position.
            // This prevents accidentally submitting the rename form in the header.
            var $form = $('[name="svgml_settings_nonce"]').closest('form');
            if (!$form.length) $form = $('[name="svgml_mapping_nonce"]').closest('form');
            if (!$form.length) $form = $('[name="svgml_manual_data_nonce"]').closest('form');
            if (!$form.length) $form = $('[name="svgml_display_nonce"]').closest('form');
            if (!$form.length) $form = $('[name="svgml_filters_nonce"]').closest('form');
            if (!$form.length) $form = $('[name="svgml_panelbuilder_nonce"]').closest('form');
            if (!$form.length) $form = $('[name="svgml_styles_nonce"]').closest('form');

            if (!$form.length) return;

            // Flush polygon ID edits into the hidden JSON field BEFORE the form
            // submits. native form.submit() does not fire jQuery 'submit' handlers,
            // so we call the exported sync function explicitly here.
            if (typeof window.svgmlSyncLayersToHidden === 'function') {
                window.svgmlSyncLayersToHidden();
            }

            // Click the form's own submit button — triggers the full browser submit
            // pipeline including jQuery 'submit' handlers as a second safety net.
            var $submitBtn = $form.find('input[type="submit"], button[type="submit"]').first();
            if ($submitBtn.length) {
                $submitBtn[0].click();
            } else {
                $form[0].submit(); // fallback: no submit button found
            }
        }

        // Keyboard shortcut: Ctrl+S / Cmd+S
        $(document).on('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                svgmlSavePage();
            }
        });

        // Save button in the sticky tab bar
        $('#svgml-save-btn').on('click', function(e) {
            e.preventDefault();
            svgmlSavePage();
        });
    });
    </script>
    <?php
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
    // Note: pages registered with null parent get the hook id 'admin_page_svgml-*',
    // so we check $_GET['page'] instead of $screen->id for reliability.
    if ( 'svgml-panel-builder' === $current_page ) :
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
            $(document).trigger('svgmlBlockAdded', [$newRow]);
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
                var label  = $row.find('[name="svgml_block_label[]"]').val()        || '';
                var width  = parseInt($row.find('[name="svgml_block_width[]"]').val() || 100, 10);
                var html   = ($row.find('[name="svgml_block_html[]"][type="hidden"]').val() === '1');
                var layout = $row.find('[name="svgml_block_label_layout[]"]').val() || 'block';
                blocks.push({ field:field, type:type, label:label, width:width, html:html, label_layout:layout });
            });
            return blocks;
        }

        function svgml_renderPreviewBlock(block, obj) {
            var type        = block.type         || 'text';
            var field       = block.field        || '';
            var label       = block.label        || '';
            var width       = block.width        || 100;
            var isHtml      = !!block.html;
            var labelLayout = block.label_layout || 'block';
            var flex        = (width === 33) ? '33.333%' : (width + '%');
            var style       = 'flex:0 0 ' + flex + ';max-width:' + flex + ';box-sizing:border-box;';
            var inlineStyle = labelLayout === 'inline'
                ? 'display:flex;gap:8px;align-items:center;justify-content:space-between;'
                : '';

            if (type === 'divider') {
                return '<div style="' + style + 'padding:4px 0;width:100%;">' +
                           '<hr style="border:none;border-top:1px solid #eee;margin:0;">' +
                       '</div>';
            }

            var value = (field && obj.hasOwnProperty(field)) ? obj[field] : null;

            // Manual-mode fallback: if the block has no field key or the key isn't in the
            // dummy object, generate a type-appropriate placeholder so the preview is never blank.
            if ( (value === null || value === undefined || value === '') &&
                 (typeof svgmlAdmin !== 'undefined' && svgmlAdmin.mapMode === 'manual') ) {
                var manualDefaults = {
                    'heading'   : label || 'Voorbeeld Titel',
                    'badge'     : label || 'Beschikbaar',
                    'price'     : '€ 125.000',
                    'text'      : label || 'Voorbeeld tekst',
                    'link'      : 'https://example.com',
                    'html'      : label || '<em>HTML inhoud</em>',
                    'thumbnail' : '',
                };
                value = ( manualDefaults[ type ] !== undefined ) ? manualDefaults[ type ] : ( label || 'Voorbeeld waarde' );
            }

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
                    return '<div class="svgml-preview-block" style="' + style + inlineStyle + '">' +
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
                    return '<div class="svgml-preview-block" style="' + style + inlineStyle + '">' +
                               (label ? '<span class="svgml-preview-label-text">' +
                                            svgml_previewEscape(label) + '</span>' : '') +
                               '<div class="svgml-preview-value">' + strVal + '</div>' +
                           '</div>';

                default:
                    return '<div class="svgml-preview-block" style="' + style + inlineStyle + '">' +
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

            var isManual = (typeof svgmlAdmin !== 'undefined' && svgmlAdmin.mapMode === 'manual');
            var obj;

            if (isManual) {
                // Manual mode: no JSON feed. Use a dummy object with the three
                // hardcoded fields so the preview renders without crashing.
                obj = { title: 'Voorbeeld naam', status: 'Beschikbaar', size: '120 m²' };
            } else {
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
                obj = data[0];
            }

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
            var label  = block.label || '';
            var isHtml = !!block.html;

            var value = (field && obj.hasOwnProperty(field)) ? obj[field] : null;

            // Manual-mode fallback: same logic as svgml_renderPreviewBlock above.
            if ( (value === null || value === undefined || value === '') &&
                 (typeof svgmlAdmin !== 'undefined' && svgmlAdmin.mapMode === 'manual') ) {
                var ovManualDefaults = {
                    'heading' : label || 'Voorbeeld Titel',
                    'badge'   : label || 'Beschikbaar',
                    'price'   : '€ 125.000',
                    'text'    : label || 'Voorbeeld tekst',
                    'link'    : 'https://example.com',
                };
                value = ( ovManualDefaults[ type ] !== undefined ) ? ovManualDefaults[ type ] : ( label || 'Voorbeeld waarde' );
            }

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

            var isManual = (typeof svgmlAdmin !== 'undefined' && svgmlAdmin.mapMode === 'manual');
            var data;

            if (isManual) {
                // Manual mode: use dummy rows for overview preview
                data = [
                    { title: 'Voorbeeld naam 1', status: 'Beschikbaar', size: '120 m²' },
                    { title: 'Voorbeeld naam 2', status: 'Verkocht',     size: '85 m²'  },
                    { title: 'Voorbeeld naam 3', status: 'Onder optie',  size: '200 m²' },
                ];
            } else {
                var raw = (typeof svgmlAdmin !== 'undefined') ? svgmlAdmin.jsonData : null;
                if (!raw) {
                    $preview.html('<p class="svgml-preview-empty">Geen JSON-data geladen.<br>' +
                                  '<small>Voer een JSON-URL in bij Instellingen.</small></p>');
                    return;
                }
                data = svgml_previewNormalizeToArray(raw);
                if (!data || data.length === 0) {
                    $preview.html('<p class="svgml-preview-empty">Geen objecten gevonden in de feed.</p>');
                    return;
                }
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

        // ── DRAG-AND-DROP SORTING ───────────────────────────────────────────────
        $('#svgml-filters-tbody').sortable({
            handle:      '.svgml-drag-handle',
            cancel:      'input, textarea, button, select, option, .svgml-allow-select',
            axis:        'y',
            placeholder: 'svgml-sort-placeholder',
            helper: function(e, ui) {
                ui.children().each(function() {
                    $(this).width($(this).width());
                });
                return ui;
            },
            opacity:   0.85,
            tolerance: 'pointer'
        }).disableSelection();

        // Click-to-copy buttons in filter rows
        $(document).on('click', '.svgml-copy-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var $btn         = $(this);
            var textToCopy   = $btn.attr('data-copy');
            var originalText = $btn.text();

            function showSuccess() {
                $btn.text('✓');
                setTimeout(function() { $btn.text(originalText); }, 800);
            }

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(textToCopy).then(showSuccess);
            } else {
                var textArea = document.createElement('textarea');
                textArea.value = textToCopy;
                textArea.style.position = 'absolute';
                textArea.style.left = '-999999px';
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                try {
                    document.execCommand('copy');
                    showSuccess();
                } catch (err) {
                    console.error('Fallback copy failed', err);
                }
                document.body.removeChild(textArea);
            }
        });

        $('#svgml-add-filter').on('click', function() {
            var $template = $('#svgml-filter-row-template');
            var $newRow   = $( $template.html() );
            $('#svgml-filters-tbody').append( $newRow );
            $('#svgml-filters-tbody').sortable('refresh');
        });

        $(document).on('click', '.svgml-remove-filter', function() {
            $(this).closest('tr').remove();
        });

        function svgml_toggleOptionsPanel( $row ) {
            var type = $row.find('.svgml-filter-type-select').val();
            $row.find('.svgml-buttons-options').toggle( type === 'buttons' );
            $row.find('.svgml-input-options').toggle( type === 'input' );
        }

        $(document).on('change', '.svgml-filter-type-select', function() {
            svgml_toggleOptionsPanel( $(this).closest('tr') );
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

        // Sync input-mode hidden field when radio changes
        $(document).on('change', '.svgml-input-mode-radio', function() {
            var $row = $(this).closest('tr');
            $row.find('.svgml-input-mode-val').val( $(this).val() );
        });

        $('#svgml-filters-tbody tr').each(function() {
            svgml_toggleOptionsPanel( $(this) );
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
    // Note: null-parent pages use $_GET['page'] for detection (not $screen->id).
    if ( 'svgml-styles' === $current_page ) :
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
