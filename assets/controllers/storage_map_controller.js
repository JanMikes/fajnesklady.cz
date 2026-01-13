import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['canvas', 'tooltip'];
    static values = {
        mapImage: String,
        storages: Array,
        storageTypes: Array
    }

    connect() {
        this.hoveredStorage = null;
        this.scale = 1;
        this.mousePos = { x: 0, y: 0 };

        this.initializeCanvas();
        this.loadMapImage();
        this.bindEvents();
    }

    initializeCanvas() {
        this.ctx = this.canvasTarget.getContext('2d');
        this.canvasTarget.width = this.canvasTarget.parentElement.clientWidth;
        this.canvasTarget.height = 400;
    }

    loadMapImage() {
        if (this.mapImageValue) {
            this.mapImg = new Image();
            this.mapImg.onload = () => {
                this.fitImageToCanvas();
                this.render();
            };
            this.mapImg.src = this.mapImageValue;
        } else {
            this.render();
        }
    }

    fitImageToCanvas() {
        if (!this.mapImg) return;

        const canvasRatio = this.canvasTarget.width / this.canvasTarget.height;
        const imageRatio = this.mapImg.width / this.mapImg.height;

        if (imageRatio > canvasRatio) {
            this.scale = this.canvasTarget.width / this.mapImg.width;
        } else {
            this.scale = this.canvasTarget.height / this.mapImg.height;
        }

        this.imageOffset = {
            x: (this.canvasTarget.width - this.mapImg.width * this.scale) / 2,
            y: (this.canvasTarget.height - this.mapImg.height * this.scale) / 2
        };
    }

    bindEvents() {
        this.canvasTarget.addEventListener('mousemove', this.onMouseMove.bind(this));
        this.canvasTarget.addEventListener('mouseleave', this.onMouseLeave.bind(this));
        this.canvasTarget.addEventListener('click', this.onClick.bind(this));
    }

    getMousePos(e) {
        const rect = this.canvasTarget.getBoundingClientRect();
        return {
            x: e.clientX - rect.left,
            y: e.clientY - rect.top
        };
    }

    onMouseMove(e) {
        const pos = this.getMousePos(e);
        this.mousePos = pos;
        const storage = this.getStorageAtPosition(pos);

        if (storage !== this.hoveredStorage) {
            this.hoveredStorage = storage;
            this.render();
            this.updateTooltip(storage);
        }

        this.canvasTarget.style.cursor = storage ? 'pointer' : 'default';
    }

    onMouseLeave() {
        this.hoveredStorage = null;
        this.render();
        this.hideTooltip();
    }

    onClick(e) {
        const pos = this.getMousePos(e);
        const storage = this.getStorageAtPosition(pos);

        if (storage && storage.status === 'available') {
            // Could navigate to order page or show modal
            const orderBtn = document.querySelector(`[data-storage-type-id="${storage.storageTypeId}"]`);
            if (orderBtn) {
                orderBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
                orderBtn.classList.add('ring-2', 'ring-blue-500');
                setTimeout(() => orderBtn.classList.remove('ring-2', 'ring-blue-500'), 2000);
            }
        }
    }

    getStorageAtPosition(pos) {
        for (let i = this.storagesValue.length - 1; i >= 0; i--) {
            const s = this.storagesValue[i];
            const coords = s.coordinates;
            if (pos.x >= coords.x && pos.x <= coords.x + coords.width &&
                pos.y >= coords.y && pos.y <= coords.y + coords.height) {
                return s;
            }
        }
        return null;
    }

    updateTooltip(storage) {
        if (!this.hasTooltipTarget) return;

        if (storage) {
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

            // Position tooltip relative to the storage unit
            const coords = storage.coordinates;
            const containerRect = this.element.getBoundingClientRect();
            const canvasRect = this.canvasTarget.getBoundingClientRect();
            const canvasOffsetTop = canvasRect.top - containerRect.top;

            // Show tooltip first to get its dimensions
            this.tooltipTarget.classList.remove('hidden');
            const tooltipRect = this.tooltipTarget.getBoundingClientRect();

            // Default position: to the right of the storage unit
            let left = coords.x + coords.width + 10;
            let top = canvasOffsetTop + coords.y;

            // If tooltip would overflow right, position to the left of the storage
            if (left + tooltipRect.width > containerRect.width - 10) {
                left = coords.x - tooltipRect.width - 10;
            }

            // If tooltip would overflow left, center it above/below the storage
            if (left < 10) {
                left = coords.x + (coords.width - tooltipRect.width) / 2;
                left = Math.max(10, Math.min(left, containerRect.width - tooltipRect.width - 10));
            }

            // If tooltip would overflow bottom, move it up
            if (top + tooltipRect.height > canvasRect.bottom - containerRect.top - 10) {
                top = canvasOffsetTop + coords.y + coords.height - tooltipRect.height;
            }

            // Ensure top is not negative
            top = Math.max(canvasOffsetTop + 10, top);

            this.tooltipTarget.style.left = `${left}px`;
            this.tooltipTarget.style.top = `${top}px`;
        } else {
            this.hideTooltip();
        }
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

    render() {
        this.ctx.clearRect(0, 0, this.canvasTarget.width, this.canvasTarget.height);

        // Draw background
        this.ctx.fillStyle = '#f3f4f6';
        this.ctx.fillRect(0, 0, this.canvasTarget.width, this.canvasTarget.height);

        // Draw map image if loaded
        if (this.mapImg && this.mapImg.complete) {
            this.ctx.drawImage(
                this.mapImg,
                this.imageOffset.x,
                this.imageOffset.y,
                this.mapImg.width * this.scale,
                this.mapImg.height * this.scale
            );
        } else {
            this.drawGrid();
        }

        // Draw storages
        this.storagesValue.forEach(storage => {
            this.drawStorage(storage);
        });
    }

    drawGrid() {
        this.ctx.strokeStyle = '#e5e7eb';
        this.ctx.lineWidth = 1;
        const gridSize = 50;

        for (let x = 0; x < this.canvasTarget.width; x += gridSize) {
            this.ctx.beginPath();
            this.ctx.moveTo(x, 0);
            this.ctx.lineTo(x, this.canvasTarget.height);
            this.ctx.stroke();
        }

        for (let y = 0; y < this.canvasTarget.height; y += gridSize) {
            this.ctx.beginPath();
            this.ctx.moveTo(0, y);
            this.ctx.lineTo(this.canvasTarget.width, y);
            this.ctx.stroke();
        }
    }

    drawStorage(storage) {
        const coords = storage.coordinates;
        const isHovered = storage === this.hoveredStorage;
        const color = this.getStorageColor(storage);

        // Save context for rotation
        this.ctx.save();
        this.ctx.translate(coords.x + coords.width / 2, coords.y + coords.height / 2);
        this.ctx.rotate((coords.rotation || 0) * Math.PI / 180);

        // Draw rectangle with shadow if hovered
        if (isHovered) {
            this.ctx.shadowColor = 'rgba(0, 0, 0, 0.3)';
            this.ctx.shadowBlur = 10;
            this.ctx.shadowOffsetX = 2;
            this.ctx.shadowOffsetY = 2;
        }

        this.ctx.fillStyle = color + (isHovered ? 'cc' : '99'); // More opacity when hovered
        this.ctx.fillRect(-coords.width / 2, -coords.height / 2, coords.width, coords.height);

        this.ctx.shadowColor = 'transparent';
        this.ctx.strokeStyle = isHovered ? '#1f2937' : color;
        this.ctx.lineWidth = isHovered ? 3 : 2;
        this.ctx.strokeRect(-coords.width / 2, -coords.height / 2, coords.width, coords.height);

        // Draw number label
        this.ctx.fillStyle = '#1f2937';
        this.ctx.font = `bold ${isHovered ? '16' : '14'}px sans-serif`;
        this.ctx.textAlign = 'center';
        this.ctx.textBaseline = 'middle';
        this.ctx.fillText(storage.number, 0, 0);

        this.ctx.restore();
    }

    getStorageColor(storage) {
        switch (storage.status) {
            case 'available': return '#22c55e'; // green
            case 'reserved': return '#f59e0b'; // yellow
            case 'occupied': return '#ef4444'; // red
            case 'manually_unavailable': return '#6b7280'; // gray
            default: return '#22c55e';
        }
    }
}
