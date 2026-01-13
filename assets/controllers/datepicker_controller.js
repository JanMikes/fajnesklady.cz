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
            allowInput: true,
            disableMobile: true
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
}
