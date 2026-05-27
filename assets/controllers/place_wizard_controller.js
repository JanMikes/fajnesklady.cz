import { Controller } from '@hotwired/stimulus';

const GEO_CONSENT_KEY = 'fajnesklady.geolocation.consent';
const STEP_LABELS = ['Pobočka', 'Velikost', 'Objednávka'];

export default class extends Controller {
    static values = {
        places: { type: Array, default: [] },
    };

    static targets = ['modal', 'stepIndicator', 'stepContent', 'backButton'];

    connect() {
        this.currentStep = 1;
        this.selectedPlace = null;
        this.selectedType = null;
        this.userLocation = null;
        this.showAllPlaces = false;
        this.escapeHandler = null;

        this.maybeAutoLocate();
    }

    disconnect() {
        this.removeEscapeListener();
    }

    open() {
        this.currentStep = 1;
        this.selectedPlace = null;
        this.selectedType = null;
        this.showAllPlaces = false;

        this.render();
        this.modalTarget.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        this.addEscapeListener();
    }

    close() {
        this.modalTarget.classList.add('hidden');
        document.body.style.overflow = '';
        this.removeEscapeListener();
    }

    back() {
        if (this.currentStep > 1) {
            this.currentStep--;
            if (this.currentStep === 1) this.selectedPlace = null;
            if (this.currentStep <= 2) this.selectedType = null;
            this.render();
        }
    }

    selectPlace(event) {
        const placeId = event.currentTarget.dataset.placeId;
        this.selectedPlace = this.placesValue.find((p) => p.id === placeId);
        if (!this.selectedPlace) return;
        this.currentStep = 2;
        this.render();
    }

    selectType(event) {
        const typeId = event.currentTarget.dataset.typeId;
        if (!this.selectedPlace) return;
        this.selectedType = this.selectedPlace.storageTypes.find((t) => t.id === typeId);
        if (!this.selectedType || !this.selectedType.isAvailable) return;
        this.currentStep = 3;
        this.render();
    }

    requestGeo() {
        if (!navigator.geolocation) return;

        navigator.geolocation.getCurrentPosition(
            (pos) => {
                this.writeGeoConsent('granted');
                this.userLocation = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                this.showAllPlaces = false;
                this.render();
            },
            (err) => {
                if (err.code === err.PERMISSION_DENIED) {
                    this.writeGeoConsent('denied');
                }
                this.render();
            },
            { enableHighAccuracy: false, timeout: 10000 },
        );
    }

    expandAllPlaces() {
        this.showAllPlaces = true;
        this.render();
    }

    /* ------------------------------------------------------------------ rendering */

    render() {
        this.renderStepIndicator();
        this.renderBackButton();

        if (this.currentStep === 1) this.renderStep1();
        else if (this.currentStep === 2) this.renderStep2();
        else this.renderStep3();
    }

    renderStepIndicator() {
        const steps = STEP_LABELS.map((label, i) => {
            const num = i + 1;
            let circleClass = 'wizard-step__circle wizard-step__circle--future';
            let content = num;

            if (num < this.currentStep) {
                circleClass = 'wizard-step__circle wizard-step__circle--completed';
                content = '<svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>';
            } else if (num === this.currentStep) {
                circleClass = 'wizard-step__circle wizard-step__circle--current';
            }

            return `<div class="wizard-step">
                <div class="flex flex-col items-center">
                    <div class="${circleClass}">${content}</div>
                    <span class="wizard-step__label ${num === this.currentStep ? 'text-gray-900 font-semibold' : 'text-gray-400'}">${label}</span>
                </div>
            </div>`;
        });

        let html = '';
        for (let i = 0; i < steps.length; i++) {
            html += steps[i];
            if (i < steps.length - 1) {
                const lineClass = i + 1 < this.currentStep ? 'wizard-step__line--active' : 'wizard-step__line--inactive';
                html += `<div class="wizard-step__line ${lineClass}"></div>`;
            }
        }

        this.stepIndicatorTarget.innerHTML = html;
    }

    renderBackButton() {
        this.backButtonTarget.classList.toggle('hidden', this.currentStep === 1);
    }

    renderStep1() {
        let places = [...this.placesValue];
        let html = '<h3 class="text-lg font-bold text-gray-900 mb-4">Vyberte pobočku</h3>';

        const consent = this.readGeoConsent();
        if (!this.userLocation && consent !== 'denied' && navigator.geolocation) {
            html += `
                <div class="flex items-center gap-3 p-3 rounded-xl bg-blue-50 border border-blue-100 mb-4">
                    <svg class="h-5 w-5 text-blue-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm text-blue-800 font-medium">Povolte polohu pro zobrazení nejbližších poboček</p>
                    </div>
                    <button type="button"
                            class="btn btn-sm bg-blue-600 hover:bg-blue-700 text-white shrink-0"
                            data-action="place-wizard#requestGeo">
                        Povolit polohu
                    </button>
                </div>`;
        }

        if (this.userLocation) {
            places = places.map((p) => ({
                ...p,
                _distance: this.distanceKm(this.userLocation, { lat: p.latitude, lng: p.longitude }),
            }));
            places.sort((a, b) => a._distance - b._distance);

            if (!this.showAllPlaces && places.length > 3) {
                const topPlaces = places.slice(0, 3);
                html += this.renderPlaceCards(topPlaces);
                html += `
                    <button type="button"
                            class="w-full mt-3 text-sm text-primary hover:text-primary/80 font-medium py-2 transition-colors"
                            data-action="place-wizard#expandAllPlaces">
                        Zobrazit všechny pobočky (${places.length})
                    </button>`;
                this.stepContentTarget.innerHTML = html;
                return;
            }
        }

        html += this.renderPlaceCards(places);
        this.stepContentTarget.innerHTML = html;
    }

    renderPlaceCards(places) {
        if (places.length === 0) {
            return '<p class="text-gray-500 text-sm py-4 text-center">Žádné pobočky nejsou k dispozici.</p>';
        }

        return places
            .map((place) => {
                const badge = place.isAvailable
                    ? '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">K dispozici</span>'
                    : '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">Obsazeno</span>';

                const distanceBadge =
                    place._distance != null
                        ? `<span class="text-xs text-gray-400 ml-2">≈ ${place._distance < 10 ? place._distance.toFixed(1) : Math.round(place._distance)} km</span>`
                        : '';

                const priceText = place.lowestPrice != null ? `od ${this.formatPrice(place.lowestPrice)} Kč / měsíc` : '';
                const areaText = place.lowestAreaM2 != null ? `od ${place.lowestAreaM2} m²` : '';
                const meta = [priceText, areaText].filter(Boolean).join(' · ');

                return `
                    <button type="button"
                            class="wizard-card"
                            data-action="place-wizard#selectPlace"
                            data-place-id="${this.escapeAttr(place.id)}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="font-bold text-gray-900">${this.escapeHtml(place.name)}</div>
                                <div class="text-sm text-gray-500 mt-0.5">${this.escapeHtml(place.city)}${distanceBadge}</div>
                            </div>
                            <div class="shrink-0 mt-0.5">${badge}</div>
                        </div>
                        ${meta ? `<div class="text-sm text-gray-600 mt-2">${meta}</div>` : ''}
                    </button>`;
            })
            .join('');
    }

    renderStep2() {
        const place = this.selectedPlace;
        if (!place) return;

        const types = place.storageTypes ?? [];

        let html = `<h3 class="text-lg font-bold text-gray-900 mb-1">Zvolte velikost</h3>
            <p class="text-sm text-gray-500 mb-4">${this.escapeHtml(place.name)} · ${this.escapeHtml(place.city)}</p>`;

        if (types.length === 0) {
            html += '<p class="text-gray-500 text-sm py-4 text-center">Žádné skladovací jednotky nejsou k dispozici.</p>';
            this.stepContentTarget.innerHTML = html;
            return;
        }

        html += types
            .map((type) => {
                const isAvailable = type.isAvailable;
                const badge = isAvailable
                    ? '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">K dispozici</span>'
                    : '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">Obsazeno</span>';

                const attrs = isAvailable
                    ? `data-action="place-wizard#selectType" data-type-id="${this.escapeAttr(type.id)}"`
                    : 'disabled';

                return `
                    <button type="button" class="wizard-card" ${attrs}>
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="font-bold text-gray-900">${this.escapeHtml(type.name)}</div>
                                <div class="text-sm text-gray-500 mt-0.5">${this.escapeHtml(type.dimensions)} · ${type.floorAreaM2} m²</div>
                            </div>
                            <div class="shrink-0 mt-0.5">${badge}</div>
                        </div>
                        <div class="text-sm text-gray-600 mt-2 font-medium">od ${this.formatPrice(type.pricePerMonth)} Kč / měsíc</div>
                    </button>`;
            })
            .join('');

        this.stepContentTarget.innerHTML = html;
    }

    renderStep3() {
        const place = this.selectedPlace;
        const type = this.selectedType;
        if (!place || !type) return;

        this.stepContentTarget.innerHTML = `
            <h3 class="text-lg font-bold text-gray-900 mb-4">Objednejte online</h3>

            <div class="rounded-xl border border-gray-200 p-4 mb-5">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" style="background: linear-gradient(to bottom right, #e54545, #b82829)">
                        <svg class="h-5 w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    </div>
                    <div>
                        <div class="font-bold text-gray-900">${this.escapeHtml(place.name)}</div>
                        <div class="text-sm text-gray-500">${this.escapeHtml(place.city)}</div>
                    </div>
                </div>
                <div class="border-t border-gray-100 pt-3">
                    <div class="font-semibold text-gray-900">${this.escapeHtml(type.name)}</div>
                    <div class="text-sm text-gray-500 mt-0.5">${this.escapeHtml(type.dimensions)} · ${type.floorAreaM2} m²</div>
                    <div class="text-sm font-medium text-gray-800 mt-1">od ${this.formatPrice(type.pricePerMonth)} Kč / měsíc</div>
                </div>
            </div>

            <div class="mb-5">
                <p class="text-sm font-medium text-gray-700 mb-2">Co vás čeká:</p>
                <ul class="space-y-2">
                    <li class="flex items-center gap-2 text-sm text-gray-600">
                        <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Vyplníte kontaktní údaje
                    </li>
                    <li class="flex items-center gap-2 text-sm text-gray-600">
                        <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Podepíšete smlouvu online
                    </li>
                    <li class="flex items-center gap-2 text-sm text-gray-600">
                        <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Zaplatíte kartou nebo převodem
                    </li>
                </ul>
            </div>

            <a href="${this.escapeAttr(type.orderUrl)}"
               class="btn btn-primary btn-lg w-full justify-center">
                Přejít k objednávce
                <svg class="h-5 w-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
            </a>

            <div class="text-center mt-3">
                <a href="${this.escapeAttr(place.url)}" class="text-sm text-gray-500 hover:text-gray-700 transition-colors">
                    Zobrazit detail pobočky
                </a>
            </div>`;
    }

    /* ------------------------------------------------------------------ geolocation */

    async maybeAutoLocate() {
        const consent = this.readGeoConsent();
        if (consent !== 'granted' || !navigator.geolocation) return;

        if (navigator.permissions) {
            try {
                const status = await navigator.permissions.query({ name: 'geolocation' });
                if (status.state !== 'granted') return;
            } catch {
                return;
            }
        }

        navigator.geolocation.getCurrentPosition(
            (pos) => {
                this.userLocation = { lat: pos.coords.latitude, lng: pos.coords.longitude };
            },
            () => {},
            { timeout: 8000, maximumAge: 5 * 60 * 1000 },
        );
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
            /* private browsing — non-fatal */
        }
    }

    /* ------------------------------------------------------------------ keyboard */

    addEscapeListener() {
        this.escapeHandler = (e) => {
            if (e.key === 'Escape') this.close();
        };
        document.addEventListener('keydown', this.escapeHandler);
    }

    removeEscapeListener() {
        if (this.escapeHandler) {
            document.removeEventListener('keydown', this.escapeHandler);
            this.escapeHandler = null;
        }
    }

    /* ------------------------------------------------------------------ utils */

    distanceKm(a, b) {
        const R = 6371;
        const dLat = ((b.lat - a.lat) * Math.PI) / 180;
        const dLng = ((b.lng - a.lng) * Math.PI) / 180;
        const lat1 = (a.lat * Math.PI) / 180;
        const lat2 = (b.lat * Math.PI) / 180;
        const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
        return R * 2 * Math.asin(Math.sqrt(h));
    }

    formatPrice(price) {
        return new Intl.NumberFormat('cs-CZ', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
        }).format(price);
    }

    escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    escapeAttr(text) {
        if (text === null || text === undefined) return '';
        return String(text).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
}
