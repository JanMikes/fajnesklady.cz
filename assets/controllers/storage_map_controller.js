import { Controller } from '@hotwired/stimulus';
import Konva from 'konva';

export default class extends Controller {
    static targets = [
        'container', 'tooltip', 'minimap',
        'modal', 'modalTitle', 'modalPhotos', 'modalDetails', 'modalOrderBtn',
        'zoomLabel',
    ];
    static values = {
        mapImage: String,
        storages: Array,
        storageTypes: Array,
        placeId: String,
        highlightStorage: String,
    }

    connect() {
        this.hoveredStorage = null;
        this.isPanning = false;
        this.panLastPos = null;
        this.boundPanMove = null;
        this.boundPanUp = null;
        this.zoomLevel = 1;

        this.minimapStage = null;
        this.minimapBgLayer = null;
        this.minimapStorageLayer = null;
        this.minimapViewportLayer = null;
        this.minimapViewportRect = null;

        this.initializeStage();
        this.initializeMinimap();
        this.loadMapImage();
    }

    disconnect() {
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
        const height = 400;

        this.stage = new Konva.Stage({
            container: this.containerTarget,
            width: width,
            height: height,
        });

        this.bgLayer = new Konva.Layer({ listening: false });
        this.storageLayer = new Konva.Layer();

        this.stage.add(this.bgLayer);
        this.stage.add(this.storageLayer);

        // Draw default background
        this.bgRect = new Konva.Rect({
            x: 0, y: 0,
            width: width, height: height,
            fill: '#f3f4f6',
        });
        this.bgLayer.add(this.bgRect);
        this.bgLayer.draw();

        // Pan via middle-click or Ctrl/Cmd+drag
        this.stage.on('mousedown', (e) => {
            if (e.evt.button === 1 || (e.evt.button === 0 && (e.evt.ctrlKey || e.evt.metaKey))) {
                this.isPanning = true;
                this.panLastPos = { x: e.evt.clientX, y: e.evt.clientY };
                this.stage.container().style.cursor = 'grabbing';
                this.boundPanMove = this.onPanMove.bind(this);
                this.boundPanUp = this.onPanUp.bind(this);
                window.addEventListener('mousemove', this.boundPanMove);
                window.addEventListener('mouseup', this.boundPanUp);
                e.evt.preventDefault();
            }
        });
    }

    // --- Zoom & Pan ---

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
        newScale = Math.max(0.5, Math.min(5, newScale));

        const centerX = this.stage.width() / 2;
        const centerY = this.stage.height() / 2;
        const oldScale = this.stage.scaleX();

        const mousePointTo = {
            x: (centerX - this.stage.x()) / oldScale,
            y: (centerY - this.stage.y()) / oldScale,
        };

        this.stage.scale({ x: newScale, y: newScale });
        this.stage.position({
            x: centerX - mousePointTo.x * newScale,
            y: centerY - mousePointTo.y * newScale,
        });

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

    onPanUp() {
        this.isPanning = false;
        this.stage.container().style.cursor = 'default';
        window.removeEventListener('mousemove', this.boundPanMove);
        window.removeEventListener('mouseup', this.boundPanUp);
        this.boundPanMove = null;
        this.boundPanUp = null;
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

        this.minimapBgLayer.add(new Konva.Rect({
            x: 0, y: 0,
            width: minimapW, height: minimapH,
            fill: '#f3f4f6',
        }));

        this.minimapViewportRect = new Konva.Rect({
            x: 0, y: 0,
            width: minimapW, height: minimapH,
            fill: 'rgba(59, 130, 246, 0.15)',
            stroke: '#3b82f6',
            strokeWidth: 1.5,
            draggable: true,
        });
        this.minimapViewportLayer.add(this.minimapViewportRect);

        this.minimapViewportRect.on('dragmove', () => this.onMinimapViewportDrag());

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

        // Storage rects
        this.minimapStorageLayer.destroyChildren();
        this.storagesValue.forEach(storage => {
            const c = this.denormalizeCoords(storage.coordinates);
            const color = this.getStorageColor(storage);

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

        const viewW = this.stage.width() / scale;
        const viewH = this.stage.height() / scale;

        this.stage.x(-(stageX - viewW / 2) * scale);
        this.stage.y(-(stageY - viewH / 2) * scale);

        this.updateMinimapViewport();
    }

    // --- Map Image ---

    loadMapImage() {
        if (this.mapImageValue) {
            const img = new Image();
            img.onload = () => {
                this.mapImg = img;
                this.fitImageToStage();
                this.renderStorages();
                this.renderMinimap();
            };
            img.onerror = () => {
                this.drawGrid();
                this.renderStorages();
                this.renderMinimap();
            };
            img.src = this.mapImageValue;
        } else {
            this.drawGrid();
            this.renderStorages();
            this.renderMinimap();
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
    }

    denormalizeCoords(coords) {
        if (!coords.normalized || !this.mapImg) return coords;
        return {
            x: coords.x * this.imgScale + this.imgOffsetX,
            y: coords.y * this.imgScale + this.imgOffsetY,
            width: coords.width * this.imgScale,
            height: coords.height * this.imgScale,
            rotation: coords.rotation || 0,
        };
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
    }

    // --- Storages ---

    renderStorages() {
        this.storageLayer.destroyChildren();

        this.storagesValue.forEach(storage => {
            const displayStorage = {
                ...storage,
                _displayCoords: this.denormalizeCoords(storage.coordinates),
            };
            const group = this.createStorageGroup(displayStorage);
            this.storageLayer.add(group);
        });

        this.storageLayer.draw();
    }

    createStorageGroup(storage) {
        const coords = storage._displayCoords || storage.coordinates;
        const color = this.getStorageColor(storage);
        const isHighlighted = this.highlightStorageValue && storage.id === this.highlightStorageValue;

        // Scale font size with storage dimensions (matching editor approach)
        const baseFontSize = Math.min(14, Math.max(8, Math.min(coords.width, coords.height) * 0.3));

        const group = new Konva.Group({
            x: coords.x + coords.width / 2,
            y: coords.y + coords.height / 2,
            rotation: coords.rotation || 0,
            offsetX: 0,
            offsetY: 0,
        });

        // Store storage data on the group for event handlers
        group.storageData = storage;

        const rect = new Konva.Rect({
            x: -coords.width / 2,
            y: -coords.height / 2,
            width: coords.width,
            height: coords.height,
            fill: isHighlighted ? color + 'aa' : color + '80',
            stroke: isHighlighted ? '#1e3a5f' : color,
            strokeWidth: isHighlighted ? 3 : 2,
            cornerRadius: 2,
        });

        const text = new Konva.Text({
            x: -coords.width / 2,
            y: -coords.height / 2,
            width: coords.width,
            height: coords.height,
            text: storage.number,
            fontSize: baseFontSize,
            fontStyle: 'bold',
            fontFamily: 'sans-serif',
            fill: '#1f2937',
            align: 'center',
            verticalAlign: 'middle',
        });

        group.add(rect);
        group.add(text);

        // Events
        group.on('mouseenter', () => {
            if (this.isPanning) return;
            this.hoveredStorage = storage;
            this.stage.container().style.cursor = 'pointer';

            rect.fill(color + 'aa');
            rect.stroke(color);
            rect.strokeWidth(2.5);
            this.storageLayer.draw();

            this.updateTooltip(storage);
        });

        group.on('mouseleave', () => {
            this.hoveredStorage = null;
            if (!this.isPanning) {
                this.stage.container().style.cursor = 'default';
            }

            rect.fill(isHighlighted ? color + 'aa' : color + '80');
            rect.stroke(isHighlighted ? '#1e3a5f' : color);
            rect.strokeWidth(isHighlighted ? 3 : 2);
            this.storageLayer.draw();

            this.hideTooltip();
        });

        group.on('click tap', () => {
            if (this.isPanning) return;
            if (storage.status === 'available') {
                if (storage.isUniform) {
                    const orderBtn = document.querySelector(`[data-storage-type-id="${storage.storageTypeId}"]`);
                    if (orderBtn) {
                        orderBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        orderBtn.classList.add('ring-2', 'ring-blue-500');
                        setTimeout(() => orderBtn.classList.remove('ring-2', 'ring-blue-500'), 2000);
                    }
                } else {
                    this.showStorageModal(storage);
                }
            }
        });

        return group;
    }

    showStorageModal(storage) {
        if (!this.hasModalTarget) return;

        this.modalTitleTarget.textContent = `Sklad ${storage.number}`;

        if (storage.photoUrls && storage.photoUrls.length > 0) {
            this.modalPhotosTarget.innerHTML = storage.photoUrls.map((url, index) =>
                `<a href="${url}" class="glightbox" data-gallery="storage-${storage.id}">
                    <img src="${url}" alt="Sklad ${storage.number}" class="${index === 0 ? 'w-full h-48' : 'w-full h-20'} object-cover rounded-lg cursor-pointer hover:opacity-80 transition-opacity">
                </a>`
            ).join('');
            this.modalPhotosTarget.className = storage.photoUrls.length > 1
                ? 'grid grid-cols-3 gap-2 mb-4'
                : 'mb-4';
            this.modalPhotosTarget.classList.remove('hidden');
        } else if (storage.photoUrl) {
            this.modalPhotosTarget.innerHTML = `<img src="${storage.photoUrl}" alt="Sklad ${storage.number}" class="w-full h-48 object-cover rounded-lg">`;
            this.modalPhotosTarget.className = 'mb-4';
            this.modalPhotosTarget.classList.remove('hidden');
        } else {
            this.modalPhotosTarget.innerHTML = '';
            this.modalPhotosTarget.classList.add('hidden');
        }

        this.modalDetailsTarget.innerHTML = `
            <div class="flex justify-between">
                <span class="text-gray-500">Typ:</span>
                <span class="font-medium">${storage.storageTypeName}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-500">Rozměry:</span>
                <span class="font-medium">${storage.dimensions}</span>
            </div>
            <div class="border-t pt-2 mt-2">
                <div class="flex justify-between">
                    <span class="text-gray-500">Cena za týden:</span>
                    <span class="font-semibold">${storage.pricePerWeek.toLocaleString('cs-CZ')} Kč</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Cena za měsíc:</span>
                    <span class="font-semibold text-primary">${storage.pricePerMonth.toLocaleString('cs-CZ')} Kč</span>
                </div>
            </div>
        `;

        const orderUrl = `/objednavka/${this.placeIdValue}/${storage.storageTypeId}/${storage.id}`;
        this.modalOrderBtnTarget.href = orderUrl;

        this.modalTarget.showModal();
    }

    updateTooltip(storage) {
        if (!this.hasTooltipTarget) return;

        const statusText = this.getStatusText(storage.status);
        const statusClass = this.getStatusClass(storage.status);

        this.tooltipTarget.innerHTML = `
            <div class="space-y-1">
                <div class="flex items-center gap-2">
                    <span class="font-bold text-gray-900">${storage.number}</span>
                    <span class="badge ${statusClass} text-xs">${statusText}</span>
                </div>
                <div class="text-gray-600">${storage.storageTypeName}</div>
                <div class="text-gray-500 text-xs">${storage.dimensions}</div>
                <div class="font-semibold text-blue-600 pt-1">${storage.pricePerMonth.toLocaleString('cs-CZ')} Kč/měsíc</div>
            </div>
        `;

        // Position tooltip near pointer, accounting for zoom/pan
        const pointerPos = this.stage.getPointerPosition();
        if (!pointerPos) return;

        const containerRect = this.element.getBoundingClientRect();
        const stageRect = this.containerTarget.getBoundingClientRect();
        const stageOffsetTop = stageRect.top - containerRect.top;

        this.tooltipTarget.classList.remove('hidden');
        const tooltipRect = this.tooltipTarget.getBoundingClientRect();

        let left = pointerPos.x + 15;
        let top = stageOffsetTop + pointerPos.y - 10;

        // Overflow right
        if (left + tooltipRect.width > containerRect.width - 10) {
            left = pointerPos.x - tooltipRect.width - 15;
        }

        // Overflow left
        left = Math.max(10, left);

        // Overflow bottom
        if (top + tooltipRect.height > stageRect.bottom - containerRect.top - 10) {
            top = stageOffsetTop + pointerPos.y - tooltipRect.height - 10;
        }

        top = Math.max(stageOffsetTop + 10, top);

        this.tooltipTarget.style.left = `${left}px`;
        this.tooltipTarget.style.top = `${top}px`;
    }

    hideTooltip() {
        if (!this.hasTooltipTarget) return;
        this.tooltipTarget.classList.add('hidden');
    }

    getStatusText(status) {
        switch (status) {
            case 'available': return 'Volný';
            case 'reserved': return 'Rezervovaný';
            case 'occupied': return 'Obsazený';
            case 'manually_unavailable': return 'Nedostupný';
            default: return status;
        }
    }

    getStatusClass(status) {
        switch (status) {
            case 'available': return 'badge-success';
            case 'reserved': return 'badge-warning';
            case 'occupied': return 'badge-error';
            case 'manually_unavailable': return 'badge-ghost';
            default: return 'badge-ghost';
        }
    }

    getStorageColor(storage) {
        switch (storage.status) {
            case 'available': return '#22c55e';
            case 'reserved': return '#f59e0b';
            case 'occupied': return '#ef4444';
            case 'manually_unavailable': return '#6b7280';
            default: return '#22c55e';
        }
    }
}
