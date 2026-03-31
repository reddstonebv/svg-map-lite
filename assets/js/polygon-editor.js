/**
 * SVG Map Lite – Polygon Editor (jQuery + Fabric.js v5)
 *
 * Provides a complete interactive polygon editor on top of a background image:
 *
 *   DRAW       – Click to place points, "Done" to close the polygon.
 *   EDIT       – Select a polygon → click "Edit Points" → drag or delete
 *                individual vertices. Backspace deletes the selected point.
 *   SNAP       – Optional: points snap magnetically to points of
 *                adjacent polygons (within an adjustable distance).
 *   ZOOM       – Ctrl+scroll, buttons or keys (+/-) to zoom in/out.
 *                The canvas container scrolls so you can pan at high zoom levels.
 *   DELETE     – Select polygon → click "Delete" or use the list button.
 *
 * Coordinates are saved as normalised values (0–1), so polygons
 * scale correctly regardless of image size or zoom level.
 *
 * Dependencies: jQuery (WordPress), Fabric.js v5 (CDN), wp.media
 */

jQuery(document).ready(function($) {

    'use strict';

    // ── CHECK ────────────────────────────────────────────────────────────
    if ( !$('#svgml-polygon-canvas').length ) return;

    // ── COLORS ───────────────────────────────────────────────────────────
    var POLY_FILL         = 'rgba(42, 157, 143, 0.20)';
    var POLY_FILL_ACTIVE  = 'rgba(42, 157, 143, 0.40)';
    var POLY_STROKE       = '#2a9d8f';
    var POLY_STROKE_WIDTH = 1;
    var POINT_RADIUS      = 5;
    var POINT_FILL        = '#e76f51';       // Coral: draw points
    var EDIT_POINT_FILL   = '#264653';       // Charcoal: editable points
    var EDIT_POINT_ACTIVE = '#e76f51';       // Coral: selected edit point
    var SNAP_RADIUS       = 12;              // Pixels: magnetic attraction distance

    // ── MULTI-LAYER STATE ────────────────────────────────────────────────────
    var layers        = [];          // Array of layer objects
    var activeLayerIndex = 0;        // Currently active layer

    // ── STATE (PER LAYER) ────────────────────────────────────────────────────
    var canvas        = null;
    var bgImage       = null;
    var isDrawing     = false;       // Drawing mode
    var isEditing     = false;       // Point edit mode
    var drawPoints    = [];          // Points of the current polygon (canvas-px)
    var drawHelpers   = [];          // Temporary Fabric objects (circles, lines)
    var polygons      = [];          // [ { id, points (normalized), fabricObj }, ... ]
    var selectedPoly  = null;        // Currently selected polygon
    var editCircles   = [];          // Fabric.Circle's of editable points
    var editPolyRef   = null;        // Polygon being edited
    var selectedEditPt= null;        // Currently selected edit point
    var imageNatW     = 0;
    var imageNatH     = 0;
    var canvasBaseW   = 0;           // Width at 100% zoom
    var canvasBaseH   = 0;           // Height at 100% zoom
    var zoomLevel     = 1;           // Current zoom level (1 = 100%)
    var prevZoomLevel = 1;           // Previous zoom level (for edit-circle repositioning)
    var snapEnabled   = true;
    var isPanning     = false;       // Space bar pressed → panning
    var panStartX     = 0;
    var panStartY     = 0;
    var panScrollX    = 0;
    var panScrollY    = 0;

    // ── DOM ──────────────────────────────────────────────────────────────
    var $editorWrap   = $('#svgml-polygon-editor-wrap');
    var $canvasWrap   = $('#svgml-polygon-canvas-wrap');
    var $drawBtn      = $('#svgml-poly-draw');
    var $editBtn      = $('#svgml-poly-edit');
    var $doneBtn      = $('#svgml-poly-done');
    var $cancelBtn    = $('#svgml-poly-cancel');
    var $deleteBtn    = $('#svgml-poly-delete');
    var $statusText   = $('#svgml-poly-status');
    var $polygonList  = $('#svgml-polygon-tbody');
    var $hiddenJson   = $('#svgml_polygons_json');
    var $imageInput   = $('#svgml_image_attachment_id');
    var $zoomIn       = $('#svgml-zoom-in');
    var $zoomOut      = $('#svgml-zoom-out');
    var $zoomReset    = $('#svgml-zoom-reset');
    var $zoomLevel    = $('#svgml-zoom-level');
    var $snapToggle   = $('#svgml-snap-toggle');
    var $strokeColor  = $('#svgml-stroke-color');
    var $strokeWidth  = $('#svgml-stroke-width');

    // Initialise line style constants from saved values in the toolbar
    if ($strokeColor.length && $strokeColor.val()) POLY_STROKE = $strokeColor.val();
    if ($strokeWidth.length && $strokeWidth.val()) POLY_STROKE_WIDTH = parseFloat($strokeWidth.val()) || 1;

    $deleteBtn.hide();
    $('.svgml-edit-options').hide();

    // ════════════════════════════════════════════════════════════════════
    //  SOURCE TYPE TOGGLE
    // ════════════════════════════════════════════════════════════════════
    $('input[name="svgml_source_type"]').on('change', function() {
        if ($(this).val() === 'svg') {
            $('#svgml-source-svg').show();
            $('#svgml-source-image').hide();
        } else {
            $('#svgml-source-svg').hide();
            $('#svgml-source-image').show();
            if ( $imageInput.val() && !canvas ) initCanvas();
        }
    });

    // ════════════════════════════════════════════════════════════════════
    //  IMAGE UPLOAD (wp.media)
    // ════════════════════════════════════════════════════════════════════
    var imageFrame = null;

    $('#svgml-upload-image-btn').on('click', function(e) {
        e.preventDefault();
        if (imageFrame) { imageFrame.open(); return; }

        imageFrame = wp.media({
            title:    'Select background image',
            button:   { text: 'Use this image' },
            multiple: false,
            library:  { type: ['image/jpeg', 'image/png', 'image/webp'] }
        });
        imageFrame.on('select', function() {
            var att = imageFrame.state().get('selection').first().toJSON();
            $imageInput.val(att.id);
            $('#svgml-upload-image-btn').html(
                '<span class="dashicons dashicons-format-image"></span> Change image'
            );
            $editorWrap.show();

            // Also update the layers array so svgml_layers_json stays in sync.
            // If there are no layers yet, create a default layer for this image.
            // If we're on an existing layer, update its image attachment ID.
            if (layers.length === 0) {
                layers.push({
                    name: 'Laag 1',
                    imageAttachmentId: att.id,
                    imageUrl: att.url,
                    polygons: [],
                    strokeColor: $strokeColor.val() || '#2a9d8f',
                    strokeWidth: $strokeWidth.val() || '1'
                });
                activeLayerIndex = 0;
            } else if (activeLayerIndex >= 0 && activeLayerIndex < layers.length) {
                layers[activeLayerIndex].imageAttachmentId = att.id;
                layers[activeLayerIndex].imageUrl = att.url;
            }
            syncLayersToHiddenField();

            loadImageOnCanvas(att.url);
        });
        imageFrame.open();
    });

    $('#svgml-remove-image').on('click', function() {
        $imageInput.val('');
        $editorWrap.hide();
        if (canvas) { canvas.dispose(); canvas = null; }
        polygons = [];
        syncToHiddenField();
        renderPolygonList();
    });

    // ════════════════════════════════════════════════════════════════════
    //  CANVAS INITIALISATION
    // ════════════════════════════════════════════════════════════════════

    function initCanvas() {
        if (canvas) return;
        canvas = new fabric.Canvas('svgml-polygon-canvas', {
            selection:          false,
            hoverCursor:        'pointer',
            backgroundColor:    '#f5f5f5',
            selectionColor:     'transparent',       // No blue selection overlay
            selectionBorderColor: 'transparent',     // No blue selection border
        });
        // Optimize canvas for multiple readback operations (getImageData)
        const ctx = canvas.getContext('2d');
        if (ctx && ctx.canvas) {
            ctx.canvas.getContext('2d', { willReadFrequently: true });
        }
        canvas.on('mouse:down', onCanvasClick);
        canvas.on('selection:created', onObjectSelected);
        canvas.on('selection:updated', onObjectSelected);
        canvas.on('selection:cleared', onObjectDeselected);

        // Edit point highlight events (must be here, not at top-scope where canvas is still null)
        canvas.on('selection:created', highlightEditPt);
        canvas.on('selection:updated', highlightEditPt);
        canvas.on('selection:cleared', unhighlightEditPts);

        // Register pan events on the Fabric.js upper canvas (after short delay so DOM is ready)
        setTimeout(setupPanEvents, 50);

        return canvas;
    }

    /**
     * Load an image on the canvas as background.
     * Calculates scale factor so the image fits in the container.
     */
    function loadImageOnCanvas(url, onReadyCallback) {
        if (!canvas) initCanvas();
        if (bgImage) { canvas.remove(bgImage); bgImage = null; }

        fabric.Image.fromURL(url, function(img) {
            bgImage   = img;
            imageNatW = img.width;
            imageNatH = img.height;

            var containerWidth = $canvasWrap.width() || 800;
            var scale = containerWidth / img.width;
            canvasBaseW = Math.round(img.width  * scale);
            canvasBaseH = Math.round(img.height * scale);
            zoomLevel   = 1;

            applyZoom();

            img.set({
                left: 0, top: 0,
                scaleX: scale * zoomLevel,
                scaleY: scale * zoomLevel,
                selectable: false, evented: false, hoverCursor: 'default'
            });
            canvas.insertAt(img, 0);
            canvas.renderAll();

            // If a callback is provided (e.g. from loadLayer), use it.
            // Otherwise: load the polygons from the legacy hidden field.
            if (typeof onReadyCallback === 'function') {
                onReadyCallback();
            } else {
                loadExistingPolygons();
            }
        }, { crossOrigin: 'anonymous' });
    }

    // ════════════════════════════════════════════════════════════════════
    //  ZOOM
    // ════════════════════════════════════════════════════════════════════

    var ZOOM_MIN = 0.5;
    var ZOOM_MAX = 5;
    var ZOOM_STEP = 0.25;

    /**
     * Apply zoom level to the canvas.
     * The canvas is enlarged, the container scrolls.
     * All objects are rescaled.
     */
    function applyZoom() {
        var prevZoom = prevZoomLevel;
        var w = Math.round(canvasBaseW * zoomLevel);
        var h = Math.round(canvasBaseH * zoomLevel);
        canvas.setWidth(w);
        canvas.setHeight(h);

        // Rescale the background image
        if (bgImage) {
            var baseScale = canvasBaseW / imageNatW;
            bgImage.set({
                scaleX: baseScale * zoomLevel,
                scaleY: baseScale * zoomLevel
            });
        }

        // Reposition all polygon Fabric objects
        rebuildAllFabricPolygons();

        // If we're in edit mode: also reposition the edit points
        if (isEditing && editPolyRef && editCircles.length > 0) {
            // First save the current positions as normalised values
            // (based on the PREVIOUS zoom/canvas size)
            var oldCw = canvasBaseW * prevZoom;
            var oldCh = canvasBaseH * prevZoom;
            var tempNormalized = [];
            $.each(editCircles, function(i, c) {
                tempNormalized.push({
                    x: round6(c.left / oldCw),
                    y: round6(c.top / oldCh)
                });
            });
            editPolyRef.points = tempNormalized;

            // Reposition based on the NEW zoom level
            var cw = canvasBaseW * zoomLevel;
            var ch = canvasBaseH * zoomLevel;
            $.each(editCircles, function(i, c) {
                c.set({
                    left: tempNormalized[i].x * cw,
                    top:  tempNormalized[i].y * ch
                });
                c.setCoords();
            });
            // Rebuild the edit polygon too
            updatePolygonFromEditCircles();
        }

        canvas.renderAll();
        $zoomLevel.text(Math.round(zoomLevel * 100) + '%');
        prevZoomLevel = zoomLevel;
    }

    /**
     * Rebuild all Fabric.js polygon objects based on the normalised points.
     * Needed after zoom change, as we need to recalculate pixel positions.
     */
    function rebuildAllFabricPolygons() {
        $.each(polygons, function(i, p) {
            if (p.fabricObj) canvas.remove(p.fabricObj);
            var cw = canvasBaseW * zoomLevel;
            var ch = canvasBaseH * zoomLevel;
            var pts = $.map(p.points, function(pt) {
                return { x: pt.x * cw, y: pt.y * ch };
            });
            p.fabricObj = createFabricPolygon(pts, p.id, true); // true = re-add to canvas
        });
    }

    // Zoom buttons
    $zoomIn.on('click', function() {
        if (zoomLevel < ZOOM_MAX) { zoomLevel = Math.min(ZOOM_MAX, zoomLevel + ZOOM_STEP); applyZoom(); }
    });
    $zoomOut.on('click', function() {
        if (zoomLevel > ZOOM_MIN) { zoomLevel = Math.max(ZOOM_MIN, zoomLevel - ZOOM_STEP); applyZoom(); }
    });
    $zoomReset.on('click', function() {
        zoomLevel = 1; applyZoom();
    });

    // Ctrl+scroll zoom on the canvas container
    $canvasWrap.on('wheel', function(e) {
        if (!e.ctrlKey && !e.metaKey) return;
        e.preventDefault();
        var delta = e.originalEvent.deltaY;
        if (delta < 0 && zoomLevel < ZOOM_MAX) {
            zoomLevel = Math.min(ZOOM_MAX, zoomLevel + ZOOM_STEP);
        } else if (delta > 0 && zoomLevel > ZOOM_MIN) {
            zoomLevel = Math.max(ZOOM_MIN, zoomLevel - ZOOM_STEP);
        }
        applyZoom();
    });

    // Keyboard shortcuts: + and - for zoom
    $(document).on('keydown', function(e) {
        // Only if focus is not in an input
        if ($(e.target).is('input, select, textarea')) return;
        if (e.key === '+' || e.key === '=') { $zoomIn.click(); e.preventDefault(); }
        if (e.key === '-')                   { $zoomOut.click(); e.preventDefault(); }
    });

    // Enter key finishes a drawn polygon
    $(document).on('keydown', function(e) {
        if (!isDrawing) return;
        if ($(e.target).is('input, select, textarea')) return;
        if (e.key === 'Enter') {
            e.preventDefault();
            saveDrawing();
        }
    });

    // ════════════════════════════════════════════════════════════════════
    //  PANNING (DRAG TO NAVIGATE ZOOMED IMAGE)
    // ════════════════════════════════════════════════════════════════════
    //
    //  Right mouse button or middle mouse button: drag to pan.
    //  The events are registered on the upper-canvas element of Fabric.js,
    //  because that element sits on top of the container and intercepts all mouse events.
    //  We only register these in initCanvas() once the canvas exists.

    function setupPanEvents() {
        // Fabric.js creates an extra <canvas class="upper-canvas"> above the real canvas.
        // That is the element that intercepts all mouse events.
        var upperCanvas = $canvasWrap.find('canvas.upper-canvas')[0] || $canvasWrap.find('canvas')[1];
        if (!upperCanvas) return;

        // Right mouse button + middle mouse button → pan
        $(upperCanvas).on('mousedown.svgmlpan', function(e) {
            if (e.button !== 1 && e.button !== 2) return;
            e.preventDefault();
            e.stopPropagation();
            isPanning = true;
            $canvasWrap.addClass('svgml-panning-active');
            panStartX  = e.clientX;
            panStartY  = e.clientY;
            panScrollX = $canvasWrap.scrollLeft();
            panScrollY = $canvasWrap.scrollTop();
        });

        // Block context menu on the upper canvas
        $(upperCanvas).on('contextmenu.svgmlpan', function(e) {
            e.preventDefault();
        });

        // Double-click to finish drawing
        $(upperCanvas).on('dblclick.svgmlpan', function(e) {
            if (isDrawing) {
                e.preventDefault();
                e.stopPropagation();
                saveDrawing();
            }
        });
    }

    // Globale mousemove en mouseup werken al op document-level
    $(document).on('mousemove.svgmlpan', function(e) {
        if (!isPanning) return;
        e.preventDefault();
        var dx = e.clientX - panStartX;
        var dy = e.clientY - panStartY;
        $canvasWrap.scrollLeft(panScrollX - dx);
        $canvasWrap.scrollTop(panScrollY - dy);
    });

    $(document).on('mouseup.svgmlpan', function(e) {
        if (!isPanning) return;
        isPanning = false;
        $canvasWrap.removeClass('svgml-panning-active');
    });

    // ════════════════════════════════════════════════════════════════════
    //  LINE STYLE SETTINGS (colour + thickness)
    // ════════════════════════════════════════════════════════════════════

    // When changing colour or thickness: update all existing polygons directly
    // and sync to hidden form fields so values are saved.
    $strokeColor.add($strokeWidth).on('input change', function() {
        var newColor = $strokeColor.val();
        var newWidth = parseFloat($strokeWidth.val()) || 1;

        // Update the constant so new polygons get the same style
        POLY_STROKE       = newColor;
        POLY_STROKE_WIDTH = newWidth;

        // Sync to hidden form fields (sent with save)
        $('#svgml_poly_stroke_color').val(newColor);
        $('#svgml_poly_stroke_width').val(newWidth);

        // Apply to all existing polygon objects on the canvas
        if (!canvas) return;
        $.each(polygons, function(i, p) {
            if (p.fabricObj) {
                p.fabricObj.set('stroke', newColor);
                p.fabricObj.set('strokeWidth', newWidth);
            }
        });
        canvas.renderAll();
    });

    // ════════════════════════════════════════════════════════════════════
    //  SNAP-TO-POINT
    // ════════════════════════════════════════════════════════════════════

    $snapToggle.on('change', function() {
        snapEnabled = $(this).is(':checked');
    });

    /**
     * Find the nearest point of other polygons (not the current polygon).
     * Returns the point if it falls within SNAP_RADIUS pixels, otherwise null.
     *
     * @param {number} x       – X in canvas pixels
     * @param {number} y       – Y in canvas pixels
     * @param {string} excludeId – ID of the polygon we are drawing/editing (to exclude)
     * @returns {{ x: number, y: number }|null}
     */
    function findSnapPoint(x, y, excludeId) {
        if (!snapEnabled) return null;

        var cw = canvasBaseW * zoomLevel;
        var ch = canvasBaseH * zoomLevel;
        var bestDist = SNAP_RADIUS;
        var bestPt   = null;

        $.each(polygons, function(i, p) {
            if (p.id === excludeId) return; // Skip own polygon
            $.each(p.points, function(j, pt) {
                var px = pt.x * cw;
                var py = pt.y * ch;
                var dist = Math.sqrt((px - x) * (px - x) + (py - y) * (py - y));
                if (dist < bestDist) {
                    bestDist = dist;
                    bestPt   = { x: px, y: py };
                }
            });
        });

        return bestPt;
    }

    // ════════════════════════════════════════════════════════════════════
    //  LOAD EXISTING POLYGONS
    // ════════════════════════════════════════════════════════════════════

    function loadExistingPolygons() {
        var json = $hiddenJson.val();
        if (!json) return;
        var data;
        try { data = JSON.parse(json); } catch(e) { return; }
        if (!Array.isArray(data)) return;

        clearAllPolygons();
        var cw = canvasBaseW * zoomLevel;
        var ch = canvasBaseH * zoomLevel;

        $.each(data, function(i, poly) {
            if (!poly.id || !poly.points || poly.points.length < 3) return;
            var pts = $.map(poly.points, function(pt) {
                return { x: pt.x * cw, y: pt.y * ch };
            });
            var fabricPoly = createFabricPolygon(pts, poly.id, true);
            polygons.push({ id: poly.id, points: poly.points, fabricObj: fabricPoly });
        });

        canvas.renderAll();
        renderPolygonList();
        updateStatus('');
    }

    // ════════════════════════════════════════════════════════════════════
    //  CREATE FABRIC.JS POLYGON
    // ════════════════════════════════════════════════════════════════════

    /**
     * @param {Array}   points  – [ { x, y } ] in canvas pixels
     * @param {string}  id      – Unique polygon ID
     * @param {boolean} addToCanvas – true = add to canvas
     */
    function createFabricPolygon(points, id, addToCanvas) {
        // Read current line style settings from the toolbar
        var currentStroke = $strokeColor.val() || POLY_STROKE;
        var currentWidth  = parseFloat($strokeWidth.val()) || POLY_STROKE_WIDTH;

        var poly = new fabric.Polygon(points, {
            fill:                POLY_FILL,
            stroke:              currentStroke,
            strokeWidth:         currentWidth,
            selectable:          true,
            hasControls:         false,
            hasBorders:          false,           // No blue Fabric.js selection border
            borderColor:         'transparent',   // Extra assurance: no visible selection
            lockMovementX:       true,
            lockMovementY:       true,
            perPixelTargetFind:  true,
            objectCaching:       false,
            svgmlId:             id,
        });
        if (addToCanvas !== false) canvas.add(poly);
        return poly;
    }

    // ════════════════════════════════════════════════════════════════════
    //  DRAW MODE
    // ════════════════════════════════════════════════════════════════════

    $drawBtn.on('click', function() {
        if (isDrawing || isEditing) return;
        exitEditMode();
        isDrawing = true;

        canvas.discardActiveObject();
        deselectAll();
        setPolygonsSelectable(false);

        $drawBtn.hide();
        $editBtn.hide();
        $doneBtn.show();
        $cancelBtn.show();
        showEditOptions();
        updateStatus('Klik op de afbeelding om punten te plaatsen. Min. 3 punten.');

        drawPoints  = [];
        drawHelpers = [];
    });

    /**
     * Canvas click: in draw mode adds a point.
     */
    function onCanvasClick(opt) {
        if (!isDrawing || isPanning) return;

        var pointer = canvas.getPointer(opt.e);
        var x = pointer.x;
        var y = pointer.y;

        // Snap: try to snap to an adjacent point
        var snap = findSnapPoint(x, y, '__new__');
        if (snap) { x = snap.x; y = snap.y; }

        drawPoints.push({ x: x, y: y });

        // Draw a dot
        var circle = new fabric.Circle({
            left: x - POINT_RADIUS, top: y - POINT_RADIUS,
            radius: POINT_RADIUS,
            fill: snap ? '#2a9d8f' : POINT_FILL,  // Teal if snapped, coral normally
            stroke: '#fff', strokeWidth: 1,
            selectable: false, evented: false, svgmlTemp: true
        });
        canvas.add(circle);
        drawHelpers.push(circle);

        // Connection line to the previous point
        if (drawPoints.length > 1) {
            var prev = drawPoints[drawPoints.length - 2];
            var line = new fabric.Line([ prev.x, prev.y, x, y ], {
                stroke: POINT_FILL, strokeWidth: 1.5, strokeDashArray: [4, 3],
                selectable: false, evented: false, svgmlTemp: true
            });
            canvas.add(line);
            drawHelpers.push(line);
        }

        canvas.renderAll();
        var remaining = Math.max(0, 3 - drawPoints.length);
        updateStatus('Punt ' + drawPoints.length + ' geplaatst.' +
                     (remaining > 0 ? ' ' + remaining + ' nog nodig.' : ' Klik op "Klaar" of ga door.'));
    }

    /**
     * Finish the polygon.
     */
    $doneBtn.on('click', function() {
        saveDrawing();
    });

    function saveDrawing() {
        // Draw mode: save new polygon
        if (isDrawing) {
            if (drawPoints.length < 3) {
                updateStatus('Minimaal 3 punten vereist.');
                return;
            }

            var polyId = promptForId();
            if (!polyId) { cancelDrawing(); return; }

            var cw = canvasBaseW * zoomLevel;
            var ch = canvasBaseH * zoomLevel;
            var normalizedPoints = $.map(drawPoints, function(pt) {
                return { x: round6(pt.x / cw), y: round6(pt.y / ch) };
            });

            cleanupHelpers();
            var fabricPoly = createFabricPolygon(drawPoints, polyId, true);
            polygons.push({ id: polyId, points: normalizedPoints, fabricObj: fabricPoly });
            finishDrawing();
            syncToHiddenField();
            renderPolygonList();
            updateStatus('Polygon "' + polyId + '" saved!');
            return;
        }

        // Edit mode: save modified points
        if (isEditing) {
            saveEditedPoints();
            exitEditMode();
            updateStatus('Punten opgeslagen.');
        }
    }

    $cancelBtn.on('click', function() {
        if (isDrawing)  cancelDrawing();
        if (isEditing)  { exitEditMode(); updateStatus('Bewerking geannuleerd.'); }
    });

    function cancelDrawing() {
        cleanupHelpers();
        finishDrawing();
        updateStatus('Tekenen geannuleerd.');
    }

    function promptForId() {
        while (true) {
            var polyId = prompt('Geef een unieke ID voor dit vlak (bijv. "unit-1"):');
            if (!polyId || !polyId.trim()) {
                return null;
            }
            polyId = polyId.trim();

            if (findPolyByIdAcrossLayers(polyId)) {
                alert('Deze ID bestaat al in een andere laag. Kies een andere ID.');
                continue;
            }

            return polyId;
        }
    }

    function finishDrawing() {
        isDrawing   = false;
        drawPoints  = [];
        drawHelpers = [];
        setPolygonsSelectable(true);
        $drawBtn.show();
        $editBtn.show();
        $doneBtn.hide();
        $cancelBtn.hide();
        hideEditOptions();
        $deleteBtn.hide();
    }

    function cleanupHelpers() {
        $.each(drawHelpers, function(i, obj) { canvas.remove(obj); });
        drawHelpers = [];
        canvas.renderAll();
    }

    function showEditOptions() {
        $('.svgml-edit-options').show();
        $('.svgml-polygon-image-actions').show();
    }

    function hideEditOptions() {
        $('.svgml-edit-options').hide();
        $('.svgml-polygon-image-actions').hide();
    }

    // ════════════════════════════════════════════════════════════════════
    //  EDIT MODE: move + delete points
    // ════════════════════════════════════════════════════════════════════

    /**
     * Start edit mode for the selected polygon.
     * Shows editable circles on each vertex that can be dragged.
     */
    $editBtn.on('click', function() {
        if (isDrawing || isEditing) return;

        // Er moet een vlak geselecteerd zijn
        if (!selectedPoly || !selectedPoly.svgmlId) {
            alert('Selecteer eerst een vlak op het canvas.');
            return;
        }

        enterEditMode(selectedPoly.svgmlId);
    });

    function enterEditMode(polyId) {
        var polyData = findPolyById(polyId);
        if (!polyData) return;

        isEditing   = true;
        editPolyRef = polyData;
        editCircles = [];
        selectedEditPt = null;

        // Make all polygons non-selectable
        setPolygonsSelectable(false);

        // Set the polygon visually as semi-transparent
        if (polyData.fabricObj) {
            polyData.fabricObj.set({ stroke: EDIT_POINT_FILL, strokeWidth: 3 });
        }

        // Calculate canvas pixel points from normalised coordinates
        var cw = canvasBaseW * zoomLevel;
        var ch = canvasBaseH * zoomLevel;

        $.each(polyData.points, function(i, pt) {
            var cx = pt.x * cw;
            var cy = pt.y * ch;

            // Create a draggable circle for each vertex
            var circle = new fabric.Circle({
                left:       cx - 6,
                top:        cy - 6,
                radius:     6,
                fill:       EDIT_POINT_FILL,
                stroke:     '#fff',
                strokeWidth:2,
                selectable: true,
                hasControls:false,
                hasBorders: false,
                originX:    'center',
                originY:    'center',
                left:       cx,
                top:        cy,
                // Custom data:
                svgmlEditPt:    true,
                svgmlPtIndex:   i,
                svgmlPolyId:    polyId,
            });

            canvas.add(circle);
            editCircles.push(circle);
        });

        // Listen for edit point movement
        canvas.on('object:moving', onEditPointMoving);
        canvas.on('object:modified', onEditPointDone);
        canvas.on('mouse:down', onCanvasClickInEditMode);
        canvas.on('mouse:move', onEditModeHover);

        // UI
        $drawBtn.hide();
        $editBtn.hide();
        $doneBtn.show().text('✓ Klaar');
        $cancelBtn.show();
        showEditOptions();
        $deleteBtn.show();

        canvas.renderAll();
        updateStatus('Sleep punten of klik op een rand om een punt toe te voegen. Backspace verwijdert een punt.');
    }

    /**
     * Called while an edit point is being dragged.
     * Snap-to-point + live polygon update.
     */
    function onEditPointMoving(opt) {
        var obj = opt.target;
        if (!obj || !obj.svgmlEditPt) return;

        var x = obj.left;
        var y = obj.top;

        // Snap
        var snap = findSnapPoint(x, y, editPolyRef ? editPolyRef.id : '');
        if (snap) {
            obj.set({ left: snap.x, top: snap.y });
            obj.set('fill', '#2a9d8f'); // Teal = snapped
        } else {
            obj.set('fill', EDIT_POINT_FILL);
        }

        // Live update the polygon
        updatePolygonFromEditCircles();
    }

    /**
     * Called when an edit point is dropped.
     */
    function onEditPointDone(opt) {
        var obj = opt.target;
        if (!obj || !obj.svgmlEditPt) return;
        updatePolygonFromEditCircles();
    }

    /**
     * Click on canvas in edit mode: add a point to the nearest edge.
     * Only works if no edit point was clicked.
     */
    // ── Hover preview: show a transparent point on the nearest edge ──
    var hoverPreviewCircle = null;

    function onEditModeHover(opt) {
        if (!isEditing || !editPolyRef || isPanning) {
            removeHoverPreview();
            return;
        }

        // Don't show if we're dragging an object
        if (opt.target && opt.target.svgmlEditPt) {
            removeHoverPreview();
            return;
        }

        var pointer = canvas.getPointer(opt.e);
        var px = pointer.x;
        var py = pointer.y;

        // Find nearest edge
        var bestDist = 20;
        var bestPt   = null;

        for (var i = 0; i < editCircles.length; i++) {
            var j = (i + 1) % editCircles.length;
            var proj = svgml_pointToSegment(px, py,
                editCircles[i].left, editCircles[i].top,
                editCircles[j].left, editCircles[j].top);
            if (proj.dist < bestDist) {
                bestDist = proj.dist;
                bestPt = { x: proj.x, y: proj.y };
            }
        }

        if (!bestPt) {
            removeHoverPreview();
            return;
        }

        // Create or update the preview point
        if (!hoverPreviewCircle) {
            hoverPreviewCircle = new fabric.Circle({
                radius:      5,
                fill:        'rgba(42, 157, 143, 0.5)',
                stroke:      '#2a9d8f',
                strokeWidth: 1.5,
                selectable:  false,
                evented:     false,
                originX:     'center',
                originY:     'center',
                svgmlTemp:   true,
            });
            canvas.add(hoverPreviewCircle);
        }
        hoverPreviewCircle.set({ left: bestPt.x, top: bestPt.y, visible: true });
        canvas.renderAll();
    }

    function removeHoverPreview() {
        if (hoverPreviewCircle) {
            canvas.remove(hoverPreviewCircle);
            hoverPreviewCircle = null;
        }
    }

    function onCanvasClickInEditMode(opt) {
        if (!isEditing || !editPolyRef) return;
        if (isPanning) return;

        // If clicked on an existing edit point or polygon, do nothing
        var target = opt.target;
        if (target && (target.svgmlEditPt || target.svgmlId)) return;

        var pointer = canvas.getPointer(opt.e);
        var px = pointer.x;
        var py = pointer.y;

        // Find the nearest edge (line between two consecutive points)
        var bestDist = 20; // Generous click zone in pixels for easy clicking
        var bestIdx  = -1; // Index of the point BEFORE the insertion point
        var bestPt   = null; // Projection point on the edge

        for (var i = 0; i < editCircles.length; i++) {
            var j = (i + 1) % editCircles.length; // Next point (wraparound)
            var ax = editCircles[i].left;
            var ay = editCircles[i].top;
            var bx = editCircles[j].left;
            var by = editCircles[j].top;

            // Calculate the projection of the click point on the line segment
            var proj = svgml_pointToSegment(px, py, ax, ay, bx, by);
            if (proj.dist < bestDist) {
                bestDist = proj.dist;
                bestIdx  = i;
                bestPt   = { x: proj.x, y: proj.y };
            }
        }

        if (bestIdx < 0 || !bestPt) return; // No edge close enough

        // Snap: check if the new point can snap
        var snap = findSnapPoint(bestPt.x, bestPt.y, editPolyRef.id);
        if (snap) { bestPt.x = snap.x; bestPt.y = snap.y; }

        // Create a new editable circle
        var newCircle = new fabric.Circle({
            left:       bestPt.x,
            top:        bestPt.y,
            radius:     6,
            fill:       EDIT_POINT_ACTIVE, // Coral: stand out as new point
            stroke:     '#fff',
            strokeWidth:2,
            selectable: true,
            hasControls:false,
            hasBorders: false,
            originX:    'center',
            originY:    'center',
            svgmlEditPt:    true,
            svgmlPtIndex:   bestIdx + 1,
            svgmlPolyId:    editPolyRef.id,
        });

        // Insert after bestIdx
        canvas.add(newCircle);
        editCircles.splice(bestIdx + 1, 0, newCircle);

        // Re-index all svgmlPtIndex values
        $.each(editCircles, function(k, c) { c.svgmlPtIndex = k; });

        // Update the polygon
        updatePolygonFromEditCircles();
        canvas.setActiveObject(newCircle);
        canvas.renderAll();

        updateStatus('Punt toegevoegd. ' + editCircles.length + ' punten in totaal.');
    }

    /**
     * Calculate the shortest distance from point (px, py) to line segment (ax,ay)-(bx,by).
     * Returns { dist, x, y } where (x, y) is the projection point.
     */
    function svgml_pointToSegment(px, py, ax, ay, bx, by) {
        var dx = bx - ax;
        var dy = by - ay;
        var lenSq = dx * dx + dy * dy;

        // If the segment has zero length, fall back to point distance
        if (lenSq === 0) {
            var d = Math.sqrt((px - ax) * (px - ax) + (py - ay) * (py - ay));
            return { dist: d, x: ax, y: ay };
        }

        // Parameter t: projection of point on the line (0 = start, 1 = end)
        var t = ((px - ax) * dx + (py - ay) * dy) / lenSq;
        t = Math.max(0, Math.min(1, t)); // Limit to the segment

        // Calculate the closest point on the segment
        var projX = ax + t * dx;
        var projY = ay + t * dy;
        var dist  = Math.sqrt((px - projX) * (px - projX) + (py - projY) * (py - projY));

        return { dist: dist, x: projX, y: projY };
    }

    /**
     * Rebuild the polygon based on the current positions of the edit points.
     */
    function updatePolygonFromEditCircles() {
        if (!editPolyRef || !editPolyRef.fabricObj) return;

        var newPoints = [];
        $.each(editCircles, function(i, c) {
            newPoints.push({ x: c.left, y: c.top });
        });

        // Remove the old polygon and create a new one
        canvas.remove(editPolyRef.fabricObj);
        editPolyRef.fabricObj = new fabric.Polygon(newPoints, {
            fill:                POLY_FILL_ACTIVE,
            stroke:              EDIT_POINT_FILL,
            strokeWidth:         3,
            selectable:          false,
            hasControls:         false,
            perPixelTargetFind:  true,
            objectCaching:       false,
            svgmlId:             editPolyRef.id,
        });
        canvas.add(editPolyRef.fabricObj);
        // Ensure the polygon is behind the edit points
        editPolyRef.fabricObj.sendToBack();
        if (bgImage) bgImage.sendToBack();

        canvas.renderAll();
    }

    /**
     * Save the edited points as normalised coordinates.
     */
    function saveEditedPoints() {
        if (!editPolyRef) return;

        var cw = canvasBaseW * zoomLevel;
        var ch = canvasBaseH * zoomLevel;

        var newNormalized = [];
        $.each(editCircles, function(i, c) {
            newNormalized.push({ x: round6(c.left / cw), y: round6(c.top / ch) });
        });

        editPolyRef.points = newNormalized;
        syncToHiddenField();
        renderPolygonList();
    }

    /**
     * Exit edit mode: remove edit points, restore polygon style.
     */
    function exitEditMode() {
        if (!isEditing) return;

        // Remove all edit points from the canvas
        $.each(editCircles, function(i, c) { canvas.remove(c); });
        editCircles    = [];
        selectedEditPt = null;

        // Restore polygon style
        if (editPolyRef && editPolyRef.fabricObj) {
            editPolyRef.fabricObj.set({
                fill:        POLY_FILL,
                stroke:      POLY_STROKE,
                strokeWidth: POLY_STROKE_WIDTH,
                selectable:  true
            });
        }

        // Remove event listeners for editing
        canvas.off('object:moving', onEditPointMoving);
        canvas.off('object:modified', onEditPointDone);
        canvas.off('mouse:down', onCanvasClickInEditMode);
        canvas.off('mouse:move', onEditModeHover);

        // Remove hover preview point if it exists
        removeHoverPreview();

        editPolyRef = null;
        isEditing   = false;

        setPolygonsSelectable(true);
        $drawBtn.show();
        $editBtn.show();
        $doneBtn.hide().text('✓ Klaar');
        $cancelBtn.hide();
        hideEditOptions();
        $deleteBtn.hide();

        canvas.renderAll();
    }

    // Backspace/Delete: delete selected edit point
    $(document).on('keydown', function(e) {
        if (!isEditing) return;
        if (e.key !== 'Backspace' && e.key !== 'Delete') return;
        if ($(e.target).is('input, select, textarea')) return;

        e.preventDefault();

        // Which edit point is selected?
        var activeObj = canvas.getActiveObject();
        if (!activeObj || !activeObj.svgmlEditPt) return;

        // Minimum 3 points required
        if (editCircles.length <= 3) {
            updateStatus('Minimaal 3 punten vereist. Verwijder het hele vlak om het te verwijderen.');
            return;
        }

        // Remove the circle from the canvas and array
        var idx = editCircles.indexOf(activeObj);
        if (idx > -1) {
            canvas.remove(activeObj);
            editCircles.splice(idx, 1);
            // Re-index the svgmlPtIndex values
            $.each(editCircles, function(i, c) { c.svgmlPtIndex = i; });
            updatePolygonFromEditCircles();
            canvas.discardActiveObject();
            updateStatus('Punt verwijderd. ' + editCircles.length + ' punten over.');
        }
    });

    // Mark selected edit point visually
    // (event listeners are in initCanvas() so canvas is not null)

    function highlightEditPt(opt) {
        unhighlightEditPts();
        var obj = opt.selected ? opt.selected[0] : null;
        if (obj && obj.svgmlEditPt) {
            obj.set('fill', EDIT_POINT_ACTIVE);
            obj.set('radius', 8);
            selectedEditPt = obj;
            canvas.renderAll();
        }
    }
    function unhighlightEditPts() {
        if (selectedEditPt) {
            selectedEditPt.set('fill', EDIT_POINT_FILL);
            selectedEditPt.set('radius', 6);
            selectedEditPt = null;
            if (canvas) canvas.renderAll();
        }
    }

    // ════════════════════════════════════════════════════════════════════
    //  SELECTION & DELETE
    // ════════════════════════════════════════════════════════════════════

    function onObjectSelected(opt) {
        var obj = opt.selected ? opt.selected[0] : null;
        if (!obj) return;
        // Don't treat edit points as polygon selection
        if (obj.svgmlEditPt) return;
        if (!obj.svgmlId) return;

        // If we're already in edit mode and the hover preview is active,
        // do nothing — the user is trying to add a point.
        if (isEditing && hoverPreviewCircle) return;

        // Deselect the previously selected polygon before selecting the new one
        if (selectedPoly && selectedPoly !== obj) {
            selectedPoly.set('fill', POLY_FILL);
        }

        selectedPoly = obj;
        obj.set('fill', POLY_FILL_ACTIVE);
        canvas.renderAll();

        $polygonList.find('tr').removeClass('svgml-poly-row-active');
        $polygonList.find('tr[data-poly-id="' + obj.svgmlId + '"]').addClass('svgml-poly-row-active');

        // Go directly to edit mode if we're not already drawing or editing
        if (!isDrawing && !isEditing) {
            updateStatus('Vlak "' + obj.svgmlId + '" geselecteerd. Klik op Bewerk punten om te bewerken.');
        }
    }

    function onObjectDeselected() {
        if (selectedPoly && !isEditing) {
            selectedPoly.set('fill', POLY_FILL);
            canvas.renderAll();
        }
        selectedPoly = null;
        $polygonList.find('tr').removeClass('svgml-poly-row-active');
    }

    function deselectAll() {
        canvas.discardActiveObject();
        onObjectDeselected();
    }

    function setPolygonsSelectable(val) {
        $.each(polygons, function(i, p) {
            if (p.fabricObj) p.fabricObj.set('selectable', val);
        });
    }

    // Delete selected polygon
    $deleteBtn.on('click', function() {
        if (isEditing) {
            // In edit mode: delete the entire polygon
            if (!editPolyRef) return;
            if (!confirm('Weet je zeker dat je vlak "' + editPolyRef.id + '" wilt verwijderen?')) return;
            var id = editPolyRef.id;
            exitEditMode();
            deletePolygon(id);
            return;
        }
        if (!selectedPoly || !selectedPoly.svgmlId) {
            alert('Selecteer eerst een vlak.');
            return;
        }
        if (!confirm('Weet je zeker dat je vlak "' + selectedPoly.svgmlId + '" wilt verwijderen?')) return;
        deletePolygon(selectedPoly.svgmlId);
    });

    function deletePolygon(id) {
        var poly = findPolyById(id);
        if (poly && poly.fabricObj) canvas.remove(poly.fabricObj);
        polygons = $.grep(polygons, function(p) { return p.id !== id; });
        selectedPoly = null;
        syncToHiddenField();
        renderPolygonList();
        updateStatus('Vlak "' + id + '" verwijderd.');
    }

    // ════════════════════════════════════════════════════════════════════
    //  POLYGON LIST (TABLE)
    // ════════════════════════════════════════════════════════════════════

    function renderPolygonList() {
        $polygonList.empty();
        if (polygons.length === 0) {
            $polygonList.append(
                '<tr><td colspan="3" style="text-align:center;color:#999;font-style:italic;padding:14px;">' +
                'Nog geen polygonen getekend</td></tr>'
            );
            updateDuplicateWarning();
            return;
        }
        $.each(polygons, function(i, poly) {
            $polygonList.append(
                '<tr data-poly-id="' + escHtml(poly.id) + '">' +
                    '<td style="padding:6px 10px;">' +
                        '<input type="text" class="svgml-poly-id-input regular-text" ' +
                               'value="' + escHtml(poly.id) + '" ' +
                               'data-original-id="' + escHtml(poly.id) + '" ' +
                               'style="width:100%">' +
                    '</td>' +
                    '<td style="padding:6px 10px;">' + poly.points.length + '</td>' +
                    '<td style="padding:6px 10px; white-space:nowrap;">' +
                        '<button type="button" class="button button-small svgml-poly-focus" ' +
                                'data-poly-id="' + escHtml(poly.id) + '">🔍</button> ' +
                        '<button type="button" class="button button-small svgml-poly-edit-btn" ' +
                                'data-poly-id="' + escHtml(poly.id) + '" title="Bewerk punten">✏️</button> ' +
                        '<button type="button" class="button button-small svgml-btn-coral svgml-poly-remove" ' +
                                'data-poly-id="' + escHtml(poly.id) + '">✕</button>' +
                    '</td>' +
                '</tr>'
            );
        });
        updateDuplicateWarning();
    }

    // ── List events ──────────────────────────────────────────────────────

    // Highlight polygon on hover
    $(document).on('mouseenter', '#svgml_poly_list tr[data-poly-id]', function() {
        var id = $(this).data('poly-id');
        var poly = findPolyById(id);
        if (poly && poly.fabricObj && !isEditing) {
            poly.fabricObj.set('fill', POLY_FILL_ACTIVE);
            canvas.renderAll();
        }
    });

    // Unhighlight polygon on leave (unless selected)
    $(document).on('mouseleave', '#svgml_poly_list tr[data-poly-id]', function() {
        var id = $(this).data('poly-id');
        var poly = findPolyById(id);
        if (poly && poly.fabricObj && !isEditing) {
            // Only unhighlight if this isn't the selected polygon
            if (!selectedPoly || selectedPoly !== poly.fabricObj) {
                poly.fabricObj.set('fill', POLY_FILL);
            }
            canvas.renderAll();
        }
    });

    // Focus on polygon
    $(document).on('click', '.svgml-poly-focus', function() {
        var poly = findPolyById($(this).data('poly-id'));
        if (poly && poly.fabricObj) {
            canvas.setActiveObject(poly.fabricObj);
            canvas.renderAll();
        }
    });

    // Edit points from list
    $(document).on('click', '.svgml-poly-edit-btn', function() {
        if (isEditing) exitEditMode();
        if (isDrawing) return;
        var id = $(this).data('poly-id');
        // Select the polygon first
        var poly = findPolyById(id);
        if (poly && poly.fabricObj) {
            canvas.setActiveObject(poly.fabricObj);
            selectedPoly = poly.fabricObj;
        }
        enterEditMode(id);
    });

    // Delete from list
    $(document).on('click', '.svgml-poly-remove', function() {
        var id = $(this).data('poly-id');
        if (!confirm('Weet je zeker dat je vlak "' + id + '" wilt verwijderen?')) return;
        if (isEditing && editPolyRef && editPolyRef.id === id) exitEditMode();
        deletePolygon(id);
    });

    // Change ID
    $(document).on('change', '.svgml-poly-id-input', function() {
        var $input     = $(this);
        var originalId = $input.data('original-id');
        var newId      = $.trim($input.val());

        if (!newId) { alert('ID cannot be empty.'); $input.val(originalId); return; }

        var dup = findPolyByIdAcrossLayers(newId, originalId);
if (dup) { alert('Deze ID bestaat al in een andere laag of polygon.'); $input.val(originalId); return; }

        var poly = findPolyById(originalId);
        if (poly) {
            poly.id = newId;
            if (poly.fabricObj) poly.fabricObj.svgmlId = newId;
        }
        $input.data('original-id', newId);
        $input.closest('tr').attr('data-poly-id', newId);
        syncToHiddenField();
        updateStatus('ID: "' + originalId + '" → "' + newId + '"');
        updateDuplicateWarning();
    });

    // ════════════════════════════════════════════════════════════════════
    //  SYNC & HELPER FUNCTIONS
    // ════════════════════════════════════════════════════════════════════

    function syncToHiddenField() {
        var data = $.map(polygons, function(p) {
            return { id: p.id, points: p.points };
        });
        var jsonStr = JSON.stringify(data);
        $hiddenJson.val(jsonStr);
        
        // Debug logging
        console.log('📋 Synced ' + polygons.length + ' polygons to hidden field', {
            polygonCount: polygons.length,
            polygonIds: $.map(polygons, function(p) { return p.id; }),
            hidden_field_name: $hiddenJson.attr('name'),
            data_length: jsonStr.length
        });

        // Also sync to layers JSON if layers exist
        if (typeof syncLayersToHiddenField === 'function') {
            syncLayersToHiddenField();
        }
    }

    function findPolyById(id) {
        var found = null;
        $.each(polygons, function(i, p) { if (p.id === id) { found = p; return false; } });
        return found;
    }

    function findPolyByIdAcrossLayers(id, ignoreOriginalId) {
        var found = null;

        $.each(polygons, function(i, p) {
            if (p.id === id && p.id !== ignoreOriginalId) { found = p; return false; }
        });
        if (found) return found;

        $.each(layers, function(li, layer) {
            if (li === activeLayerIndex) return true;
            $.each(layer.polygons || [], function(i, p) {
                if (p.id === id && p.id !== ignoreOriginalId) { found = p; return false; }
            });
            if (found) return false;
            return true;
        });

        return found;
    }

    function updateDuplicateWarning() {
        if (canvas && activeLayerIndex >= 0 && activeLayerIndex < layers.length) {
            layers[activeLayerIndex].polygons = $.map(polygons, function(p) {
                return { id: p.id, points: p.points };
            });
        }

        var counts = {};
        $.each(layers, function(li, layer) {
            $.each(layer.polygons || [], function(i, p) {
                if (!p.id) return true;
                counts[p.id] = (counts[p.id] || 0) + 1;
            });
        });

        var duplicates = [];
        $.each(counts, function(id, count) {
            if (count > 1) duplicates.push(id);
        });

        var $warning = $('#svgml-duplicate-warning');
        if (duplicates.length > 0) {
            $warning.html('Duplicate polygon IDs found across layers: <strong>' + escHtml(duplicates.join(', ')) + '</strong>. Polygon IDs must be unique across all layers.');
            $warning.show();
        } else {
            $warning.hide();
        }
    }

    function clearAllPolygons() {
        $.each(polygons, function(i, p) { if (p.fabricObj) canvas.remove(p.fabricObj); });
        polygons = [];
    }

    function updateStatus(msg) { $statusText.text(msg); }

    function round6(n) { return Math.round(n * 1000000) / 1000000; }

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;')
                          .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
                          .replace(/'/g,'&#039;');
    }

    // ════════════════════════════════════════════════════════════════════
    //  MULTI-LAYER MANAGEMENT
    // ════════════════════════════════════════════════════════════════════

    /**
     * Initialise layers from svgmlAdmin.layers (passed from PHP)
     * Fall back to single-layer mode if needed
     */
    function initLayersFromAdmin() {
        if (svgmlAdmin.layers && Array.isArray(svgmlAdmin.layers) && svgmlAdmin.layers.length > 0) {
            layers = $.map(svgmlAdmin.layers, function(layer) {
                return {
                    name: layer.name || 'Layer',
                    imageAttachmentId: layer.image_attachment_id,
                    imageUrl: '',
                    polygons: layer.polygons || [],
                    strokeColor: layer.stroke_color || '#2a9d8f',
                    strokeWidth: layer.stroke_width || '1'
                };
            });
        } else {
            // Fall back to single-layer mode: read from the current image input
            var imgId = $imageInput.val();
            if (imgId) {
                layers = [{
                    name: 'Image',
                    imageAttachmentId: imgId,
                    imageUrl: '',
                    polygons: [],
                    strokeColor: $strokeColor.val() || '#2a9d8f',
                    strokeWidth: $strokeWidth.val() || '1'
                }];
            }
        }
    }

    /**
     * Render the layer tabs in the UI
     */
    function renderLayerTabs() {
        var $tabContainer = $('#svgml-layer-tabs-container');
        $tabContainer.empty();

        // Show tabs only if there are 2+ layers
        if (layers.length < 2) return;

        var tabsHtml = '<div class="svgml-layer-tabs">';
        $.each(layers, function(idx, layer) {
            var isActive = idx === activeLayerIndex ? ' svgml-layer-tab-active' : '';
            tabsHtml += '<button type="button" class="svgml-layer-tab' + isActive + '" ' +
                       'data-layer-idx="' + idx + '">' +
                       '<span class="dashicons dashicons-format-image" style="font-size:16px;width:16px;height:16px;line-height:16px;margin-right:2px;opacity:0.5;"></span>' +
                       '<input type="text" class="svgml-layer-name-input" value="' +
                       escHtml(layer.name) + '" data-layer-idx="' + idx + '" ' +
                       'title="Dubbelklik om naam te wijzigen">' +
                       '<span class="svgml-tab-close" data-layer-idx="' + idx + '" title="Verwijder laag">✕</span>' +
                       '</button>';
        });
        tabsHtml += '</div>';

        $tabContainer.html(tabsHtml);
    }

    /**
     * Switch to another layer: save current, load new
     */
    /**
     * Switch to another layer.
     * Saves the current layer state, cleans up the canvas and loads the new layer.
     *
     * @param {number} idx  Index of the desired layer in the layers array
     */
    function switchToLayer(idx) {
        if (idx === activeLayerIndex || idx < 0 || idx >= layers.length) return;

        // Save the state of the current layer
        if (canvas && activeLayerIndex >= 0 && activeLayerIndex < layers.length) {
            layers[activeLayerIndex].polygons = $.map(polygons, function(p) {
                return { id: p.id, points: p.points };
            });
            layers[activeLayerIndex].strokeColor = $strokeColor.val() || '#2a9d8f';
            layers[activeLayerIndex].strokeWidth  = $strokeWidth.val() || '1';
        }

        // Stop any edit or draw mode
        if (isEditing) exitEditMode();
        if (isDrawing) cancelDrawing();

        // Clean up the current canvas
        if (canvas) canvas.dispose();
        canvas   = null;
        polygons = [];
        drawPoints  = [];
        drawHelpers = [];

        // Switch active index and mark tab
        activeLayerIndex = idx;
        $('.svgml-layer-tab').removeClass('svgml-layer-tab-active');
        $('.svgml-layer-tab[data-layer-idx="' + idx + '"]').addClass('svgml-layer-tab-active');

        // Load the new layer (image + polygons)
        loadLayer(idx);
    }

    /**
     * Sync all layers to hidden JSON field for form submission
     */
    function syncLayersToHiddenField() {
        // Save current layer first
        if (canvas && activeLayerIndex >= 0 && activeLayerIndex < layers.length) {
            layers[activeLayerIndex].polygons = $.map(polygons, function(p) {
                return { id: p.id, points: p.points };
            });
            layers[activeLayerIndex].strokeColor = $strokeColor.val() || '#2a9d8f';
            layers[activeLayerIndex].strokeWidth = $strokeWidth.val() || '1';
        }

        // Create JSON with all layers
        var layersData = $.map(layers, function(layer) {
            return {
                name: layer.name,
                image_attachment_id: layer.imageAttachmentId,
                polygons: layer.polygons,
                stroke_color: layer.strokeColor,
                stroke_width: layer.strokeWidth
            };
        });

        var jsonStr = JSON.stringify(layersData);
        // Update the hidden field (for form submission)
        $('#svgml_layers_json').val(jsonStr);

        // Debug logging
        console.log('📦 Synced ' + layers.length + ' layer(s) to hidden field', {
            layers: layers.length,
            layerNames: $.map(layers, function(l) { return l.name; }),
            totalPolygons: $.map(layers, function(l) { return (l.polygons || []).length; }).reduce(function(a, b) { return a + b; }, 0),
            hidden_field_name: $('#svgml_layers_json').attr('name'),
            data_length: jsonStr.length,
            activeLayer: activeLayerIndex,
            currentPolygons: polygons.length
        });
    }

    // ════════════════════════════════════════════════════════════════════
    //  INITIALISATION ON LOAD
    // ════════════════════════════════════════════════════════════════════

    /**
     * Load a specific layer on the canvas: fetch the image via
     * AJAX and display the associated polygons.
     *
     * @param {number} idx  Index in the layers array
     */
    function loadLayer(idx) {
        var layer = layers[idx];
        if (!layer || !layer.imageAttachmentId) return;

        // Set line style BEFORE loading polygons, so createFabricPolygon()
        // reads the correct values from the toolbar inputs.
        $strokeColor.val(layer.strokeColor || '#2a9d8f');
        $strokeWidth.val(layer.strokeWidth  || '1');
        POLY_STROKE       = $strokeColor.val();
        POLY_STROKE_WIDTH = parseFloat($strokeWidth.val()) || 1;

        $.post(svgmlAdmin.ajaxUrl, {
            action:        'svgml_get_image_url',
            nonce:         svgmlAdmin.nonce,
            attachment_id: layer.imageAttachmentId,
            map_id:        svgmlAdmin.mapId || 0
        }, function(response) {
            if (response.success && response.data.url) {
                layer.imageUrl = response.data.url;
                initCanvas();

                // Provide a callback that loads the layer-specific polygons
                // AFTER the background image is ready (Fabric.js async).
                loadImageOnCanvas(response.data.url, function() {
                    // Clear any old polygons
                    clearAllPolygons();

                    // Load polygons from this specific layer
                    var cw = canvasBaseW * zoomLevel;
                    var ch = canvasBaseH * zoomLevel;

                    $.each(layer.polygons || [], function(i, poly) {
                        if (!poly.id || !poly.points || poly.points.length < 3) return;
                        var pts = $.map(poly.points, function(pt) {
                            return { x: pt.x * cw, y: pt.y * ch };
                        });
                        var fabricPoly = createFabricPolygon(pts, poly.id, true);
                        polygons.push({ id: poly.id, points: poly.points, fabricObj: fabricPoly });
                    });

                    canvas.renderAll();
                    renderPolygonList();
                });
            }
        });
    }

    (function init() {
        var sourceType = $('input[name="svgml_source_type"]:checked').val();
        if (sourceType !== 'image') return;

        // Initialise layers from PHP data
        initLayersFromAdmin();

        // ── Event handlers for the layer system ──────────────────────────
        // These are ALWAYS bound (even with 1 or 0 layers) so they
        // also work after the user dynamically adds a layer.

        // Click on a layer tab: switch to that layer
        $(document).on('click', '.svgml-layer-tab', function(e) {
            // Prevent switching if the user clicks on the name field or close button
            if ($(e.target).hasClass('svgml-layer-name-input') ||
                $(e.target).hasClass('svgml-tab-close')) return;
            var idx = $(this).data('layer-idx');
            switchToLayer(parseInt(idx, 10));
        });

        // Change the name of a layer in the tab
        $(document).on('change blur', '.svgml-layer-name-input', function() {
            var idx = parseInt($(this).data('layer-idx'), 10);
            var newName = $.trim($(this).val()) || 'Layer ' + (idx + 1);
            layers[idx].name = newName;
            syncLayersToHiddenField();
        });

        // ✕ button: delete a layer (only if >1 layer)
        $(document).on('click', '.svgml-tab-close', function(e) {
            e.stopPropagation();
            var idx = parseInt($(this).data('layer-idx'), 10);
            if (layers.length <= 1) {
                alert('Je hebt minimaal één laag nodig.');
                return;
            }
            if (!confirm('Weet je zeker dat je deze laag wilt verwijderen? Alle polygonen op deze laag worden verwijderd.')) return;

            layers.splice(idx, 1);

            // Correct activeLayerIndex if needed
            if (activeLayerIndex >= layers.length) {
                activeLayerIndex = layers.length - 1;
            }
            if (activeLayerIndex === idx) {
                // Switch to the first available layer
                activeLayerIndex = 0;
            } else if (activeLayerIndex > idx) {
                activeLayerIndex--;
            }

            renderLayerTabs();
            syncLayersToHiddenField();
            loadLayer(activeLayerIndex);
        });

        // "Add floor or viewpoint" – open WordPress Media Uploader
        $(document).on('click', '#svgml-add-layer', function() {
            // Open WordPress media library so the user can choose an image
            var mediaFrame = wp.media({
                title:    'Choose an image for the new layer',
                button:   { text: 'Use this image' },
                multiple: false,
                library:  { type: 'image' }
            });

            mediaFrame.on('select', function() {
                var attachment = mediaFrame.state().get('selection').first().toJSON();

                // Save the current layer state before switching
                if (canvas && activeLayerIndex >= 0 && activeLayerIndex < layers.length) {
                    layers[activeLayerIndex].polygons = $.map(polygons, function(p) {
                        return { id: p.id, points: p.points };
                    });
                    layers[activeLayerIndex].strokeColor = $strokeColor.val() || '#2a9d8f';
                    layers[activeLayerIndex].strokeWidth  = $strokeWidth.val() || '1';
                }

                // Create a new layer with the chosen image
                var newLayer = {
                    name:              'Layer ' + (layers.length + 1),
                    imageAttachmentId: attachment.id,
                    imageUrl:          attachment.url,
                    polygons:          [],
                    strokeColor:       '#2a9d8f',
                    strokeWidth:       '1'
                };
                layers.push(newLayer);

                // Switch to the new layer
                activeLayerIndex = layers.length - 1;
                renderLayerTabs();
                syncLayersToHiddenField();
                loadLayer(activeLayerIndex);
            });

            mediaFrame.open();
        });

        // ── Initial load ─────────────────────────────────────────────────
        if (layers.length === 0) return;

        // Show tabs if there are multiple layers
        if (layers.length > 1) {
            renderLayerTabs();
        }

        // Load the first layer on the canvas
        loadLayer(0);
    })();

    // ════════════════════════════════════════════════════════════════════
    // EXPOSE SYNC FUNCTIONS GLOBALLY
    // So they can be called from outside (e.g., form submission handlers)
    // ════════════════════════════════════════════════════════════════════
    window.svgmlSyncLayersToHidden = syncLayersToHiddenField;
    window.svgmlSyncPolygonsToHidden = syncToHiddenField;

});
