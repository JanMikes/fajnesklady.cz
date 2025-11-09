import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';

export default class extends Controller {
    static values = {
        center: { type: Array, default: [49.5, 17.5] }, // Czech Republic center
        zoom: { type: Number, default: 7 }
    }

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

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            maxZoom: 19,
        }).addTo(this.map);
    }

    addStorageLocations() {
        const locations = [
            {
                name: 'Fajne Sklady Frýdek-Místek',
                coords: [49.6852, 18.3482],
                address: 'Collo Louky 1557, 738 01 Frýdek-Místek',
                containers: [
                    { name: 'Fajně malý', size: '6.6m² (3m × 2.2m × 2.2m)', price: 'od 60 Kč/den' },
                    { name: 'Fajny', size: '8.8m² (4m × 2.2m × 2.2m)', price: 'od 76 Kč/den' },
                    { name: 'Nejfajnovější', size: '13.2m² (6m × 2.2m × 2.2m)', price: 'od 126 Kč/den' }
                ]
            },
            {
                name: 'Fajne Sklady Praha',
                coords: [50.0755, 14.4378],
                address: 'Průmyslová 123, 100 00 Praha 10',
                containers: [
                    { name: 'Fajně malý', size: '6.6m²', price: 'od 65 Kč/den' },
                    { name: 'Fajny', size: '8.8m²', price: 'od 80 Kč/den' },
                    { name: 'Nejfajnovější', size: '13.2m²', price: 'od 130 Kč/den' }
                ]
            },
            {
                name: 'Fajne Sklady Brno',
                coords: [49.1951, 16.6068],
                address: 'Skladová 45, 602 00 Brno',
                containers: [
                    { name: 'Fajně malý', size: '6.6m²', price: 'od 62 Kč/den' },
                    { name: 'Fajny', size: '8.8m²', price: 'od 78 Kč/den' },
                    { name: 'Nejfajnovější', size: '13.2m²', price: 'od 128 Kč/den' }
                ]
            },
            {
                name: 'Fajne Sklady Ostrava',
                coords: [49.8209, 18.2625],
                address: 'Logistická 78, 702 00 Ostrava',
                containers: [
                    { name: 'Fajně malý', size: '6.6m²', price: 'od 60 Kč/den' },
                    { name: 'Fajny', size: '8.8m²', price: 'od 75 Kč/den' },
                    { name: 'Nejfajnovější', size: '13.2m²', price: 'od 125 Kč/den' }
                ]
            }
        ];

        locations.forEach(location => {
            const marker = L.marker(location.coords).addTo(this.map);

            const popupContent = this.createPopupContent(location);
            marker.bindPopup(popupContent);
        });
    }

    createPopupContent(location) {
        let html = `
            <div class="storage-popup">
                <h3 class="font-bold text-lg mb-2">${location.name}</h3>
                <p class="text-sm text-gray-600 mb-3">${location.address}</p>
                <div class="mb-2">
                    <h4 class="font-semibold text-sm mb-1">Dostupné kontejnery:</h4>
        `;

        location.containers.forEach(container => {
            html += `
                <div class="text-sm mb-1">
                    <span class="font-medium">${container.name}</span> - ${container.size}
                    <br>
                    <span class="text-blue-600">${container.price}</span>
                </div>
            `;
        });

        html += `
                </div>
            </div>
        `;

        return html;
    }
}
