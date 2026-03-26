import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';

export default class extends Controller {
    static values = {
        places: { type: Array, default: [] },
        center: { type: Array, default: [49.5, 17.5] },
        zoom: { type: Number, default: 7 }
    };

    static targets = ['mapContainer', 'placeCard', 'placeList'];

    connect() {
        this.markers = {};
        this.markerElements = {};
        this.initializeMap();
        this.addStorageLocations();
        this.bindCardHoverEvents();
    }

    disconnect() {
        if (this.map) {
            this.map.remove();
        }
    }

    initializeMap() {
        const mapEl = this.hasMapContainerTarget ? this.mapContainerTarget : this.element;
        this.map = L.map(mapEl).setView(this.centerValue, this.zoomValue);

        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
            maxZoom: 20,
            subdomains: 'abcd'
        }).addTo(this.map);
    }

    addStorageLocations() {
        const places = this.placesValue;

        if (!places || places.length === 0) {
            return;
        }

        const bounds = L.latLngBounds();
        let hasValidCoordinates = false;

        places.forEach(place => {
            if (!place.latitude || !place.longitude) {
                return;
            }

            const pinColor = place.typeColor || '#d23233';
            const icon = this.createIcon(pinColor, false);

            const coords = [parseFloat(place.latitude), parseFloat(place.longitude)];
            const marker = L.marker(coords, { icon }).addTo(this.map);

            const popupContent = this.createPopupContent(place);
            marker.bindPopup(popupContent, {
                maxWidth: 300,
                minWidth: 250
            });

            // Store marker reference by place ID
            this.markers[place.id] = { marker, pinColor, coords };

            // Marker hover → highlight corresponding card
            marker.on('mouseover', () => this.highlightCard(place.id));
            marker.on('mouseout', () => this.unhighlightCard(place.id));

            // Popup open/close → highlight corresponding card
            marker.on('popupopen', () => this.highlightCard(place.id));
            marker.on('popupclose', () => this.unhighlightCard(place.id));

            bounds.extend(coords);
            hasValidCoordinates = true;
        });

        if (hasValidCoordinates && bounds.isValid()) {
            this.map.fitBounds(bounds, {
                padding: [50, 50],
                maxZoom: 12
            });
        }
    }

    createIcon(color, highlighted) {
        const size = highlighted ? 42 : 32;
        const border = highlighted ? 4 : 3;
        const shadow = highlighted
            ? '0 0 0 6px rgba(210, 50, 51, 0.25), 0 2px 12px rgba(0, 0, 0, 0.4)'
            : '0 2px 8px rgba(0, 0, 0, 0.3)';

        return L.divIcon({
            className: 'custom-map-marker',
            html: `<div style="
                width: ${size}px;
                height: ${size}px;
                background-color: ${color};
                border: ${border}px solid white;
                border-radius: 50%;
                box-shadow: ${shadow};
                transition: all 0.2s ease;
            "></div>`,
            iconSize: [size, size],
            iconAnchor: [size / 2, size / 2],
            popupAnchor: [0, -(size / 2)]
        });
    }

    bindCardHoverEvents() {
        if (!this.hasPlaceCardTarget) return;

        this.placeCardTargets.forEach(card => {
            const placeId = card.dataset.placeId;

            card.addEventListener('mouseenter', () => this.highlightMarker(placeId));
            card.addEventListener('mouseleave', () => this.unhighlightMarker(placeId));
            card.addEventListener('click', (e) => {
                // Don't intercept link clicks
                if (e.target.closest('a')) return;
                this.focusMarker(placeId);
            });
        });
    }

    highlightMarker(placeId) {
        const data = this.markers[placeId];
        if (!data) return;

        const highlightedIcon = this.createIcon(data.pinColor, true);
        data.marker.setIcon(highlightedIcon);
        data.marker.setZIndexOffset(1000);
    }

    unhighlightMarker(placeId) {
        const data = this.markers[placeId];
        if (!data) return;

        const normalIcon = this.createIcon(data.pinColor, false);
        data.marker.setIcon(normalIcon);
        data.marker.setZIndexOffset(0);
    }

    highlightCard(placeId) {
        const card = this.placeCardTargets.find(c => c.dataset.placeId === placeId);
        if (!card) return;

        card.classList.add('place-card--highlighted');

        // Scroll card into view within the list
        if (this.hasPlaceListTarget) {
            card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    unhighlightCard(placeId) {
        const card = this.placeCardTargets.find(c => c.dataset.placeId === placeId);
        if (!card) return;

        card.classList.remove('place-card--highlighted');
    }

    focusMarker(placeId) {
        const data = this.markers[placeId];
        if (!data) return;

        this.map.flyTo(data.coords, 13, { duration: 0.5 });
        data.marker.openPopup();
    }

    createPopupContent(place) {
        let html = `
            <div class="storage-popup">
                <h3 class="font-bold text-lg mb-1">${this.escapeHtml(place.name)}</h3>
                <p class="text-sm text-gray-600 mb-3">${place.address ? this.escapeHtml(place.address) + ', ' : ''}${this.escapeHtml(place.city)}</p>
        `;

        if (place.storageTypes && place.storageTypes.length > 0) {
            html += `
                <div class="mb-3">
                    <h4 class="font-semibold text-sm mb-2 text-gray-700">Dostupné kontejnery:</h4>
                    <div class="space-y-3">
            `;

            place.storageTypes.forEach(type => {
                const available = type.availableCount > 0;
                html += `
                    <div class="text-sm border-b border-gray-100 pb-2">
                        <div class="flex justify-between items-center mb-1">
                            <div>
                                <span class="font-medium">${this.escapeHtml(type.name)}</span>
                                <span class="text-xs text-gray-500 block">${this.escapeHtml(type.dimensions)}</span>
                            </div>
                            <span style="color: #d23233;" class="font-semibold whitespace-nowrap">${this.formatPrice(type.pricePerMonth)} Kč/měs</span>
                        </div>
                        <div class="flex justify-between items-center mt-1">
                            <span class="text-xs ${available ? 'text-green-600' : 'text-red-600'}">
                                ${available ? `✓ ${type.availableCount} volných` : '✗ Obsazeno'}
                            </span>
                            ${available
                                ? `<a href="${this.escapeHtml(type.orderUrl)}"
                                      class="inline-block px-3 py-1 text-xs font-medium rounded"
                                      style="background-color: #d23233; color: white;">
                                      Objednat
                                  </a>`
                                : `<span class="inline-block px-3 py-1 text-xs font-medium rounded opacity-50"
                                         style="background-color: #999; color: white; cursor: not-allowed;">
                                      Nedostupné
                                  </span>`
                            }
                        </div>
                    </div>
                `;
            });

            html += `
                    </div>
                </div>
            `;
        }

        html += `
                <a href="${this.escapeHtml(place.url)}"
                   class="inline-block w-full text-center px-4 py-2 text-sm font-medium rounded"
                   style="background-color: white; color: #d23233; border: 1px solid #d23233;">
                    Zobrazit detail
                </a>
            </div>
        `;

        return html;
    }

    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    formatPrice(price) {
        return new Intl.NumberFormat('cs-CZ', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(price);
    }
}
