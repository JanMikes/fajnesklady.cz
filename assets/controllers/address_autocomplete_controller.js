import { Controller } from '@hotwired/stimulus';

const DEBOUNCE_MS = 250;
const BLUR_HIDE_MS = 150;

export default class extends Controller {
    static targets = ['streetInput', 'cityInput', 'postalCodeInput', 'overrideCheckbox', 'overrideContainer'];

    connect() {
        this.dropdown = null;
        this.suggestions = [];
        this.activeIndex = -1;
        this.debounceHandle = null;
        this.blurHandle = null;

        this.boundOnInput = (event) => this.onInput(event);
        this.boundOnKeydown = (event) => this.onKeydown(event);
        this.boundOnBlur = () => this.onBlur();
        this.boundOnFocus = () => this.cancelHide();
        this.boundOnAddressEdit = (event) => this.onAddressEdit(event);
        this.boundOnDocumentClick = (event) => this.onDocumentClick(event);

        if (this.hasStreetInputTarget) {
            this.streetInputTarget.addEventListener('input', this.boundOnInput);
            this.streetInputTarget.addEventListener('keydown', this.boundOnKeydown);
            this.streetInputTarget.addEventListener('blur', this.boundOnBlur);
            this.streetInputTarget.addEventListener('focus', this.boundOnFocus);
            this.streetInputTarget.setAttribute('autocomplete', 'street-address');
        }

        for (const input of this.editableInputs()) {
            input.addEventListener('input', this.boundOnAddressEdit);
        }

        document.addEventListener('click', this.boundOnDocumentClick);
    }

    disconnect() {
        if (this.hasStreetInputTarget) {
            this.streetInputTarget.removeEventListener('input', this.boundOnInput);
            this.streetInputTarget.removeEventListener('keydown', this.boundOnKeydown);
            this.streetInputTarget.removeEventListener('blur', this.boundOnBlur);
            this.streetInputTarget.removeEventListener('focus', this.boundOnFocus);
        }
        for (const input of this.editableInputs()) {
            input.removeEventListener('input', this.boundOnAddressEdit);
        }
        document.removeEventListener('click', this.boundOnDocumentClick);

        if (this.debounceHandle) clearTimeout(this.debounceHandle);
        if (this.blurHandle) clearTimeout(this.blurHandle);
        this.removeDropdown();
    }

    editableInputs() {
        const inputs = [];
        if (this.hasStreetInputTarget) inputs.push(this.streetInputTarget);
        if (this.hasCityInputTarget) inputs.push(this.cityInputTarget);
        if (this.hasPostalCodeInputTarget) inputs.push(this.postalCodeInputTarget);
        return inputs;
    }

    onInput(event) {
        const query = (event.target.value ?? '').trim();
        if (this.debounceHandle) clearTimeout(this.debounceHandle);

        if (query.length < 3) {
            this.removeDropdown();
            return;
        }

        this.debounceHandle = setTimeout(() => this.fetchSuggestions(query), DEBOUNCE_MS);
    }

    onAddressEdit(event) {
        // Manual edit to any of the three address inputs invalidates a prior
        // override — the address has changed, the user must re-confirm.
        if (this.hasOverrideCheckboxTarget && event.isTrusted && this.overrideCheckboxTarget.checked) {
            this.overrideCheckboxTarget.checked = false;
            this.overrideCheckboxTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    onBlur() {
        // Slight delay so click events on dropdown items still fire.
        if (this.blurHandle) clearTimeout(this.blurHandle);
        this.blurHandle = setTimeout(() => this.removeDropdown(), BLUR_HIDE_MS);
    }

    cancelHide() {
        if (this.blurHandle) {
            clearTimeout(this.blurHandle);
            this.blurHandle = null;
        }
    }

    onDocumentClick(event) {
        if (!this.element.contains(event.target)) {
            this.removeDropdown();
        }
    }

    async fetchSuggestions(query) {
        try {
            const response = await fetch(`/api/address/suggest?q=${encodeURIComponent(query)}`, {
                headers: { 'Accept': 'application/json' },
            });
            if (!response.ok) {
                this.removeDropdown();
                return;
            }
            const data = await response.json();
            const suggestions = Array.isArray(data.suggestions) ? data.suggestions : [];
            this.renderDropdown(suggestions);
        } catch (e) {
            this.removeDropdown();
        }
    }

    renderDropdown(suggestions) {
        this.suggestions = suggestions;
        this.activeIndex = -1;

        if (suggestions.length === 0) {
            this.removeDropdown();
            return;
        }

        if (!this.dropdown) {
            this.dropdown = document.createElement('ul');
            this.dropdown.className = 'absolute z-20 mt-1 w-full bg-white border border-gray-200 rounded-md shadow-lg max-h-72 overflow-auto text-sm';
            this.dropdown.setAttribute('data-live-ignore', '');
            this.dropdown.setAttribute('role', 'listbox');

            // Position relative to the street input.
            const wrapper = this.streetInputTarget.parentElement;
            wrapper.style.position = 'relative';
            wrapper.appendChild(this.dropdown);
        }

        this.dropdown.innerHTML = '';
        suggestions.forEach((suggestion, index) => {
            const li = document.createElement('li');
            li.className = 'px-3 py-2 cursor-pointer hover:bg-accent/10';
            li.textContent = suggestion.displayLabel;
            li.setAttribute('role', 'option');
            li.addEventListener('mousedown', (event) => {
                event.preventDefault();
                this.applySuggestion(index);
            });
            this.dropdown.appendChild(li);
        });
    }

    removeDropdown() {
        if (this.dropdown && this.dropdown.parentNode) {
            this.dropdown.parentNode.removeChild(this.dropdown);
        }
        this.dropdown = null;
        this.suggestions = [];
        this.activeIndex = -1;
    }

    onKeydown(event) {
        if (!this.dropdown || this.suggestions.length === 0) {
            return;
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            this.moveActive(1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            this.moveActive(-1);
        } else if (event.key === 'Enter') {
            if (this.activeIndex >= 0) {
                event.preventDefault();
                this.applySuggestion(this.activeIndex);
            }
        } else if (event.key === 'Escape') {
            event.preventDefault();
            this.removeDropdown();
        }
    }

    moveActive(delta) {
        if (this.suggestions.length === 0) return;
        this.activeIndex = (this.activeIndex + delta + this.suggestions.length) % this.suggestions.length;
        const items = this.dropdown.querySelectorAll('li');
        items.forEach((item, index) => {
            if (index === this.activeIndex) {
                item.classList.add('bg-accent/10');
            } else {
                item.classList.remove('bg-accent/10');
            }
        });
    }

    applySuggestion(index) {
        const suggestion = this.suggestions[index];
        if (!suggestion) return;

        const fullStreet = [suggestion.street, suggestion.houseNumber].filter(Boolean).join(' ').trim();
        this.setInputValue(this.streetInputTarget, fullStreet);

        if (this.hasCityInputTarget) {
            this.setInputValue(this.cityInputTarget, suggestion.city);
        }
        if (this.hasPostalCodeInputTarget) {
            this.setInputValue(this.postalCodeInputTarget, suggestion.postalCode);
        }

        if (this.hasOverrideCheckboxTarget && this.overrideCheckboxTarget.checked) {
            this.overrideCheckboxTarget.checked = false;
            this.overrideCheckboxTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }

        this.removeDropdown();
    }

    setInputValue(input, value) {
        if (!input) return;
        input.value = value;
        // Dispatch synthetic events so Live Components re-sync.
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }
}
