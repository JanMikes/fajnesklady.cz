import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['canvas', 'sidebar', 'storageList', 'form', 'numberInput', 'typeSelect', 'saveBtn', 'deleteBtn', 'cancelBtn'];
    static values = {
        mapImage: String,
        storages: Array,
        storageTypes: Array,
        apiUrl: String,
        csrfToken: String
    }

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
            this.render();
        } else if (this.isResizing && this.selectedStorage) {
            this.resizeStorage(pos);
            this.selectedStorage.modified = true;
            this.render();
        } else if (this.isRotating && this.selectedStorage) {
            // Calculate rotation angle from center of storage to mouse position
            const coords = this.selectedStorage.coordinates;
            const centerX = coords.x + coords.width / 2;
            const centerY = coords.y + coords.height / 2;
            const angle = Math.atan2(pos.y - centerY, pos.x - centerX) * 180 / Math.PI + 90;
            coords.rotation = Math.round(angle);
            this.selectedStorage.modified = true;
            this.render();
        } else if (this.isCreating && this.creationStart) {
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
            // Update cursor
            if (this.selectedStorage) {
                const rotHandle = this.getRotationHandle(pos, this.selectedStorage);
                if (rotHandle) {
                    this.canvasTarget.style.cursor = 'grab';
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

    getStorageAtPosition(pos) {
        for (let i = this.storages.length - 1; i >= 0; i--) {
            const s = this.storages[i];
            const coords = s.coordinates;
            if (pos.x >= coords.x && pos.x <= coords.x + coords.width &&
                pos.y >= coords.y && pos.y <= coords.y + coords.height) {
                return s;
            }
        }
        return null;
    }

    getResizeHandle(pos, storage) {
        const coords = storage.coordinates;
        const handleSize = 12;
        const handles = [
            { name: 'nw', x: coords.x, y: coords.y, cursor: 'nwse-resize' },
            { name: 'ne', x: coords.x + coords.width - handleSize, y: coords.y, cursor: 'nesw-resize' },
            { name: 'sw', x: coords.x, y: coords.y + coords.height - handleSize, cursor: 'nesw-resize' },
            { name: 'se', x: coords.x + coords.width - handleSize, y: coords.y + coords.height - handleSize, cursor: 'nwse-resize' }
        ];

        for (const handle of handles) {
            if (pos.x >= handle.x && pos.x <= handle.x + handleSize &&
                pos.y >= handle.y && pos.y <= handle.y + handleSize) {
                return handle;
            }
        }
        return null;
    }

    getRotationHandle(pos, storage) {
        const coords = storage.coordinates;
        const handleRadius = 8;
        const handleDistance = 25; // Distance above the storage

        // Calculate center of storage
        const centerX = coords.x + coords.width / 2;
        const centerY = coords.y;

        // Rotation handle is above the center top of the storage
        const handleX = centerX;
        const handleY = centerY - handleDistance;

        // Check if mouse is within the circular handle
        const dx = pos.x - handleX;
        const dy = pos.y - handleY;
        const distance = Math.sqrt(dx * dx + dy * dy);

        if (distance <= handleRadius) {
            return { x: handleX, y: handleY, radius: handleRadius };
        }
        return null;
    }

    resizeStorage(pos) {
        if (!this.selectedStorage || !this.resizeHandle) return;

        const coords = this.selectedStorage.coordinates;
        const minSize = 30;

        switch (this.resizeHandle.name) {
            case 'se': // Southeast - standard resize
                coords.width = Math.max(minSize, pos.x - coords.x);
                coords.height = Math.max(minSize, pos.y - coords.y);
                break;
            case 'nw': // Northwest - resize from top-left
                const newWidthNW = coords.width + (coords.x - pos.x);
                const newHeightNW = coords.height + (coords.y - pos.y);
                if (newWidthNW >= minSize) {
                    coords.x = pos.x;
                    coords.width = newWidthNW;
                }
                if (newHeightNW >= minSize) {
                    coords.y = pos.y;
                    coords.height = newHeightNW;
                }
                break;
            case 'ne': // Northeast - resize from top-right
                const newWidthNE = pos.x - coords.x;
                const newHeightNE = coords.height + (coords.y - pos.y);
                if (newWidthNE >= minSize) {
                    coords.width = newWidthNE;
                }
                if (newHeightNE >= minSize) {
                    coords.y = pos.y;
                    coords.height = newHeightNE;
                }
                break;
            case 'sw': // Southwest - resize from bottom-left
                const newWidthSW = coords.width + (coords.x - pos.x);
                const newHeightSW = pos.y - coords.y;
                if (newWidthSW >= minSize) {
                    coords.x = pos.x;
                    coords.width = newWidthSW;
                }
                if (newHeightSW >= minSize) {
                    coords.height = newHeightSW;
                }
                break;
        }
    }

    selectStorage(storage) {
        this.selectedStorage = storage;
        this.showForm();
        this.render();
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
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfTokenValue
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
                method: 'DELETE',
                headers: {
                    'X-CSRF-Token': this.csrfTokenValue
                }
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

        // Get storage type color
        const storageType = this.storageTypesValue.find(t => t.id === storage.storageTypeId);
        const color = this.getStorageColor(storage, storageType);

        // Save context for rotation
        this.ctx.save();
        this.ctx.translate(coords.x + coords.width / 2, coords.y + coords.height / 2);
        this.ctx.rotate((coords.rotation || 0) * Math.PI / 180);

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

        this.ctx.restore();

        // Draw resize handles if selected
        if (isSelected) {
            const handleSize = 12;
            this.ctx.fillStyle = '#2563eb';
            // NW handle
            this.ctx.fillRect(coords.x, coords.y, handleSize, handleSize);
            // NE handle
            this.ctx.fillRect(coords.x + coords.width - handleSize, coords.y, handleSize, handleSize);
            // SW handle
            this.ctx.fillRect(coords.x, coords.y + coords.height - handleSize, handleSize, handleSize);
            // SE handle
            this.ctx.fillRect(coords.x + coords.width - handleSize, coords.y + coords.height - handleSize, handleSize, handleSize);

            // Draw rotation handle
            const handleRadius = 8;
            const handleDistance = 25;
            const centerX = coords.x + coords.width / 2;
            const handleY = coords.y - handleDistance;

            // Draw line connecting to storage
            this.ctx.strokeStyle = '#2563eb';
            this.ctx.lineWidth = 2;
            this.ctx.beginPath();
            this.ctx.moveTo(centerX, coords.y);
            this.ctx.lineTo(centerX, handleY);
            this.ctx.stroke();

            // Draw rotation handle circle
            this.ctx.fillStyle = '#2563eb';
            this.ctx.beginPath();
            this.ctx.arc(centerX, handleY, handleRadius, 0, Math.PI * 2);
            this.ctx.fill();

            // Draw rotation icon inside the circle
            this.ctx.strokeStyle = '#ffffff';
            this.ctx.lineWidth = 2;
            this.ctx.beginPath();
            this.ctx.arc(centerX, handleY, 4, -Math.PI * 0.7, Math.PI * 0.3);
            this.ctx.stroke();
        }
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
        this.storages.forEach(storage => {
            const storageType = this.storageTypesValue.find(t => t.id === storage.storageTypeId);
            const typeName = storageType ? storageType.name : 'Neprirazen';
            const isSelected = storage === this.selectedStorage;

            html += `
                <li class="p-2 cursor-pointer hover:bg-gray-50 ${isSelected ? 'bg-blue-50' : ''}"
                    data-action="click->storage-canvas#onStorageListClick"
                    data-storage-id="${storage.id || 'new'}">
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

        const storageId = li.dataset.storageId;
        const storage = this.storages.find(s =>
            (s.id && s.id === storageId) || (!s.id && storageId === 'new')
        );

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
