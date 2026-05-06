import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input'];
    static values = { url: String };

    async generate(event) {
        event.preventDefault();
        if (!this.urlValue) return;

        try {
            const response = await fetch(this.urlValue, { method: 'POST' });
            const result = await response.json();

            if (!response.ok) {
                alert(result.message || 'Nepodařilo se vygenerovat kód.');
                return;
            }

            this.inputTarget.value = result.code;
            this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
            this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (err) {
            console.error('Generate code error:', err);
            alert('Nepodařilo se vygenerovat kód.');
        }
    }
}
