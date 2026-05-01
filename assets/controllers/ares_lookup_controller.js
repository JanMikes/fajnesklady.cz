import { Controller } from '@hotwired/stimulus';

const FIELD_NAMES = ['companyName', 'companyVatId', 'billingStreet', 'billingCity', 'billingPostalCode'];

export default class extends Controller {
    static targets = ['button', 'status'];

    connect() {
        this.loading = false;
        this.companyIdInput = this.element.querySelector('input[name$="[companyId]"]');
        this.boundUpdateButtonState = () => this.updateButtonState();
        this.companyIdInput?.addEventListener('input', this.boundUpdateButtonState);
        this.updateButtonState();
    }

    disconnect() {
        this.companyIdInput?.removeEventListener('input', this.boundUpdateButtonState);
    }

    updateButtonState() {
        const value = (this.companyIdInput?.value ?? '').trim();
        const valid = /^\d{8}$/.test(value);
        if (this.hasButtonTarget) {
            this.buttonTarget.disabled = !valid || this.loading;
        }
    }

    async lookup() {
        if (!this.companyIdInput) return;

        const ico = this.companyIdInput.value.trim();
        if (!/^\d{8}$/.test(ico)) {
            this.setStatus('not_found', 'IČO musí mít přesně 8 číslic');
            return;
        }

        this.setStatus('loading', 'Načítání údajů z ARES…');
        this.loading = true;
        this.updateButtonState();

        try {
            const response = await fetch(`/api/ares/${encodeURIComponent(ico)}`, {
                headers: { 'Accept': 'application/json' },
            });

            if (response.status === 200) {
                const data = await response.json();
                this.applyData(data);
                this.setStatus('success', 'Údaje načteny z ARES');
                return;
            }

            if (response.status === 404) {
                this.setStatus('not_found', 'IČO nebylo v ARES nalezeno');
                return;
            }

            if (response.status === 422) {
                this.setStatus('not_found', 'IČO musí mít přesně 8 číslic');
                return;
            }

            if (response.status === 429) {
                this.setStatus('error', 'Načítání údajů z ARES selhalo, zkuste to prosím za chvíli');
                return;
            }

            this.setStatus('error', 'Načítání údajů z ARES selhalo, zkuste to prosím později');
        } catch (e) {
            this.setStatus('error', 'Načítání údajů z ARES selhalo, zkuste to prosím později');
        } finally {
            this.loading = false;
            this.updateButtonState();
        }
    }

    applyData(data) {
        for (const field of FIELD_NAMES) {
            const value = data[field];
            // ARES sometimes returns null for VAT ID — leave the existing input alone in that case
            if (value === null || value === undefined || value === '') continue;
            const input = this.element.querySelector(`input[name$="[${field}]"]`);
            if (input) {
                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    }

    setStatus(kind, message) {
        if (!this.hasStatusTarget) return;
        const colors = {
            loading: 'text-gray-600',
            success: 'text-green-700',
            not_found: 'text-amber-700',
            error: 'text-red-700',
        };
        this.statusTarget.className = `text-sm mt-1 ${colors[kind] ?? ''}`;
        this.statusTarget.textContent = message;
    }
}
