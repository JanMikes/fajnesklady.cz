import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';

export default class extends Controller {
    static values = {
        places: { type: Array, default: [] },
        center: { type: Array, default: [49.5, 17.5] }, // Czech Republic center
        zoom: { type: Number, default: 7 }
    };

    connect() {
        this.initializeMap();
        this.addStorageLocations();
    }

    disconnect() {
        if (this.map) {
            this.map.remove();
        }
    }

    initializeMap() {
        this.map = L.map(this.element).setView(this.centerValue, this.zoomValue);

        // CartoDB Dark Matter - Modern, clean dark theme
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

        // Create custom icon
        const customIcon = L.divIcon({
            className: 'custom-map-marker',
            html: `<div style="
                width: 32px;
                height: 32px;
                background-color: #d23233;
                border: 3px solid white;
                border-radius: 50%;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
            "></div>`,
            iconSize: [32, 32],
            iconAnchor: [16, 16],
            popupAnchor: [0, -16]
        });

        places.forEach(place => {
            // Skip places without coordinates
            if (!place.latitude || !place.longitude) {
                return;
            }

            const coords = [parseFloat(place.latitude), parseFloat(place.longitude)];
            const marker = L.marker(coords, { icon: customIcon }).addTo(this.map);

            const popupContent = this.createPopupContent(place);
            marker.bindPopup(popupContent, {
                maxWidth: 300,
                minWidth: 250
            });

            bounds.extend(coords);
            hasValidCoordinates = true;
        });

        // Fit map to show all markers
        if (hasValidCoordinates && bounds.isValid()) {
            this.map.fitBounds(bounds, {
                padding: [50, 50],
                maxZoom: 12
            });
        }
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
                    <div class="space-y-2">
            `;

            place.storageTypes.forEach(type => {
                html += `
                    <div class="flex justify-between items-center text-sm border-b border-gray-100 pb-1">
                        <div>
                            <span class="font-medium">${this.escapeHtml(type.name)}</span>
                            <span class="text-xs text-gray-500 block">${this.escapeHtml(type.dimensions)}</span>
                        </div>
                        <span style="color: #d23233;" class="font-semibold whitespace-nowrap">${this.formatPrice(type.pricePerMonth)} Kč/měs</span>
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
