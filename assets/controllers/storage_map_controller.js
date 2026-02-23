import { Controller } from '@hotwired/stimulus';
import Konva from 'konva';

export default class extends Controller {
    static targets = ['container', 'tooltip', 'modal', 'modalTitle', 'modalPhotos', 'modalDetails', 'modalOrderBtn'];
    static values = {
        mapImage: String,
        storages: Array,
        storageTypes: Array,
        placeId: String
    }

    connect() {
        this.hoveredStorage = null;
        this.initializeStage();
        this.loadMapImage();
    }

    disconnect() {
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
    }

    loadMapImage() {
        if (this.mapImageValue) {
            const img = new Image();
            img.onload = () => {
                this.mapImg = img;
                this.fitImageToStage();
                this.renderStorages();
            };
            img.onerror = () => {
                this.drawGrid();
                this.renderStorages();
            };
            img.src = this.mapImageValue;
        } else {
            this.drawGrid();
            this.renderStorages();
        }
    }

    fitImageToStage() {
        const stageW = this.stage.width();
        const stageH = this.stage.height();
        const imgW = this.mapImg.width;
        const imgH = this.mapImg.height;

        const scale = Math.min(stageW / imgW, stageH / imgH);
        const offsetX = (stageW - imgW * scale) / 2;
        const offsetY = (stageH - imgH * scale) / 2;

        const konvaImg = new Konva.Image({
            x: offsetX,
            y: offsetY,
            image: this.mapImg,
            width: imgW * scale,
            height: imgH * scale,
        });

        this.bgLayer.add(konvaImg);
        this.bgLayer.draw();
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

    renderStorages() {
        this.storageLayer.destroyChildren();

        this.storagesValue.forEach(storage => {
            const group = this.createStorageGroup(storage);
            this.storageLayer.add(group);
        });

        this.storageLayer.draw();
    }

    createStorageGroup(storage) {
        const coords = storage.coordinates;
        const color = this.getStorageColor(storage);

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
            fill: color + '99',
            stroke: color,
            strokeWidth: 2,
            cornerRadius: 2,
        });

        const text = new Konva.Text({
            x: -coords.width / 2,
            y: -coords.height / 2,
            width: coords.width,
            height: coords.height,
            text: storage.number,
            fontSize: 14,
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
            this.hoveredStorage = storage;
            this.stage.container().style.cursor = 'pointer';

            rect.fill(color + 'cc');
            rect.stroke('#1f2937');
            rect.strokeWidth(3);
            rect.shadowColor('rgba(0, 0, 0, 0.3)');
            rect.shadowBlur(10);
            rect.shadowOffsetX(2);
            rect.shadowOffsetY(2);
            rect.shadowEnabled(true);
            text.fontSize(16);
            this.storageLayer.draw();

            this.updateTooltip(storage);
        });

        group.on('mouseleave', () => {
            this.hoveredStorage = null;
            this.stage.container().style.cursor = 'default';

            rect.fill(color + '99');
            rect.stroke(color);
            rect.strokeWidth(2);
            rect.shadowEnabled(false);
            text.fontSize(14);
            this.storageLayer.draw();

            this.hideTooltip();
        });

        group.on('click tap', () => {
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

        // Position tooltip using pointer position
        const pointerPos = this.stage.getPointerPosition();
        if (!pointerPos) return;

        const containerRect = this.element.getBoundingClientRect();
        const stageRect = this.containerTarget.getBoundingClientRect();
        const stageOffsetTop = stageRect.top - containerRect.top;

        this.tooltipTarget.classList.remove('hidden');
        const tooltipRect = this.tooltipTarget.getBoundingClientRect();

        const coords = storage.coordinates;
        let left = coords.x + coords.width + 10;
        let top = stageOffsetTop + coords.y;

        // Overflow right → position left
        if (left + tooltipRect.width > containerRect.width - 10) {
            left = coords.x - tooltipRect.width - 10;
        }

        // Overflow left → center
        if (left < 10) {
            left = coords.x + (coords.width - tooltipRect.width) / 2;
            left = Math.max(10, Math.min(left, containerRect.width - tooltipRect.width - 10));
        }

        // Overflow bottom
        if (top + tooltipRect.height > stageRect.bottom - containerRect.top - 10) {
            top = stageOffsetTop + coords.y + coords.height - tooltipRect.height;
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
