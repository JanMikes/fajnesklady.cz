import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['canvas', 'sidebar', 'storageList', 'form', 'numberInput', 'typeSelect', 'saveBtn', 'deleteBtn', 'cancelBtn'];
    static values = {
        mapImage: String,
        storages: Array,
        storageTypes: Array,
        apiUrl: String
    }

    // Configuration constants
    HANDLE_SIZE = 14;           // Visual size of resize handles
    HANDLE_HIT_AREA = 24;       // Hit detection area (larger for easier clicking)
    ROTATION_HANDLE_RADIUS = 14;
    ROTATION_HANDLE_DISTANCE = 40;
    ROTATION_HANDLE_HIT_AREA = 12; // Additional hit area around rotation handle

    // Custom rotate cursor (SVG encoded as data URL)
    ROTATE_CURSOR = 'url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'24\' height=\'24\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%23000\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\'%3E%3Cpath d=\'M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8\'/%3E%3Cpath d=\'M21 3v5h-5\'/%3E%3C/svg%3E") 12 12, pointer';

    connect() {
        this.selectedStorage = null;
        this.isDragging = false;
        this.isResizing = false;
        this.isRotating = false;
        this.isCreating = false;
        this.dragOffset = { x: 0, y: 0 };
        this.resizeHandle = null;
        this.creationStart = null;
        this.storages = [...this.storagesValue];
        this.scale = 1;
        this.imageOffset = { x: 0, y: 0 };

        this.initializeCanvas();
        this.loadMapImage();
        this.bindEvents();
        this.renderStorageList();
    }

    initializeCanvas() {
        this.ctx = this.canvasTarget.getContext('2d');
        this.canvasTarget.width = this.canvasTarget.parentElement.clientWidth;
        this.canvasTarget.height = 600;
    }

    loadMapImage() {
        if (this.mapImageValue) {
            this.mapImg = new Image();
            this.mapImg.crossOrigin = 'anonymous';
            this.mapImg.onload = () => {
                this.fitImageToCanvas();
                this.render();
            };
            this.mapImg.onerror = (e) => {
                console.error('Failed to load map image:', this.mapImageValue, e);
                this.mapImg = null;
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
        this.canvasTarget.addEventListener('mousedown', this.onMouseDown.bind(this));
        this.canvasTarget.addEventListener('mousemove', this.onMouseMove.bind(this));
        this.canvasTarget.addEventListener('mouseup', this.onMouseUp.bind(this));
        this.canvasTarget.addEventListener('dblclick', this.onDoubleClick.bind(this));

        // Keyboard shortcuts
        this.boundKeyDown = this.onKeyDown.bind(this);
        document.addEventListener('keydown', this.boundKeyDown);

        if (this.hasSaveBtnTarget) {
            this.saveBtnTarget.addEventListener('click', this.saveStorage.bind(this));
        }
        if (this.hasDeleteBtnTarget) {
            this.deleteBtnTarget.addEventListener('click', this.deleteStorage.bind(this));
        }
        if (this.hasCancelBtnTarget) {
            this.cancelBtnTarget.addEventListener('click', this.cancelEdit.bind(this));
        }
    }

    disconnect() {
        if (this.boundKeyDown) {
            document.removeEventListener('keydown', this.boundKeyDown);
        }
    }

    onKeyDown(e) {
        // Delete selected storage with Delete or Backspace key
        if ((e.key === 'Delete' || e.key === 'Backspace') && this.selectedStorage) {
            // Don't trigger if user is typing in an input
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
                return;
            }

            // Block deletion of occupied or reserved storages
            if (this.selectedStorage.status === 'occupied' ||
                this.selectedStorage.status === 'reserved') {
                return;
            }

            e.preventDefault();
            this.deleteStorage();
        }
    }

    getMousePos(e) {
        const rect = this.canvasTarget.getBoundingClientRect();
        return {
            x: e.clientX - rect.left,
            y: e.clientY - rect.top
        };
    }

    onMouseDown(e) {
        const pos = this.getMousePos(e);

        // Check if clicking on rotation handle
        if (this.selectedStorage) {
            const rotHandle = this.getRotationHandle(pos, this.selectedStorage);
            if (rotHandle) {
                this.isRotating = true;
                return;
            }
        }

        // Check if clicking on resize handle
        if (this.selectedStorage) {
            const handle = this.getResizeHandle(pos, this.selectedStorage);
            if (handle) {
                this.isResizing = true;
                this.resizeHandle = handle;
                return;
            }
        }

        // Check if clicking on a storage
        const storage = this.getStorageAtPosition(pos);
        if (storage) {
            this.selectStorage(storage);
            this.isDragging = true;
            this.dragOffset = {
                x: pos.x - storage.coordinates.x,
                y: pos.y - storage.coordinates.y
            };
        } else {
            // Start creating new storage
            this.isCreating = true;
            this.creationStart = pos;
            this.deselectStorage();
        }
    }

    onMouseMove(e) {
        const pos = this.getMousePos(e);

        if (this.isDragging && this.selectedStorage) {
            this.selectedStorage.coordinates.x = Math.max(0, pos.x - this.dragOffset.x);
            this.selectedStorage.coordinates.y = Math.max(0, pos.y - this.dragOffset.y);
            this.selectedStorage.modified = true;
            this.canvasTarget.style.cursor = 'move';
            this.render();
        } else if (this.isResizing && this.selectedStorage) {
            this.resizeStorage(pos);
            this.selectedStorage.modified = true;
            // Keep the resize cursor during resize operation
            if (this.resizeHandle) {
                this.canvasTarget.style.cursor = this.resizeHandle.cursor;
            }
            this.render();
        } else if (this.isRotating && this.selectedStorage) {
            // Calculate rotation angle from center of storage to mouse position
            const coords = this.selectedStorage.coordinates;
            const centerX = coords.x + coords.width / 2;
            const centerY = coords.y + coords.height / 2;
            const angle = Math.atan2(pos.y - centerY, pos.x - centerX) * 180 / Math.PI + 90;
            coords.rotation = Math.round(angle);
            this.selectedStorage.modified = true;
            this.canvasTarget.style.cursor = this.ROTATE_CURSOR;
            this.render();
        } else if (this.isCreating && this.creationStart) {
            this.canvasTarget.style.cursor = 'crosshair';
            this.render();
            // Draw creation rectangle
            const width = pos.x - this.creationStart.x;
            const height = pos.y - this.creationStart.y;
            this.ctx.strokeStyle = '#3b82f6';
            this.ctx.lineWidth = 2;
            this.ctx.setLineDash([5, 5]);
            this.ctx.strokeRect(this.creationStart.x, this.creationStart.y, width, height);
            this.ctx.setLineDash([]);
        } else {
            // Update cursor based on what's under the mouse
            if (this.selectedStorage) {
                const rotHandle = this.getRotationHandle(pos, this.selectedStorage);
                if (rotHandle) {
                    this.canvasTarget.style.cursor = this.ROTATE_CURSOR;
                    return;
                }
                const handle = this.getResizeHandle(pos, this.selectedStorage);
                if (handle) {
                    this.canvasTarget.style.cursor = handle.cursor;
                    return;
                }
            }
            const storage = this.getStorageAtPosition(pos);
            if (storage) {
                this.canvasTarget.style.cursor = 'move';
            } else {
                this.canvasTarget.style.cursor = 'crosshair';
            }
        }
    }

    onMouseUp(e) {
        const pos = this.getMousePos(e);

        if (this.isCreating && this.creationStart) {
            const width = Math.abs(pos.x - this.creationStart.x);
            const height = Math.abs(pos.y - this.creationStart.y);

            if (width > 20 && height > 20) {
                const newStorage = {
                    id: null,
                    number: this.getNextStorageNumber(),
                    storageTypeId: this.storageTypesValue[0]?.id || null,
                    coordinates: {
                        x: Math.min(this.creationStart.x, pos.x),
                        y: Math.min(this.creationStart.y, pos.y),
                        width: width,
                        height: height,
                        rotation: 0
                    },
                    isNew: true,
                    modified: true
                };
                this.storages.push(newStorage);
                this.selectStorage(newStorage);
                this.renderStorageList();
            }
        }

        this.isDragging = false;
        this.isResizing = false;
        this.isRotating = false;
        this.isCreating = false;
        this.creationStart = null;
        this.resizeHandle = null;
        this.render();
    }

    onDoubleClick(e) {
        const pos = this.getMousePos(e);
        const storage = this.getStorageAtPosition(pos);
        if (storage) {
            this.selectStorage(storage);
            this.showForm();
        }
    }

    // Transform a point from canvas coordinates to storage's local coordinates (accounting for rotation)
    transformPointToLocal(pos, storage) {
        const coords = storage.coordinates;
        const centerX = coords.x + coords.width / 2;
        const centerY = coords.y + coords.height / 2;
        const rotation = (coords.rotation || 0) * Math.PI / 180;

        // Translate point to origin (center of storage)
        const dx = pos.x - centerX;
        const dy = pos.y - centerY;

        // Rotate point in opposite direction
        const cos = Math.cos(-rotation);
        const sin = Math.sin(-rotation);
        const localX = dx * cos - dy * sin;
        const localY = dx * sin + dy * cos;

        return { x: localX, y: localY };
    }

    // Check if a point (in local coordinates) is inside a storage's bounds
    isPointInStorage(localPos, storage) {
        const coords = storage.coordinates;
        const halfWidth = coords.width / 2;
        const halfHeight = coords.height / 2;

        return localPos.x >= -halfWidth && localPos.x <= halfWidth &&
               localPos.y >= -halfHeight && localPos.y <= halfHeight;
    }

    getStorageAtPosition(pos) {
        for (let i = this.storages.length - 1; i >= 0; i--) {
            const s = this.storages[i];
            const localPos = this.transformPointToLocal(pos, s);
            if (this.isPointInStorage(localPos, s)) {
                return s;
            }
        }
        return null;
    }

    getResizeHandle(pos, storage) {
        const coords = storage.coordinates;
        const hitArea = this.HANDLE_HIT_AREA;
        const halfWidth = coords.width / 2;
        const halfHeight = coords.height / 2;

        // Transform mouse position to storage's local coordinate system
        const localPos = this.transformPointToLocal(pos, storage);

        // Define handles in local coordinates (relative to center)
        const handles = [
            { name: 'nw', localX: -halfWidth, localY: -halfHeight, cursor: 'nwse-resize' },
            { name: 'ne', localX: halfWidth, localY: -halfHeight, cursor: 'nesw-resize' },
            { name: 'sw', localX: -halfWidth, localY: halfHeight, cursor: 'nesw-resize' },
            { name: 'se', localX: halfWidth, localY: halfHeight, cursor: 'nwse-resize' }
        ];

        for (const handle of handles) {
            const dx = localPos.x - handle.localX;
            const dy = localPos.y - handle.localY;
            const distance = Math.sqrt(dx * dx + dy * dy);

            if (distance <= hitArea) {
                return handle;
            }
        }
        return null;
    }

    getRotationHandle(pos, storage) {
        const coords = storage.coordinates;
        const handleRadius = this.ROTATION_HANDLE_RADIUS;
        const handleDistance = this.ROTATION_HANDLE_DISTANCE;
        const rotation = (coords.rotation || 0) * Math.PI / 180;

        // Calculate center of storage
        const centerX = coords.x + coords.width / 2;
        const centerY = coords.y + coords.height / 2;

        // Rotation handle position in local coords (above the top center)
        const localHandleX = 0;
        const localHandleY = -coords.height / 2 - handleDistance;

        // Transform to canvas coordinates (apply rotation)
        const cos = Math.cos(rotation);
        const sin = Math.sin(rotation);
        const handleX = centerX + localHandleX * cos - localHandleY * sin;
        const handleY = centerY + localHandleX * sin + localHandleY * cos;

        // Check if mouse is within the circular handle
        const dx = pos.x - handleX;
        const dy = pos.y - handleY;
        const distance = Math.sqrt(dx * dx + dy * dy);

        if (distance <= handleRadius + this.ROTATION_HANDLE_HIT_AREA) {
            return { x: handleX, y: handleY, radius: handleRadius };
        }
        return null;
    }

    resizeStorage(pos) {
        if (!this.selectedStorage || !this.resizeHandle) return;

        const coords = this.selectedStorage.coordinates;
        const minSize = 30;

        // Transform mouse position to local coordinates
        const localPos = this.transformPointToLocal(pos, this.selectedStorage);
        const halfWidth = coords.width / 2;
        const halfHeight = coords.height / 2;

        // Calculate center position
        const centerX = coords.x + halfWidth;
        const centerY = coords.y + halfHeight;

        let newWidth = coords.width;
        let newHeight = coords.height;
        let newCenterX = centerX;
        let newCenterY = centerY;

        switch (this.resizeHandle.name) {
            case 'se': // Southeast - resize from bottom-right
                newWidth = Math.max(minSize, halfWidth + localPos.x);
                newHeight = Math.max(minSize, halfHeight + localPos.y);
                // Adjust center to keep opposite corner fixed
                newCenterX = centerX + (newWidth - coords.width) / 2;
                newCenterY = centerY + (newHeight - coords.height) / 2;
                break;
            case 'nw': // Northwest - resize from top-left
                newWidth = Math.max(minSize, halfWidth - localPos.x);
                newHeight = Math.max(minSize, halfHeight - localPos.y);
                // Adjust center to keep opposite corner fixed
                newCenterX = centerX - (newWidth - coords.width) / 2;
                newCenterY = centerY - (newHeight - coords.height) / 2;
                break;
            case 'ne': // Northeast - resize from top-right
                newWidth = Math.max(minSize, halfWidth + localPos.x);
                newHeight = Math.max(minSize, halfHeight - localPos.y);
                // Adjust center
                newCenterX = centerX + (newWidth - coords.width) / 2;
                newCenterY = centerY - (newHeight - coords.height) / 2;
                break;
            case 'sw': // Southwest - resize from bottom-left
                newWidth = Math.max(minSize, halfWidth - localPos.x);
                newHeight = Math.max(minSize, halfHeight + localPos.y);
                // Adjust center
                newCenterX = centerX - (newWidth - coords.width) / 2;
                newCenterY = centerY + (newHeight - coords.height) / 2;
                break;
        }

        // Apply rotation to center offset to get correct position shift
        const rotation = (coords.rotation || 0) * Math.PI / 180;
        const cos = Math.cos(rotation);
        const sin = Math.sin(rotation);

        // Calculate the offset in world coordinates
        const localDx = newCenterX - centerX;
        const localDy = newCenterY - centerY;
        const worldDx = localDx * cos - localDy * sin;
        const worldDy = localDx * sin + localDy * cos;

        // Update coordinates
        coords.width = newWidth;
        coords.height = newHeight;
        coords.x = centerX + worldDx - newWidth / 2;
        coords.y = centerY + worldDy - newHeight / 2;
    }

    selectStorage(storage) {
        this.selectedStorage = storage;
        this.showForm();
        this.render();
        this.renderStorageList();
    }

    deselectStorage() {
        this.selectedStorage = null;
        this.hideForm();
        this.render();
    }

    showForm() {
        if (!this.hasFormTarget || !this.selectedStorage) return;

        this.formTarget.classList.remove('hidden');
        this.numberInputTarget.value = this.selectedStorage.number || '';
        this.typeSelectTarget.value = this.selectedStorage.storageTypeId || '';

        if (this.selectedStorage.isNew) {
            this.deleteBtnTarget.classList.add('hidden');
        } else {
            this.deleteBtnTarget.classList.remove('hidden');

            // Disable delete for occupied or reserved storages
            const isOccupied = this.selectedStorage.status === 'occupied';
            const isReserved = this.selectedStorage.status === 'reserved';

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

    async saveStorage() {
        if (!this.selectedStorage) return;

        this.selectedStorage.number = this.numberInputTarget.value;
        this.selectedStorage.storageTypeId = this.typeSelectTarget.value;

        const data = {
            number: this.selectedStorage.number,
            storageTypeId: this.selectedStorage.storageTypeId,
            coordinates: this.selectedStorage.coordinates
        };

        try {
            const url = this.selectedStorage.id
                ? `${this.apiUrlValue}/${this.selectedStorage.id}`
                : this.apiUrlValue;
            const method = this.selectedStorage.id ? 'PUT' : 'POST';

            const response = await fetch(url, {
                method: method,
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            });

            if (!response.ok) {
                const error = await response.json();
                alert(error.message || 'Chyba pri ukladani skladu');
                return;
            }

            const result = await response.json();
            this.selectedStorage.id = result.id;
            this.selectedStorage.isNew = false;
            this.selectedStorage.modified = false;

            this.renderStorageList();
            this.render();
            this.showNotification('Sklad ulozen');
        } catch (err) {
            console.error('Save error:', err);
            alert('Chyba pri ukladani skladu');
        }
    }

    async deleteStorage() {
        if (!this.selectedStorage || !this.selectedStorage.id) {
            // Just remove from local array if not saved yet
            const idx = this.storages.indexOf(this.selectedStorage);
            if (idx > -1) {
                this.storages.splice(idx, 1);
            }
            this.deselectStorage();
            this.renderStorageList();
            return;
        }

        if (!confirm('Opravdu chcete smazat tento sklad?')) return;

        try {
            const response = await fetch(`${this.apiUrlValue}/${this.selectedStorage.id}`, {
                method: 'DELETE'
            });

            if (!response.ok) {
                const error = await response.json();
                alert(error.message || 'Chyba pri mazani skladu');
                return;
            }

            const idx = this.storages.indexOf(this.selectedStorage);
            if (idx > -1) {
                this.storages.splice(idx, 1);
            }
            this.deselectStorage();
            this.renderStorageList();
            this.showNotification('Sklad smazan');
        } catch (err) {
            console.error('Delete error:', err);
            alert('Chyba pri mazani skladu');
        }
    }

    cancelEdit() {
        if (this.selectedStorage && this.selectedStorage.isNew) {
            const idx = this.storages.indexOf(this.selectedStorage);
            if (idx > -1) {
                this.storages.splice(idx, 1);
            }
            this.renderStorageList();
        }
        this.deselectStorage();
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
            // Draw grid if no image
            this.drawGrid();
        }

        // Draw storages
        this.storages.forEach(storage => {
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
        const isSelected = storage === this.selectedStorage;
        const rotation = (coords.rotation || 0) * Math.PI / 180;
        const centerX = coords.x + coords.width / 2;
        const centerY = coords.y + coords.height / 2;

        // Get storage type color
        const storageType = this.storageTypesValue.find(t => t.id === storage.storageTypeId);
        const color = this.getStorageColor(storage, storageType);

        // Save context for rotation
        this.ctx.save();
        this.ctx.translate(centerX, centerY);
        this.ctx.rotate(rotation);

        // Draw rectangle
        this.ctx.fillStyle = color + '80'; // 50% opacity
        this.ctx.fillRect(-coords.width / 2, -coords.height / 2, coords.width, coords.height);

        this.ctx.strokeStyle = isSelected ? '#2563eb' : color;
        this.ctx.lineWidth = isSelected ? 3 : 2;
        this.ctx.strokeRect(-coords.width / 2, -coords.height / 2, coords.width, coords.height);

        // Draw number label
        this.ctx.fillStyle = '#1f2937';
        this.ctx.font = 'bold 14px sans-serif';
        this.ctx.textAlign = 'center';
        this.ctx.textBaseline = 'middle';
        this.ctx.fillText(storage.number || '?', 0, 0);

        // Draw resize handles if selected (inside the rotated context so they rotate with the storage)
        if (isSelected) {
            const handleSize = this.HANDLE_SIZE;
            const halfSize = handleSize / 2;
            const halfWidth = coords.width / 2;
            const halfHeight = coords.height / 2;

            this.ctx.fillStyle = '#2563eb';

            // NW handle (top-left)
            this.ctx.fillRect(-halfWidth - halfSize, -halfHeight - halfSize, handleSize, handleSize);
            // NE handle (top-right)
            this.ctx.fillRect(halfWidth - halfSize, -halfHeight - halfSize, handleSize, handleSize);
            // SW handle (bottom-left)
            this.ctx.fillRect(-halfWidth - halfSize, halfHeight - halfSize, handleSize, handleSize);
            // SE handle (bottom-right)
            this.ctx.fillRect(halfWidth - halfSize, halfHeight - halfSize, handleSize, handleSize);

            // Draw rotation handle (in local coordinates, above the storage)
            const handleRadius = this.ROTATION_HANDLE_RADIUS;
            const handleDistance = this.ROTATION_HANDLE_DISTANCE;
            const rotHandleY = -halfHeight - handleDistance;

            // Draw line connecting to storage
            this.ctx.strokeStyle = '#2563eb';
            this.ctx.lineWidth = 2;
            this.ctx.beginPath();
            this.ctx.moveTo(0, -halfHeight);
            this.ctx.lineTo(0, rotHandleY);
            this.ctx.stroke();

            // Draw rotation handle circle
            this.ctx.fillStyle = '#2563eb';
            this.ctx.beginPath();
            this.ctx.arc(0, rotHandleY, handleRadius, 0, Math.PI * 2);
            this.ctx.fill();

            // Draw rotation icon inside the circle
            this.ctx.strokeStyle = '#ffffff';
            this.ctx.lineWidth = 2;
            this.ctx.beginPath();
            this.ctx.arc(0, rotHandleY, 5, -Math.PI * 0.7, Math.PI * 0.3);
            this.ctx.stroke();
        }

        this.ctx.restore();
    }

    getStorageColor(storage, storageType) {
        if (storage.status === 'occupied') return '#ef4444'; // red
        if (storage.status === 'reserved') return '#f59e0b'; // yellow
        if (storage.status === 'manually_unavailable') return '#6b7280'; // gray
        return '#22c55e'; // green for available
    }

    renderStorageList() {
        if (!this.hasStorageListTarget) return;

        let html = '<ul class="divide-y divide-gray-200">';
        this.storages.forEach((storage, index) => {
            const storageType = this.storageTypesValue.find(t => t.id === storage.storageTypeId);
            const typeName = storageType ? storageType.name : 'Nepřiřazen';
            const isSelected = storage === this.selectedStorage;

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
            html = '<p class="text-sm text-gray-500 p-4">Zadne sklady. Kliknete a tahnete na platne pro vytvoreni.</p>';
        }

        this.storageListTarget.innerHTML = html;
    }

    onStorageListClick(e) {
        const li = e.target.closest('li');
        if (!li) return;

        const index = parseInt(li.dataset.storageIndex, 10);
        const storage = this.storages[index];

        if (storage) {
            this.selectStorage(storage);
        }
    }

    showNotification(message) {
        // Simple notification - could be improved with toast library
        const notification = document.createElement('div');
        notification.className = 'fixed bottom-4 right-4 bg-green-500 text-white px-4 py-2 rounded shadow-lg';
        notification.textContent = message;
        document.body.appendChild(notification);

        setTimeout(() => notification.remove(), 3000);
    }
}
