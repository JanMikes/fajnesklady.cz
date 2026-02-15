import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';

export default class extends Controller {
    static targets = ['addressFields', 'mapContainer', 'latitude', 'longitude', 'toggle'];

    static values = {
        latitude: { type: Number, default: 49.8 },
        longitude: { type: Number, default: 15.5 },
        zoom: { type: Number, default: 7 },
    };

    connect() {
        this.mapInitialized = false;
        this.marker = null;

        // Set initial state based on checkbox
        const checkbox = this.toggleTarget;
        if (checkbox.checked) {
            this.showMap();
        } else {
            this.showAddress();
        }
    }

    disconnect() {
        if (this.map) {
            this.map.remove();
            this.map = null;
        }
    }

    toggle() {
        const checkbox = this.toggleTarget;
        if (checkbox.checked) {
            this.showMap();
        } else {
            this.showAddress();
        }
    }

    showMap() {
        this.addressFieldsTarget.classList.add('hidden');
        this.mapContainerTarget.classList.remove('hidden');

        if (!this.mapInitialized) {
            this.initializeMap();
        } else {
            this.map.invalidateSize();
        }
    }

    showAddress() {
        this.addressFieldsTarget.classList.remove('hidden');
        this.mapContainerTarget.classList.add('hidden');
    }

    coordinateChanged() {
        const lat = parseFloat(this.latitudeTarget.value);
        const lng = parseFloat(this.longitudeTarget.value);

        if (isNaN(lat) || isNaN(lng)) {
            return;
        }

        if (lat < -90 || lat > 90 || lng < -180 || lng > 180) {
            return;
        }

        if (this.mapInitialized) {
            this.placeMarker(lat, lng);
            this.map.setView([lat, lng], 14);
        }
    }

    initializeMap() {
        const mapEl = this.mapContainerTarget.querySelector('[data-map]');
        const lat = this.latitudeTarget.value ? parseFloat(this.latitudeTarget.value) : this.latitudeValue;
        const lng = this.longitudeTarget.value ? parseFloat(this.longitudeTarget.value) : this.longitudeValue;
        const hasExisting = this.latitudeTarget.value && this.longitudeTarget.value;

        this.map = L.map(mapEl).setView([lat, lng], hasExisting ? 14 : this.zoomValue);

        L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
            maxZoom: 20,
            subdomains: 'abcd',
        }).addTo(this.map);

        if (hasExisting) {
            this.placeMarker(lat, lng);
        }

        this.map.on('click', (e) => {
            this.placeMarker(e.latlng.lat, e.latlng.lng);
            this.latitudeTarget.value = e.latlng.lat.toFixed(7);
            this.longitudeTarget.value = e.latlng.lng.toFixed(7);
        });

        this.mapInitialized = true;
    }

    placeMarker(lat, lng) {
        if (this.marker) {
            this.marker.setLatLng([lat, lng]);
        } else {
            const icon = L.divIcon({
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
            });

            this.marker = L.marker([lat, lng], { icon, draggable: true }).addTo(this.map);

            this.marker.on('dragend', () => {
                const pos = this.marker.getLatLng();
                this.latitudeTarget.value = pos.lat.toFixed(7);
                this.longitudeTarget.value = pos.lng.toFixed(7);
            });
        }
    }
}
