import { Controller } from '@hotwired/stimulus';
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.min.css';
import { Czech } from 'flatpickr/dist/l10n/cs.js';

export default class extends Controller {
    static values = {
        minDate: { type: String, default: '' },
        maxDate: { type: String, default: '' },
        defaultDate: { type: String, default: '' }
    }

    connect() {
        this.initializeDatepicker();
    }

    disconnect() {
        if (this.picker) {
            this.picker.destroy();
        }
    }

    initializeDatepicker() {
        const config = {
            locale: Czech,
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'j. n. Y',
            // Match the project's base input styling so .form-group:has(> .form-error)
            // CSS can apply error styling consistently across regular inputs and the
            // visible flatpickr alt-input.
            altInputClass: 'form-input',
            allowInput: true,
            disableMobile: true,
            // Lenient parser: accept "6.6.1992", "6. 6. 1992", "6/6/1992", "6-6-1992",
            // any spacing variant, plus the ISO Y-m-d that flatpickr itself uses for
            // minDate/maxDate/defaultDate and the hidden input value.
            parseDate: this.parseDate
        };

        if (this.minDateValue) {
            config.minDate = this.minDateValue;
        }

        if (this.maxDateValue) {
            config.maxDate = this.maxDateValue;
        }

        if (this.defaultDateValue) {
            config.defaultDate = this.defaultDateValue;
        }

        this.picker = flatpickr(this.element, config);
    }

    parseDate(dateString) {
        if (!dateString) {
            return undefined;
        }

        if (dateString instanceof Date) {
            return isNaN(dateString.getTime()) ? undefined : dateString;
        }

        const trimmed = String(dateString).trim();
        if (!trimmed) {
            return undefined;
        }

        // ISO Y-m-d — flatpickr uses this for the hidden input value and for
        // minDate/maxDate/defaultDate configured via data-* attributes.
        let m = trimmed.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
        if (m) {
            return makeDate(Number(m[1]), Number(m[2]), Number(m[3]));
        }

        // Czech-style d.m.Y / d/m/Y / d-m-Y with any whitespace around separators.
        const compact = trimmed.replace(/\s+/g, '');

        m = compact.match(/^(\d{1,2})[./-](\d{1,2})[./-](\d{4})$/);
        if (m) {
            return makeDate(Number(m[3]), Number(m[2]), Number(m[1]));
        }

        // Two-digit year (e.g. 6.6.92): pivot at 30 → 2030 vs 1930.
        m = compact.match(/^(\d{1,2})[./-](\d{1,2})[./-](\d{2})$/);
        if (m) {
            const yy = Number(m[3]);
            const year = yy < 30 ? 2000 + yy : 1900 + yy;
            return makeDate(year, Number(m[2]), Number(m[1]));
        }

        return undefined;
    }
}

function makeDate(year, month, day) {
    const d = new Date(year, month - 1, day);
    if (isNaN(d.getTime())) {
        return undefined;
    }
    // Reject impossible dates that JS silently rolls over (e.g. 31.2. → 3.3.).
    if (d.getFullYear() !== year || d.getMonth() !== month - 1 || d.getDate() !== day) {
        return undefined;
    }
    return d;
}
