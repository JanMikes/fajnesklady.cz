import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';

const GEO_CONSENT_KEY = 'fajnesklady.geolocation.consent';
const DESKTOP_MIN_PX = 768;
const MOBILE_LIST_BREAKPOINT_PX = 1024;

export default class extends Controller {
    static values = {
        places: { type: Array, default: [] },
        center: { type: Array, default: [49.5, 17.5] },
        zoom: { type: Number, default: 7 },
    };

    static targets = [
        'mapContainer',
        'placeCard',
        'placeList',
        'geoButton',
        'modal',
        'modalBody',
        'bottomSheet',
        'bottomSheetList',
        'bottomSheetPill',
        'closestChip',
    ];

    connect() {
        this.markers = {};
        this.userLocation = null;
        this.userMarker = null;
        this.placesById = new Map((this.placesValue ?? []).map((p) => [p.id, p]));

        this.initializeMap();
        this.addStorageLocations();
        this.bindCardHoverEvents();
        this.bindPopupCloseDelegation();
        this.bindKeyboardEscape();
        this.populateBottomSheet();
        this.toggleBottomSheetPill();
        this.maybeAutoLocate();

        this.resizeHandler = () => {
            this.toggleBottomSheetPill();
            this.refreshClosestChip();
        };
        window.addEventListener('resize', this.resizeHandler);

        this.map.on('moveend zoomend', () => this.refreshClosestChip());
    }

    disconnect() {
        if (this.map) {
            this.map.remove();
        }
        if (this.resizeHandler) {
            window.removeEventListener('resize', this.resizeHandler);
        }
        if (this.escapeHandler) {
            document.removeEventListener('keydown', this.escapeHandler);
        }
        document.body.style.overflow = '';
    }

    /* ------------------------------------------------------------------ map setup */

    initializeMap() {
        const mapEl = this.hasMapContainerTarget ? this.mapContainerTarget : this.element;
        this.map = L.map(mapEl).setView(this.centerValue, this.zoomValue);

        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
            maxZoom: 20,
            subdomains: 'abcd',
        }).addTo(this.map);
    }

    addStorageLocations() {
        const places = this.placesValue;
        if (!places || places.length === 0) {
            return;
        }

        const bounds = L.latLngBounds();
        let hasValidCoordinates = false;

        places.forEach((place) => {
            if (!place.latitude || !place.longitude) {
                return;
            }

            const pinColor = place.typeColor || '#d23233';
            const icon = this.createIcon(place, false);

            const coords = [parseFloat(place.latitude), parseFloat(place.longitude)];
            const marker = L.marker(coords, { icon }).addTo(this.map);

            marker.bindPopup(this.createPopupContent(place), {
                maxWidth: 320,
                minWidth: 280,
                offset: L.point(0, 0), // recomputed per open (side-popover orientation)
                autoPanPadding: L.point(40, 40),
                className: 'side-popup',
            });

            this.markers[place.id] = { marker, pinColor, coords, place };

            marker.on('mouseover', () => this.highlightCard(place.id));
            marker.on('mouseout', () => this.unhighlightCard(place.id));
            marker.on('popupopen', () => this.highlightCard(place.id));
            marker.on('popupclose', () => this.unhighlightCard(place.id));

            marker.on('click', () => {
                if (this.isMobileViewport()) {
                    marker.closePopup();
                    this.openMobileModal(place);
                    return;
                }
                this.applySidePopoverOffset(marker);
            });

            bounds.extend(coords);
            hasValidCoordinates = true;
        });

        if (hasValidCoordinates && bounds.isValid()) {
            this.map.fitBounds(bounds, { padding: [50, 50], maxZoom: 12 });
        }
    }

    /* ------------------------------------------------------------------ pins */

    createIcon(place, highlighted) {
        const size = highlighted ? 42 : 32;
        const border = highlighted ? 4 : 3;
        const shadow = highlighted
            ? '0 0 0 6px rgba(210, 50, 51, 0.25), 0 2px 12px rgba(0, 0, 0, 0.4)'
            : '0 2px 8px rgba(0, 0, 0, 0.3)';
        const color = place.typeColor || '#d23233';
        const fadeStyles = place.isAvailable === false
            ? 'opacity: 0.45; filter: grayscale(0.8);'
            : '';

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
                ${fadeStyles}
            "></div>`,
            iconSize: [size, size],
            iconAnchor: [size / 2, size / 2],
        });
    }

    /* ------------------------------------------------------------------ cards ↔ markers */

    bindCardHoverEvents() {
        if (!this.hasPlaceCardTarget) return;

        this.placeCardTargets.forEach((card) => this.wireCardEvents(card));
    }

    wireCardEvents(card) {
        const placeId = card.dataset.placeId;
        card.addEventListener('mouseenter', () => this.highlightMarker(placeId));
        card.addEventListener('mouseleave', () => this.unhighlightMarker(placeId));
        card.addEventListener('click', (e) => {
            if (e.target.closest('a')) return; // let the wrapper <a> handle navigation
            e.preventDefault();
            this.closeBottomSheet();
            this.focusMarker(placeId);
        });
    }

    highlightMarker(placeId) {
        const data = this.markers[placeId];
        if (!data) return;
        data.marker.setIcon(this.createIcon(data.place, true));
        data.marker.setZIndexOffset(1000);
    }

    unhighlightMarker(placeId) {
        const data = this.markers[placeId];
        if (!data) return;
        data.marker.setIcon(this.createIcon(data.place, false));
        data.marker.setZIndexOffset(0);
    }

    highlightCard(placeId) {
        this.allCardNodes(placeId).forEach((card) => {
            card.classList.add('place-card--highlighted');
            if (card.dataset.clone !== 'true' && this.hasPlaceListTarget) {
                card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
    }

    unhighlightCard(placeId) {
        this.allCardNodes(placeId).forEach((card) => card.classList.remove('place-card--highlighted'));
    }

    allCardNodes(placeId) {
        const matches = [];
        if (this.hasPlaceCardTarget) {
            this.placeCardTargets.forEach((card) => {
                if (card.dataset.placeId === placeId) matches.push(card);
            });
        }
        if (this.hasBottomSheetListTarget) {
            this.bottomSheetListTarget
                .querySelectorAll(`[data-place-id="${placeId}"]`)
                .forEach((card) => matches.push(card));
        }
        return matches;
    }

    focusMarker(placeId) {
        const data = this.markers[placeId];
        if (!data) return;

        this.map.flyTo(data.coords, 13, { duration: 0.5 });

        if (this.isMobileViewport()) {
            this.openMobileModal(data.place);
            return;
        }
        this.applySidePopoverOffset(data.marker);
        data.marker.openPopup();
    }

    /* ------------------------------------------------------------------ side popover */

    applySidePopoverOffset(marker) {
        const popup = marker.getPopup();
        if (!popup) return;
        const flipLeft = this.shouldFlipPopupLeft(marker);
        const iconHalf = 16; // pin radius ~ size/2 from createIcon (32px non-highlighted)
        const padding = 8;
        const dx = (flipLeft ? -1 : 1) * (iconHalf + padding + 140); // 140 ≈ half popup max-width
        popup.options.offset = L.point(dx, 0);
    }

    shouldFlipPopupLeft(marker) {
        const mapSize = this.map.getSize();
        const pixel = this.map.latLngToContainerPoint(marker.getLatLng());
        const estimatedPopupWidth = 320;
        return pixel.x > (mapSize.x - estimatedPopupWidth - 24);
    }

    createPopupContent(place) {
        const availableTypes = (place.storageTypes ?? []).filter((t) => t.isAvailable);
        const badge = place.isAvailable
            ? '<span class="popup-badge popup-badge--available">K dispozici</span>'
            : '<span class="popup-badge popup-badge--sold-out">Aktuálně obsazeno</span>';

        let typesHtml = '';
        if (availableTypes.length > 0) {
            typesHtml = `
                <ul class="popup-types">
                    ${availableTypes.map((t) => `
                        <li>
                            <div class="popup-types__row">
                                <div class="popup-types__name">
                                    ${this.escapeHtml(t.name)}
                                    <span class="popup-types__dim">${this.escapeHtml(t.dimensions)}</span>
                                </div>
                                <div class="popup-types__price">${this.formatPrice(t.pricePerMonth)} Kč/měs</div>
                            </div>
                            <a href="${this.escapeHtml(t.orderUrl)}" class="popup-types__cta">Objednat</a>
                        </li>
                    `).join('')}
                </ul>`;
        } else {
            typesHtml = `
                <p class="popup-soldout">
                    Všechny skladovací jednotky jsou aktuálně obsazené.
                </p>`;
        }

        const address = place.address ? `${this.escapeHtml(place.address)}, ` : '';

        return `
            <div class="storage-popup">
                <header class="storage-popup__header">
                    <h3>${this.escapeHtml(place.name)}</h3>
                    <button type="button" class="storage-popup__close" aria-label="Zavřít">×</button>
                </header>
                <p class="storage-popup__address">${address}${this.escapeHtml(place.city)}</p>
                ${badge}
                ${typesHtml}
                <a href="${this.escapeHtml(place.url)}" class="storage-popup__detail">Zobrazit detail pobočky</a>
            </div>`;
    }

    bindPopupCloseDelegation() {
        this.element.addEventListener('click', (e) => {
            if (e.target instanceof Element && e.target.matches('.storage-popup__close')) {
                this.map.closePopup();
                this.closeMobileModal();
            }
        });
    }

    /* ------------------------------------------------------------------ mobile modal */

    isMobileViewport() {
        return window.matchMedia(`(max-width: ${DESKTOP_MIN_PX - 1}px)`).matches;
    }

    openMobileModal(place) {
        if (!this.hasModalTarget || !this.hasModalBodyTarget) return;
        this.modalBodyTarget.innerHTML = this.createPopupContent(place);
        this.modalTarget.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    closeMobileModal() {
        if (!this.hasModalTarget) return;
        if (this.modalTarget.hidden) return;
        this.modalTarget.hidden = true;
        document.body.style.overflow = '';
    }

    /* ------------------------------------------------------------------ bottom sheet */

    populateBottomSheet() {
        if (!this.hasBottomSheetListTarget || !this.hasPlaceCardTarget) return;
        const fragment = document.createDocumentFragment();
        this.placeCardTargets.forEach((card) => {
            const clone = card.cloneNode(true);
            // Strip the Stimulus target attribute — otherwise the clone is auto-registered
            // as another `placeCard` target, sortByDistance() then moves it into the desktop
            // list, and the visible cards double on first geolocation.
            clone.removeAttribute('data-map-target');
            clone.dataset.clone = 'true';
            this.wireCardEvents(clone);
            fragment.appendChild(clone);
        });
        this.bottomSheetListTarget.replaceChildren(fragment);
    }

    toggleBottomSheetPill() {
        if (!this.hasBottomSheetPillTarget) return;
        const mobile = window.matchMedia(`(max-width: ${MOBILE_LIST_BREAKPOINT_PX - 1}px)`).matches;
        this.bottomSheetPillTarget.hidden = !mobile;
    }

    openBottomSheet() {
        if (!this.hasBottomSheetTarget) return;
        this.bottomSheetTarget.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    closeBottomSheet() {
        if (!this.hasBottomSheetTarget) return;
        if (this.bottomSheetTarget.hidden) return;
        this.bottomSheetTarget.hidden = true;
        if (this.hasModalTarget && this.modalTarget.hidden) {
            document.body.style.overflow = '';
        }
    }

    /* ------------------------------------------------------------------ geolocation */

    maybeAutoLocate() {
        const consent = this.readGeoConsent();
        if (consent !== 'granted' || !navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(
            (pos) => this.applyUserLocation(pos.coords.latitude, pos.coords.longitude),
            () => { /* silent — consent may be revoked browser-side; do not surface an error */ },
            { timeout: 8000, maximumAge: 5 * 60 * 1000 },
        );
    }

    requestGeolocation() {
        if (!navigator.geolocation) {
            this.showGeoError('Vaše zařízení nepodporuje geolokaci.');
            return;
        }
        if (this.hasGeoButtonTarget) this.geoButtonTarget.disabled = true;
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                this.writeGeoConsent('granted');
                this.applyUserLocation(pos.coords.latitude, pos.coords.longitude);
            },
            (err) => {
                if (this.hasGeoButtonTarget) this.geoButtonTarget.disabled = false;
                if (err.code === err.PERMISSION_DENIED) {
                    this.writeGeoConsent('denied');
                }
                this.showGeoError('Polohu se nepodařilo zjistit. Zkuste to znovu nebo si vyberte pobočku ručně.');
            },
            { enableHighAccuracy: false, timeout: 10000 },
        );
    }

    applyUserLocation(lat, lng) {
        this.userLocation = { lat, lng };
        if (this.hasGeoButtonTarget) this.geoButtonTarget.hidden = true;
        this.addUserMarker();
        this.sortByDistance();
        this.flyToClosestIfOutsideViewport();
    }

    addUserMarker() {
        if (!this.userLocation) return;
        if (this.userMarker) this.userMarker.remove();
        this.userMarker = L.circleMarker([this.userLocation.lat, this.userLocation.lng], {
            radius: 6,
            color: '#2563eb',
            fillColor: '#3b82f6',
            fillOpacity: 0.9,
            weight: 2,
        }).addTo(this.map);
    }

    sortByDistance() {
        if (!this.userLocation || !this.hasPlaceListTarget) return;
        const here = this.userLocation;

        const withDistance = this.placeCardTargets.map((card) => {
            const placeId = card.dataset.placeId;
            const data = this.markers[placeId];
            const distance = data ? this.distanceKm(here, { lat: data.coords[0], lng: data.coords[1] }) : Infinity;
            return { card, distance };
        });

        withDistance.sort((a, b) => a.distance - b.distance);
        withDistance.forEach(({ card }) => this.placeListTarget.appendChild(card));

        this.populateBottomSheet();
    }

    flyToClosestIfOutsideViewport() {
        if (!this.userLocation) return;
        const closest = this.findClosestPlace();
        if (!closest) return;

        const targetBounds = L.latLngBounds(
            [this.userLocation.lat, this.userLocation.lng],
            closest.coords,
        );
        this.map.flyToBounds(targetBounds, { padding: [60, 60], maxZoom: 12, duration: 0.6 });
        // refreshClosestChip will run after moveend
    }

    flyToClosest() {
        const closest = this.findClosestPlace();
        if (!closest) return;
        this.map.flyTo(closest.coords, 13, { duration: 0.5 });
        if (this.isMobileViewport()) {
            this.openMobileModal(closest.place);
        } else {
            this.applySidePopoverOffset(closest.marker);
            closest.marker.openPopup();
        }
    }

    findClosestPlace() {
        if (!this.userLocation) return null;
        const here = this.userLocation;
        let best = null;
        Object.values(this.markers).forEach((entry) => {
            const km = this.distanceKm(here, { lat: entry.coords[0], lng: entry.coords[1] });
            if (best === null || km < best.km) {
                best = { ...entry, km };
            }
        });
        return best;
    }

    refreshClosestChip() {
        if (!this.hasClosestChipTarget) return;
        if (!this.userLocation) {
            this.closestChipTarget.hidden = true;
            return;
        }
        const bounds = this.map.getBounds();
        const anyVisible = Object.values(this.markers).some((entry) =>
            bounds.contains(L.latLng(entry.coords[0], entry.coords[1])),
        );
        const closest = this.findClosestPlace();
        if (anyVisible || !closest) {
            this.closestChipTarget.hidden = true;
            return;
        }
        const km = closest.km < 10 ? closest.km.toFixed(1) : Math.round(closest.km);
        this.closestChipTarget.hidden = false;
        this.closestChipTarget.textContent = `Nejbližší: ${closest.place.name} (≈ ${km} km)`;
    }

    distanceKm(a, b) {
        const R = 6371;
        const dLat = ((b.lat - a.lat) * Math.PI) / 180;
        const dLng = ((b.lng - a.lng) * Math.PI) / 180;
        const lat1 = (a.lat * Math.PI) / 180;
        const lat2 = (b.lat * Math.PI) / 180;
        const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
        return R * 2 * Math.asin(Math.sqrt(h));
    }

    readGeoConsent() {
        try {
            return localStorage.getItem(GEO_CONSENT_KEY);
        } catch {
            return null;
        }
    }

    writeGeoConsent(value) {
        try {
            localStorage.setItem(GEO_CONSENT_KEY, value);
        } catch {
            /* private browsing or quota — non-fatal, fall back to per-session geo */
        }
    }

    showGeoError(message) {
        // Lightweight, non-blocking — surfaces above the map for a few seconds.
        const toast = document.createElement('div');
        toast.className = 'closest-chip';
        toast.style.left = '50%';
        toast.style.right = 'auto';
        toast.style.transform = 'translateX(-50%)';
        toast.textContent = message;
        const container = this.hasMapContainerTarget ? this.mapContainerTarget.parentElement : this.element;
        container.appendChild(toast);
        setTimeout(() => toast.remove(), 4000);
    }

    /* ------------------------------------------------------------------ keyboard / utils */

    bindKeyboardEscape() {
        this.escapeHandler = (e) => {
            if (e.key !== 'Escape') return;
            this.closeMobileModal();
            this.closeBottomSheet();
            this.map.closePopup();
        };
        document.addEventListener('keydown', this.escapeHandler);
    }

    escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    formatPrice(price) {
        return new Intl.NumberFormat('cs-CZ', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
        }).format(price);
    }
}
