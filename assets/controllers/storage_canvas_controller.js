import { Controller } from '@hotwired/stimulus';
import Konva from 'konva';

export default class extends Controller {
    static targets = [
        'container', 'sidebar', 'storageList', 'form',
        'numberInput', 'typeSelect', 'saveBtn', 'deleteBtn', 'cancelBtn',
        'addBtn', 'copyBtn', 'snapCheckbox',
        'coordX', 'coordY', 'coordW', 'coordH', 'coordR',
        'zoomLabel', 'minimap',
    ];
    static values = {
        mapImage: String,
        storages: Array,
        storageTypes: Array,
        apiUrl: String
    }

    GRID_SIZE = 10;
    MIN_CREATE_SIZE = 2;
    MOVE_THRESHOLD = 5;

    connect() {
        this.storages = [...this.storagesValue];
        this.selectedIndex = -1;
        this.snapping = false;
        this.isCreating = false;
        this.creationStart = null;
        this.creationRect = null;
        this.mouseDownPos = null;
        this.zoomLevel = 1;
        this.spacePressed = false;
        this.isPanning = false;
        this.panLastPos = null;
        this.boundPanMove = null;
        this.boundPanUp = null;
        this.modifierPanActive = false;

        this.minimapStage = null;
        this.minimapBgLayer = null;
        this.minimapStorageLayer = null;
        this.minimapViewportLayer = null;
        this.minimapViewportRect = null;

        this.initializeStage();
        this.initializeMinimap();
        this.loadMapImage();
        if (!this.mapImageValue) {
            this.renderStorages();
        }
        this.renderStorageList();

        this.boundKeyDown = this.onKeyDown.bind(this);
        this.boundKeyUp = this.onKeyUp.bind(this);
        document.addEventListener('keydown', this.boundKeyDown);
        document.addEventListener('keyup', this.boundKeyUp);
    }

    disconnect() {
        if (this.boundKeyDown) {
            document.removeEventListener('keydown', this.boundKeyDown);
        }
        if (this.boundKeyUp) {
            document.removeEventListener('keyup', this.boundKeyUp);
        }
        if (this.boundPanMove) {
            window.removeEventListener('mousemove', this.boundPanMove);
        }
        if (this.boundPanUp) {
            window.removeEventListener('mouseup', this.boundPanUp);
        }
        if (this.minimapStage) {
            this.minimapStage.destroy();
        }
        if (this.stage) {
            this.stage.destroy();
        }
    }

    initializeStage() {
        const width = this.containerTarget.clientWidth;
        const height = 600;

        this.stage = new Konva.Stage({
            container: this.containerTarget,
            width: width,
            height: height,
        });

        this.bgLayer = new Konva.Layer({ listening: false });
        this.storageLayer = new Konva.Layer();

        this.stage.add(this.bgLayer);
        this.stage.add(this.storageLayer);

        // Background
        this.bgRect = new Konva.Rect({
            x: 0, y: 0,
            width: width, height: height,
            fill: '#f3f4f6',
        });
        this.bgLayer.add(this.bgRect);

        // Transformer for selected storage
        this.transformer = new Konva.Transformer({
            rotateEnabled: true,
            enabledAnchors: ['top-left', 'top-right', 'bottom-left', 'bottom-right'],
            rotationSnaps: [0, 45, 90, 135, 180, 225, 270, 315],
            rotateAnchorOffset: 40,
            rotateAnchorCursor: 'grab',
            borderStroke: '#2563eb',
            borderStrokeWidth: 2,
            anchorStroke: '#2563eb',
            anchorFill: '#ffffff',
            anchorSize: 12,
            anchorCornerRadius: 2,
            boundBoxFunc: (oldBox, newBox) => {
                if (Math.abs(newBox.width) < 2 || Math.abs(newBox.height) < 2) {
                    return oldBox;
                }
                return newBox;
            },
        });
        this.storageLayer.add(this.transformer);

        // Creation rectangle (dashed outline while drawing)
        this.creationRect = new Konva.Rect({
            visible: false,
            stroke: '#3b82f6',
            strokeWidth: 2,
            dash: [5, 5],
            listening: false,
        });
        this.storageLayer.add(this.creationRect);

        // Stage events for draw-to-create and deselection
        this.stage.on('mousedown', (e) => this.onStageMouseDown(e));
        this.stage.on('mousemove', (e) => this.onStageMouseMove(e));
        this.stage.on('mouseup', (e) => this.onStageMouseUp(e));
        this.bgLayer.draw();
    }

    loadMapImage() {
        if (this.mapImageValue) {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => {
                this.mapImg = img;
                this.fitImageToStage();
                this.denormalizeAllStorages();
                this.renderStorages();
            };
            img.onerror = () => {
                this.mapImg = null;
                this.drawGrid();
            };
            img.src = this.mapImageValue;
        } else {
            this.drawGrid();
        }
    }

    fitImageToStage() {
        const stageW = this.stage.width();
        const stageH = this.stage.height();
        const imgW = this.mapImg.width;
        const imgH = this.mapImg.height;

        this.imgScale = Math.min(stageW / imgW, stageH / imgH);
        this.imgOffsetX = (stageW - imgW * this.imgScale) / 2;
        this.imgOffsetY = (stageH - imgH * this.imgScale) / 2;

        const konvaImg = new Konva.Image({
            x: this.imgOffsetX,
            y: this.imgOffsetY,
            image: this.mapImg,
            width: imgW * this.imgScale,
            height: imgH * this.imgScale,
        });

        this.bgLayer.add(konvaImg);
        this.bgLayer.draw();
        this.renderMinimap();
    }

    drawGrid() {
        const gridSize = 50;
        const w = this.stage.width();
        const h = this.stage.height();

        for (let x = 0; x < w; x += gridSize) {
            this.bgLayer.add(new Konva.Line({
                points: [x, 0, x, h],
                stroke: '#e5e7eb',
                strokeWidth: 1,
            }));
        }
        for (let y = 0; y < h; y += gridSize) {
            this.bgLayer.add(new Konva.Line({
                points: [0, y, w, y],
                stroke: '#e5e7eb',
                strokeWidth: 1,
            }));
        }
        this.bgLayer.draw();
        this.renderMinimap();
    }

    // --- Stage mouse events for draw-to-create ---

    onStageMouseDown(e) {
        // Middle mouse button, space+left click, or Ctrl/Cmd+left click = pan
        if (e.evt.button === 1 || (e.evt.button === 0 && (this.spacePressed || e.evt.ctrlKey || e.evt.metaKey))) {
            this.isPanning = true;
            this.panLastPos = { x: e.evt.clientX, y: e.evt.clientY };
            this.stage.container().style.cursor = 'grabbing';
            this.boundPanMove = this.onPanMove.bind(this);
            this.boundPanUp = this.onPanUp.bind(this);
            window.addEventListener('mousemove', this.boundPanMove);
            window.addEventListener('mouseup', this.boundPanUp);
            e.evt.preventDefault();
            return;
        }

        // Only left click
        if (e.evt.button !== 0) return;

        // Only act on clicks on the empty stage (not on storage groups)
        if (e.target !== this.stage && e.target !== this.bgRect) return;

        const pos = this.getScaledPointerPosition();
        this.mouseDownPos = { x: pos.x, y: pos.y };
        this.isCreating = false; // Will become true after threshold
    }

    onStageMouseMove(e) {
        if (this.isPanning) return; // Handled by window listener

        if (!this.mouseDownPos) return;

        const pos = this.getScaledPointerPosition();
        const dx = pos.x - this.mouseDownPos.x;
        const dy = pos.y - this.mouseDownPos.y;

        if (!this.isCreating && (Math.abs(dx) > this.MOVE_THRESHOLD || Math.abs(dy) > this.MOVE_THRESHOLD)) {
            this.isCreating = true;
            this.creationStart = { ...this.mouseDownPos };
        }

        if (this.isCreating) {
            this.stage.container().style.cursor = 'crosshair';
            const x = Math.min(this.creationStart.x, pos.x);
            const y = Math.min(this.creationStart.y, pos.y);
            const w = Math.abs(pos.x - this.creationStart.x);
            const h = Math.abs(pos.y - this.creationStart.y);

            this.creationRect.setAttrs({ x, y, width: w, height: h, visible: true });
            this.storageLayer.draw();
        }
    }

    onStageMouseUp(e) {
        if (this.isPanning) return; // Handled by window listener

        if (this.isCreating && this.creationStart) {
            const pos = this.getScaledPointerPosition();
            const w = Math.abs(pos.x - this.creationStart.x);
            const h = Math.abs(pos.y - this.creationStart.y);

            if (w > this.MIN_CREATE_SIZE && h > this.MIN_CREATE_SIZE) {
                const x = Math.min(this.creationStart.x, pos.x);
                const y = Math.min(this.creationStart.y, pos.y);
                this.createNewStorage(x, y, w, h);
            }
        } else if (this.mouseDownPos && !this.isCreating) {
            // Simple click on empty area → deselect
            if (e.target === this.stage || e.target === this.bgRect) {
                this.deselectStorage();
            }
        }

        this.creationRect.visible(false);
        this.storageLayer.draw();
        this.isCreating = false;
        this.creationStart = null;
        this.mouseDownPos = null;
        this.stage.container().style.cursor = 'crosshair';
    }

    // --- Storage CRUD ---

    createNewStorage(x, y, width, height) {
        const newStorage = {
            id: null,
            number: this.getNextStorageNumber(),
            storageTypeId: this.storageTypesValue[0]?.id || null,
            coordinates: { x, y, width, height, rotation: 0 },
            isNew: true,
            modified: true,
        };
        this.storages.push(newStorage);
        const index = this.storages.length - 1;

        const group = this.createStorageGroup(newStorage, index);
        this.storageLayer.add(group);
        // Ensure transformer stays on top
        this.transformer.moveToTop();
        this.creationRect.moveToTop();

        this.selectStorage(index);
        this.renderStorageList();
    }

    addStorageViaButton() {
        const scale = this.stage.scaleX();
        const cx = (-this.stage.x() / scale) + (this.stage.width() / scale / 2) - 50;
        const cy = (-this.stage.y() / scale) + (this.stage.height() / scale / 2) - 50;
        this.createNewStorage(cx, cy, 100, 100);
    }

    // --- Rendering ---

    renderStorages() {
        // Remove all groups (keep transformer and creationRect)
        // Collect first to avoid mutating during iteration
        const toDestroy = this.storageLayer.getChildren().filter(
            child => child !== this.transformer && child !== this.creationRect
        );
        toDestroy.forEach(child => child.destroy());

        this.storages.forEach((storage, index) => {
            const group = this.createStorageGroup(storage, index);
            this.storageLayer.add(group);
        });

        // Keep transformer and creation rect on top
        this.transformer.moveToTop();
        this.creationRect.moveToTop();
        this.storageLayer.draw();
        this.renderMinimap();
    }

    createStorageGroup(storage, index) {
        const coords = storage.coordinates;
        const storageType = this.storageTypesValue.find(t => t.id === storage.storageTypeId);
        const color = this.getStorageColor(storage, storageType);

        const group = new Konva.Group({
            x: coords.x + coords.width / 2,
            y: coords.y + coords.height / 2,
            rotation: coords.rotation || 0,
            draggable: true,
            name: `storage-${index}`,
        });

        group.storageIndex = index;

        const rect = new Konva.Rect({
            x: -coords.width / 2,
            y: -coords.height / 2,
            width: coords.width,
            height: coords.height,
            fill: color + '80',
            stroke: color,
            strokeWidth: 2,
            cornerRadius: 2,
            name: 'storageRect',
        });

        const text = new Konva.Text({
            x: -coords.width / 2,
            y: -coords.height / 2,
            width: coords.width,
            height: coords.height,
            text: storage.number || '?',
            fontSize: 14,
            fontStyle: 'bold',
            fontFamily: 'sans-serif',
            fill: '#1f2937',
            align: 'center',
            verticalAlign: 'middle',
            name: 'storageText',
            listening: false,
        });

        group.add(rect);
        group.add(text);

        // Click to select
        group.on('click tap', () => {
            this.selectStorage(index);
        });

        // Drag events
        group.on('dragmove', () => {
            if (this.snapping) {
                const gs = this.GRID_SIZE;
                group.x(Math.round(group.x() / gs) * gs);
                group.y(Math.round(group.y() / gs) * gs);
            }
            this.syncGroupToDomain(group, index);
            this.updateCoordinateInputs();
        });

        group.on('dragend', () => {
            this.syncGroupToDomain(group, index);
            this.storages[index].modified = true;
        });

        // Transform events (resize/rotate)
        group.on('transformend', () => {
            this.onTransformEnd(group, index);
        });

        group.on('transform', () => {
            this.updateCoordinateInputs();
        });

        // Cursor
        group.on('mouseenter', () => {
            this.stage.container().style.cursor = 'move';
        });
        group.on('mouseleave', () => {
            if (!this.isCreating) {
                this.stage.container().style.cursor = 'crosshair';
            }
        });

        return group;
    }

    onTransformEnd(group, index) {
        const rect = group.findOne('.storageRect');
        const text = group.findOne('.storageText');

        // Convert scale to actual dimensions
        const newWidth = Math.round(rect.width() * group.scaleX());
        const newHeight = Math.round(rect.height() * group.scaleY());

        group.scaleX(1);
        group.scaleY(1);

        rect.width(newWidth);
        rect.height(newHeight);
        rect.x(-newWidth / 2);
        rect.y(-newHeight / 2);

        text.width(newWidth);
        text.height(newHeight);
        text.x(-newWidth / 2);
        text.y(-newHeight / 2);

        this.syncGroupToDomain(group, index);
        this.storages[index].modified = true;
        this.updateCoordinateInputs();
    }

    syncGroupToDomain(group, index) {
        const rect = group.findOne('.storageRect');
        const w = rect.width() * group.scaleX();
        const h = rect.height() * group.scaleY();

        this.storages[index].coordinates = {
            x: Math.round(group.x() - w / 2),
            y: Math.round(group.y() - h / 2),
            width: Math.round(w),
            height: Math.round(h),
            rotation: Math.round(group.rotation() % 360),
        };
    }

    // --- Selection ---

    selectStorage(index) {
        this.selectedIndex = index;
        const storage = this.getSelectedStorage();

        // Store original values for cancel/restore
        if (storage) {
            this.originalNumber = storage.number;
            this.originalStorageTypeId = storage.storageTypeId;
        }

        const group = this.getGroupByIndex(index);

        if (group) {
            this.transformer.nodes([group]);

            if (this.snapping) {
                this.applySnapToTransformer();
            }

            this.storageLayer.draw();
        }

        this.showForm();
        this.updateCoordinateInputs();
        this.renderStorageList();
    }

    deselectStorage() {
        this.selectedIndex = -1;
        this.transformer.nodes([]);
        this.storageLayer.draw();
        this.hideForm();
        this.renderStorageList();
    }

    getGroupByIndex(index) {
        return this.storageLayer.findOne(`.storage-${index}`);
    }

    getSelectedStorage() {
        if (this.selectedIndex < 0 || this.selectedIndex >= this.storages.length) return null;
        return this.storages[this.selectedIndex];
    }

    // --- Form ---

    showForm() {
        const storage = this.getSelectedStorage();
        if (!this.hasFormTarget || !storage) return;

        this.formTarget.classList.remove('hidden');
        this.numberInputTarget.value = storage.number || '';
        this.typeSelectTarget.value = storage.storageTypeId || '';

        if (storage.isNew) {
            this.deleteBtnTarget.classList.add('hidden');
        } else {
            this.deleteBtnTarget.classList.remove('hidden');
            const isOccupied = storage.status === 'occupied';
            const isReserved = storage.status === 'reserved';

            if (isOccupied || isReserved) {
                this.deleteBtnTarget.disabled = true;
                this.deleteBtnTarget.classList.add('btn-disabled');
                this.deleteBtnTarget.title = isOccupied
                    ? 'Nelze smazat obsazený sklad'
                    : 'Nelze smazat sklad s aktivní rezervací';
            } else {
                this.deleteBtnTarget.disabled = false;
                this.deleteBtnTarget.classList.remove('btn-disabled');
                this.deleteBtnTarget.title = '';
            }
        }
    }

    hideForm() {
        if (this.hasFormTarget) {
            this.formTarget.classList.add('hidden');
        }
    }

    // --- Real-time text preview ---

    onNumberInputChange() {
        const storage = this.getSelectedStorage();
        if (!storage) return;

        const value = this.numberInputTarget.value;
        storage.number = value;

        const group = this.getGroupByIndex(this.selectedIndex);
        if (group) {
            const text = group.findOne('.storageText');
            text.text(value || '?');
            this.storageLayer.draw();
        }
    }

    // --- Coordinate inputs ---

    updateCoordinateInputs() {
        const storage = this.getSelectedStorage();
        if (!storage) return;
        const c = storage.coordinates;

        if (this.hasCoordXTarget) this.coordXTarget.value = Math.round(c.x);
        if (this.hasCoordYTarget) this.coordYTarget.value = Math.round(c.y);
        if (this.hasCoordWTarget) this.coordWTarget.value = Math.round(c.width);
        if (this.hasCoordHTarget) this.coordHTarget.value = Math.round(c.height);
        if (this.hasCoordRTarget) this.coordRTarget.value = Math.round(c.rotation || 0);
    }

    onCoordinateInputChange() {
        const storage = this.getSelectedStorage();
        if (!storage) return;

        const x = parseInt(this.coordXTarget.value, 10) || 0;
        const y = parseInt(this.coordYTarget.value, 10) || 0;
        const w = Math.max(1, parseInt(this.coordWTarget.value, 10) || 100);
        const h = Math.max(1, parseInt(this.coordHTarget.value, 10) || 100);
        const r = parseInt(this.coordRTarget.value, 10) || 0;

        storage.coordinates = { x, y, width: w, height: h, rotation: r };
        storage.modified = true;

        // Update Konva group
        const group = this.getGroupByIndex(this.selectedIndex);
        if (group) {
            const rect = group.findOne('.storageRect');
            const text = group.findOne('.storageText');

            group.x(x + w / 2);
            group.y(y + h / 2);
            group.rotation(r);
            group.scaleX(1);
            group.scaleY(1);

            rect.x(-w / 2);
            rect.y(-h / 2);
            rect.width(w);
            rect.height(h);

            text.x(-w / 2);
            text.y(-h / 2);
            text.width(w);
            text.height(h);

            this.storageLayer.draw();
        }
    }

    // --- Snapping ---

    onSnapToggle() {
        this.snapping = this.hasSnapCheckboxTarget && this.snapCheckboxTarget.checked;

        if (this.snapping) {
            this.applySnapToTransformer();
        } else {
            this.transformer.anchorDragBoundFunc(null);
        }
    }

    applySnapToTransformer() {
        const gs = this.GRID_SIZE;
        this.transformer.anchorDragBoundFunc((oldPos, newPos) => {
            return {
                x: Math.round(newPos.x / gs) * gs,
                y: Math.round(newPos.y / gs) * gs,
            };
        });
    }

    // --- Zoom & Pan ---

    getScaledPointerPosition() {
        const pointer = this.stage.getPointerPosition();
        const scale = this.stage.scaleX();
        return {
            x: (pointer.x - this.stage.x()) / scale,
            y: (pointer.y - this.stage.y()) / scale,
        };
    }

    zoomIn() {
        this.applyZoom(this.stage.scaleX() * 1.2);
    }

    zoomOut() {
        this.applyZoom(this.stage.scaleX() / 1.2);
    }

    zoomReset() {
        this.stage.scale({ x: 1, y: 1 });
        this.stage.position({ x: 0, y: 0 });
        this.zoomLevel = 1;
        this.updateZoomLabel();
        this.updateMinimap();
    }

    applyZoom(newScale) {
        newScale = Math.max(0.1, Math.min(10, newScale));

        const centerX = this.stage.width() / 2;
        const centerY = this.stage.height() / 2;
        const oldScale = this.stage.scaleX();

        const mousePointTo = {
            x: (centerX - this.stage.x()) / oldScale,
            y: (centerY - this.stage.y()) / oldScale,
        };

        this.stage.scale({ x: newScale, y: newScale });

        const newPos = {
            x: centerX - mousePointTo.x * newScale,
            y: centerY - mousePointTo.y * newScale,
        };
        this.stage.position(newPos);

        this.zoomLevel = newScale;
        this.updateZoomLabel();
        this.updateMinimap();
    }

    updateZoomLabel() {
        if (this.hasZoomLabelTarget) {
            this.zoomLabelTarget.textContent = Math.round(this.zoomLevel * 100) + '%';
        }
    }

    onPanMove(e) {
        if (!this.isPanning) return;
        const dx = e.clientX - this.panLastPos.x;
        const dy = e.clientY - this.panLastPos.y;
        this.stage.x(this.stage.x() + dx);
        this.stage.y(this.stage.y() + dy);
        this.panLastPos = { x: e.clientX, y: e.clientY };
        this.updateMinimap();
    }

    onPanUp(e) {
        this.isPanning = false;
        this.stage.container().style.cursor = (this.spacePressed || this.modifierPanActive) ? 'grab' : 'crosshair';
        window.removeEventListener('mousemove', this.boundPanMove);
        window.removeEventListener('mouseup', this.boundPanUp);
        this.boundPanMove = null;
        this.boundPanUp = null;
    }

    onKeyUp(e) {
        if (e.key === ' ') {
            this.spacePressed = false;
            if (!this.isPanning && !this.modifierPanActive) {
                this.stage.container().style.cursor = 'crosshair';
            }
        }
        if (e.key === 'Control' || e.key === 'Meta') {
            this.modifierPanActive = false;
            if (!this.isPanning && !this.spacePressed) {
                this.stage.container().style.cursor = 'crosshair';
            }
        }
    }

    // --- Keyboard ---

    onKeyDown(e) {
        // Ignore if typing in form elements
        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return;

        // Space key for pan mode
        if (e.key === ' ' && !this.spacePressed) {
            e.preventDefault();
            this.spacePressed = true;
            this.stage.container().style.cursor = 'grab';
            return;
        }

        // Ctrl/Cmd key for pan mode
        if ((e.key === 'Control' || e.key === 'Meta') && !this.modifierPanActive) {
            this.modifierPanActive = true;
            if (!this.isPanning) {
                this.stage.container().style.cursor = 'grab';
            }
            return;
        }

        const storage = this.getSelectedStorage();

        // Delete / Backspace
        if ((e.key === 'Delete' || e.key === 'Backspace') && storage) {
            if (storage.status === 'occupied' || storage.status === 'reserved') return;
            e.preventDefault();
            this.deleteStorage();
            return;
        }

        // Arrow keys
        if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key) && storage) {
            e.preventDefault();
            const step = this.snapping ? this.GRID_SIZE : 1;
            const coords = storage.coordinates;

            switch (e.key) {
                case 'ArrowUp': coords.y -= step; break;
                case 'ArrowDown': coords.y += step; break;
                case 'ArrowLeft': coords.x -= step; break;
                case 'ArrowRight': coords.x += step; break;
            }

            storage.modified = true;

            const group = this.getGroupByIndex(this.selectedIndex);
            if (group) {
                group.x(coords.x + coords.width / 2);
                group.y(coords.y + coords.height / 2);
                this.storageLayer.draw();
            }
            this.updateCoordinateInputs();
            return;
        }

        // Copy: Ctrl+C or Ctrl+D
        if ((e.key === 'c' || e.key === 'd') && (e.ctrlKey || e.metaKey) && storage) {
            // Only intercept Ctrl+D (Ctrl+C is browser copy)
            if (e.key === 'd') {
                e.preventDefault();
                this.copySelectedStorage();
            }
        }
    }

    // --- Copy ---

    copySelectedStorage() {
        const storage = this.getSelectedStorage();
        if (!storage) return;

        const copy = {
            id: null,
            number: this.getNextStorageNumber(),
            storageTypeId: storage.storageTypeId,
            coordinates: {
                x: storage.coordinates.x + 20,
                y: storage.coordinates.y + 20,
                width: storage.coordinates.width,
                height: storage.coordinates.height,
                rotation: storage.coordinates.rotation,
            },
            isNew: true,
            modified: true,
        };

        this.storages.push(copy);
        const index = this.storages.length - 1;

        const group = this.createStorageGroup(copy, index);
        this.storageLayer.add(group);
        this.transformer.moveToTop();
        this.creationRect.moveToTop();

        this.selectStorage(index);
        this.renderStorageList();
        this.showNotification('Sklad zkopírován');
    }

    // --- Save / Delete / Cancel ---

    async saveAllStorages() {
        // Include storages that need coordinate normalization (legacy data)
        const needsNormalization = this.mapImg
            ? this.storages.filter(s => s.id && !s.isNew && !s.modified && !s._normalized)
            : [];
        const modified = this.storages.filter(s => s.modified || s.isNew);
        const toSave = [...modified, ...needsNormalization];

        if (toSave.length === 0) {
            this.showNotification('Žádné změny k uložení');
            return;
        }

        let saved = 0;
        let errors = 0;

        for (const storage of toSave) {
            const data = {
                number: storage.number,
                storageTypeId: storage.storageTypeId,
                coordinates: this.normalizeCoords(storage.coordinates),
            };

            try {
                const url = storage.id
                    ? `${this.apiUrlValue}/${storage.id}`
                    : this.apiUrlValue;
                const method = storage.id ? 'PUT' : 'POST';

                const response = await fetch(url, {
                    method: method,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data),
                });

                if (!response.ok) {
                    errors++;
                    continue;
                }

                const result = await response.json();
                storage.id = result.id;
                storage.isNew = false;
                storage.modified = false;
                storage._normalized = true;
                saved++;
            } catch {
                errors++;
            }
        }

        this.renderStorages();
        if (this.selectedIndex >= 0) {
            this.selectStorage(this.selectedIndex);
        }
        this.renderStorageList();

        if (errors > 0) {
            this.showNotification(`Uloženo ${saved}, chyby: ${errors}`);
        } else {
            this.showNotification(`Uloženo ${saved} skladů`);
        }
    }

    async saveStorage() {
        const storage = this.getSelectedStorage();
        if (!storage) return;

        storage.number = this.numberInputTarget.value;
        storage.storageTypeId = this.typeSelectTarget.value;

        const data = {
            number: storage.number,
            storageTypeId: storage.storageTypeId,
            coordinates: this.normalizeCoords(storage.coordinates),
        };

        try {
            const url = storage.id
                ? `${this.apiUrlValue}/${storage.id}`
                : this.apiUrlValue;
            const method = storage.id ? 'PUT' : 'POST';

            const response = await fetch(url, {
                method: method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });

            if (!response.ok) {
                const error = await response.json();
                alert(error.message || 'Chyba při ukládání skladu');
                return;
            }

            const result = await response.json();
            storage.id = result.id;
            storage.isNew = false;
            storage.modified = false;

            // Update the visual (color might change if type changed)
            this.renderStorages();
            if (this.selectedIndex >= 0) {
                this.selectStorage(this.selectedIndex);
            }
            this.renderStorageList();
            this.showNotification('Sklad uložen');
        } catch (err) {
            console.error('Save error:', err);
            alert('Chyba při ukládání skladu');
        }
    }

    async deleteStorage() {
        const storage = this.getSelectedStorage();
        if (!storage) return;

        if (!storage.id) {
            // Remove unsaved storage
            this.storages.splice(this.selectedIndex, 1);
            this.deselectStorage();
            this.renderStorages();
            this.renderStorageList();
            return;
        }

        if (!confirm('Opravdu chcete smazat tento sklad?')) return;

        try {
            const response = await fetch(`${this.apiUrlValue}/${storage.id}`, {
                method: 'DELETE',
            });

            if (!response.ok) {
                const error = await response.json();
                alert(error.message || 'Chyba při mazání skladu');
                return;
            }

            this.storages.splice(this.selectedIndex, 1);
            this.deselectStorage();
            this.renderStorages();
            this.renderStorageList();
            this.showNotification('Sklad smazán');
        } catch (err) {
            console.error('Delete error:', err);
            alert('Chyba při mazání skladu');
        }
    }

    cancelEdit() {
        const storage = this.getSelectedStorage();
        if (storage && storage.isNew) {
            this.storages.splice(this.selectedIndex, 1);
            this.renderStorages();
            this.renderStorageList();
        } else if (storage) {
            // Restore original values
            storage.number = this.originalNumber;
            storage.storageTypeId = this.originalStorageTypeId;

            const group = this.getGroupByIndex(this.selectedIndex);
            if (group) {
                const text = group.findOne('.storageText');
                text.text(storage.number || '?');
                this.storageLayer.draw();
            }
            this.renderStorageList();
        }
        this.deselectStorage();
    }

    // --- Minimap ---

    initializeMinimap() {
        if (!this.hasMinimapTarget) return;

        const minimapW = 180;
        const minimapH = 120;

        this.minimapStage = new Konva.Stage({
            container: this.minimapTarget,
            width: minimapW,
            height: minimapH,
        });

        this.minimapBgLayer = new Konva.Layer({ listening: false });
        this.minimapStorageLayer = new Konva.Layer({ listening: false });
        this.minimapViewportLayer = new Konva.Layer();

        this.minimapStage.add(this.minimapBgLayer);
        this.minimapStage.add(this.minimapStorageLayer);
        this.minimapStage.add(this.minimapViewportLayer);

        // Background fill
        this.minimapBgLayer.add(new Konva.Rect({
            x: 0, y: 0,
            width: minimapW, height: minimapH,
            fill: '#f3f4f6',
        }));

        // Viewport rectangle (draggable)
        this.minimapViewportRect = new Konva.Rect({
            x: 0, y: 0,
            width: minimapW, height: minimapH,
            fill: 'rgba(59, 130, 246, 0.15)',
            stroke: '#3b82f6',
            strokeWidth: 1.5,
            draggable: true,
            name: 'viewportRect',
        });
        this.minimapViewportLayer.add(this.minimapViewportRect);

        // Drag viewport to pan main stage
        this.minimapViewportRect.on('dragmove', () => this.onMinimapViewportDrag());

        // Click on minimap background to navigate
        this.minimapStage.on('click', (e) => {
            if (e.target === this.minimapViewportRect) return;
            this.onMinimapClick(e);
        });

        this.minimapBgLayer.draw();
    }

    getMinimapScale() {
        const stageW = this.stage.width();
        const stageH = this.stage.height();
        return Math.min(180 / stageW, 120 / stageH);
    }

    renderMinimap() {
        if (!this.minimapStage) return;

        const mmScale = this.getMinimapScale();

        // Redraw background: map image or grid representation
        this.minimapBgLayer.destroyChildren();
        this.minimapBgLayer.add(new Konva.Rect({
            x: 0, y: 0,
            width: 180, height: 120,
            fill: '#f3f4f6',
        }));

        if (this.mapImg) {
            this.minimapBgLayer.add(new Konva.Image({
                x: this.imgOffsetX * mmScale,
                y: this.imgOffsetY * mmScale,
                image: this.mapImg,
                width: this.mapImg.width * this.imgScale * mmScale,
                height: this.mapImg.height * this.imgScale * mmScale,
                opacity: 0.6,
            }));
        }
        this.minimapBgLayer.draw();

        // Redraw storage rects
        this.minimapStorageLayer.destroyChildren();
        this.storages.forEach((storage) => {
            const c = storage.coordinates;
            const storageType = this.storageTypesValue.find(t => t.id === storage.storageTypeId);
            const color = this.getStorageColor(storage, storageType);

            this.minimapStorageLayer.add(new Konva.Rect({
                x: (c.x + c.width / 2) * mmScale,
                y: (c.y + c.height / 2) * mmScale,
                offsetX: Math.max(3, c.width * mmScale) / 2,
                offsetY: Math.max(3, c.height * mmScale) / 2,
                width: Math.max(3, c.width * mmScale),
                height: Math.max(3, c.height * mmScale),
                rotation: c.rotation || 0,
                fill: color,
                opacity: 0.8,
            }));
        });
        this.minimapStorageLayer.draw();

        this.updateMinimapViewport();
    }

    updateMinimapViewport() {
        if (!this.minimapStage || !this.minimapViewportRect) return;

        const mmScale = this.getMinimapScale();
        const scale = this.stage.scaleX();

        // Visible area in stage coordinates
        const viewX = -this.stage.x() / scale;
        const viewY = -this.stage.y() / scale;
        const viewW = this.stage.width() / scale;
        const viewH = this.stage.height() / scale;

        this.minimapViewportRect.setAttrs({
            x: viewX * mmScale,
            y: viewY * mmScale,
            width: viewW * mmScale,
            height: viewH * mmScale,
        });
        this.minimapViewportLayer.draw();
    }

    updateMinimap() {
        if (!this.minimapStage) return;

        const scale = this.stage.scaleX();
        if (scale > 1.05) {
            this.minimapTarget.style.display = 'block';
            this.updateMinimapViewport();
        } else {
            this.minimapTarget.style.display = 'none';
        }
    }

    onMinimapViewportDrag() {
        const mmScale = this.getMinimapScale();
        const scale = this.stage.scaleX();

        const viewX = this.minimapViewportRect.x() / mmScale;
        const viewY = this.minimapViewportRect.y() / mmScale;

        this.stage.x(-viewX * scale);
        this.stage.y(-viewY * scale);
    }

    onMinimapClick(e) {
        const mmScale = this.getMinimapScale();
        const scale = this.stage.scaleX();

        const pos = this.minimapStage.getPointerPosition();
        const stageX = pos.x / mmScale;
        const stageY = pos.y / mmScale;

        // Center the main stage view on this point
        const viewW = this.stage.width() / scale;
        const viewH = this.stage.height() / scale;

        this.stage.x(-(stageX - viewW / 2) * scale);
        this.stage.y(-(stageY - viewH / 2) * scale);

        this.updateMinimapViewport();
    }

    // --- Coordinate normalization (image-relative ↔ stage-space) ---

    denormalizeAllStorages() {
        if (!this.mapImg) return;
        this.storages.forEach(storage => {
            if (storage.coordinates.normalized) {
                storage.coordinates = this.denormalizeCoords(storage.coordinates);
                storage._normalized = true;
            }
        });
    }

    denormalizeCoords(coords) {
        return {
            x: Math.round(coords.x * this.imgScale + this.imgOffsetX),
            y: Math.round(coords.y * this.imgScale + this.imgOffsetY),
            width: Math.round(coords.width * this.imgScale),
            height: Math.round(coords.height * this.imgScale),
            rotation: coords.rotation || 0,
        };
    }

    normalizeCoords(coords) {
        if (!this.mapImg) return coords;
        return {
            x: (coords.x - this.imgOffsetX) / this.imgScale,
            y: (coords.y - this.imgOffsetY) / this.imgScale,
            width: coords.width / this.imgScale,
            height: coords.height / this.imgScale,
            rotation: coords.rotation || 0,
            normalized: true,
        };
    }

    // --- Helpers ---

    getNextStorageNumber() {
        const numbers = this.storages
            .map(s => s.number)
            .filter(n => n && /^[A-Z]\d+$/.test(n));

        if (numbers.length === 0) return 'A1';

        const lastNum = numbers.sort().pop();
        const letter = lastNum.charAt(0);
        const num = parseInt(lastNum.slice(1)) + 1;

        if (num > 99) {
            const nextLetter = String.fromCharCode(letter.charCodeAt(0) + 1);
            return nextLetter + '1';
        }
        return letter + num;
    }

    getStorageColor(storage, storageType) {
        if (storage.status === 'occupied') return '#ef4444';
        if (storage.status === 'reserved') return '#f59e0b';
        if (storage.status === 'manually_unavailable') return '#6b7280';
        return '#22c55e';
    }

    renderStorageList() {
        if (!this.hasStorageListTarget) return;

        let html = '<ul class="divide-y divide-gray-200">';
        this.storages.forEach((storage, index) => {
            const storageType = this.storageTypesValue.find(t => t.id === storage.storageTypeId);
            const typeName = storageType ? storageType.name : 'Nepřiřazen';
            const isSelected = index === this.selectedIndex;

            html += `
                <li class="p-2 cursor-pointer hover:bg-gray-50 ${isSelected ? 'bg-blue-50' : ''}"
                    data-action="click->storage-canvas#onStorageListClick"
                    data-storage-index="${index}">
                    <div class="flex items-center justify-between">
                        <span class="font-medium">${storage.number || '?'}</span>
                        <span class="text-sm text-gray-500">${typeName}</span>
                    </div>
                </li>
            `;
        });
        html += '</ul>';

        if (this.storages.length === 0) {
            html = '<p class="text-sm text-gray-500 p-4">Žádné sklady. Klikněte a táhněte na plátně pro vytvoření.</p>';
        }

        this.storageListTarget.innerHTML = html;
    }

    onStorageListClick(e) {
        const li = e.target.closest('li');
        if (!li) return;

        const index = parseInt(li.dataset.storageIndex, 10);
        if (index >= 0 && index < this.storages.length) {
            this.selectStorage(index);
        }
    }

    showNotification(message) {
        const notification = document.createElement('div');
        notification.className = 'fixed bottom-4 right-4 bg-green-500 text-white px-4 py-2 rounded shadow-lg z-50';
        notification.textContent = message;
        document.body.appendChild(notification);
        setTimeout(() => notification.remove(), 3000);
    }
}
