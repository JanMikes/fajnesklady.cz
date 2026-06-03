import { Controller } from '@hotwired/stimulus';

const DEBOUNCE_MS = 250;
const BLUR_HIDE_MS = 150;
const MIN_QUERY_LENGTH = 3;

/**
 * Address entry as a single Photon-backed search box.
 *
 * The user types into ONE search field (`searchInput`); picking a suggestion fills
 * and reveals the three real fields (street / city / PSČ) for verification. "Zadat
 * ručně" reveals the same fields empty for manual entry. The three fields always
 * live in the DOM and are submitted with the form — only their visibility toggles.
 *
 * Why a dedicated search box instead of autocompleting the three fields directly
 * (the old design): the real fields carried WHATWG autofill tokens, so the browser
 * popped its own address dropdown on top of ours. And filling them programmatically
 * re-fired the `input` listener that lived on those same fields, re-querying Photon
 * after every selection. Here the search box owns the dropdown; the real fields have
 * no suggest listener, so neither problem can happen.
 *
 * Visibility follows `mode`: 'auto' reveals the fields iff they hold a value (the
 * default, and the state after a pick), while an explicit 'manual'/'search' choice
 * overrides that without touching the field values. The server renders the value-
 * derived ('auto') condition, so `applyMode()` re-asserts after every morph
 * (live:render:finished) for the cases where an explicit choice disagrees with the
 * field values.
 */
export default class extends Controller {
    static targets = [
        'searchInput',
        'searchSection',
        'manualSection',
        'spinner',
        'statusText',
        'streetInput',
        'cityInput',
        'postalCodeInput',
        'overrideCheckbox',
        'overrideContainer',
    ];

    connect() {
        this.dropdown = null;
        this.suggestions = [];
        this.activeIndex = -1;
        this.debounceHandle = null;
        this.blurHandle = null;
        this.abortController = null;
        this.cache = new Map(); // query -> suggestions, dedupes keystrokes within a page view
        this.lastQuery = null;
        // 'auto'   -> reveal the fields iff they hold a value (default / after a pick)
        // 'manual' -> user chose "Zadat ručně": keep fields revealed even when empty
        // 'search' -> user chose "Vyhledat adresu": keep the search box even when fields are filled
        this.mode = 'auto';

        this.boundOnSearchInput = () => this.onSearchInput();
        this.boundOnSearchKeydown = (event) => this.onSearchKeydown(event);
        this.boundOnSearchBlur = () => this.onSearchBlur();
        this.boundOnSearchFocus = () => this.onSearchFocus();
        this.boundOnAddressEdit = (event) => this.onAddressEdit(event);
        this.boundOnDocumentClick = (event) => this.onDocumentClick(event);
        this.boundOnLiveRender = () => this.applyMode();

        if (this.hasSearchInputTarget) {
            this.searchInputTarget.addEventListener('input', this.boundOnSearchInput);
            this.searchInputTarget.addEventListener('keydown', this.boundOnSearchKeydown);
            this.searchInputTarget.addEventListener('blur', this.boundOnSearchBlur);
            this.searchInputTarget.addEventListener('focus', this.boundOnSearchFocus);
        }

        for (const input of this.addressInputs()) {
            input.addEventListener('input', this.boundOnAddressEdit);
        }

        document.addEventListener('click', this.boundOnDocumentClick);
        // Live Components re-render the macro from server (value-derived) state on
        // every morph; it can't know about an explicit search/manual choice that
        // disagrees with the field values, so re-assert the mode after each render.
        document.addEventListener('live:render:finished', this.boundOnLiveRender);

        this.applyMode();
    }

    disconnect() {
        if (this.hasSearchInputTarget) {
            this.searchInputTarget.removeEventListener('input', this.boundOnSearchInput);
            this.searchInputTarget.removeEventListener('keydown', this.boundOnSearchKeydown);
            this.searchInputTarget.removeEventListener('blur', this.boundOnSearchBlur);
            this.searchInputTarget.removeEventListener('focus', this.boundOnSearchFocus);
        }
        for (const input of this.addressInputs()) {
            input.removeEventListener('input', this.boundOnAddressEdit);
        }
        document.removeEventListener('click', this.boundOnDocumentClick);
        document.removeEventListener('live:render:finished', this.boundOnLiveRender);

        if (this.debounceHandle) clearTimeout(this.debounceHandle);
        if (this.blurHandle) clearTimeout(this.blurHandle);
        if (this.abortController) this.abortController.abort();
        this.removeDropdown();
    }

    addressInputs() {
        const inputs = [];
        if (this.hasStreetInputTarget) inputs.push(this.streetInputTarget);
        if (this.hasCityInputTarget) inputs.push(this.cityInputTarget);
        if (this.hasPostalCodeInputTarget) inputs.push(this.postalCodeInputTarget);
        return inputs;
    }

    // --- Reveal state (search vs. the three fields) -------------------------

    hasAnyAddressValue() {
        return this.addressInputs().some((input) => (input.value ?? '').trim() !== '');
    }

    applyMode() {
        let reveal;
        if (this.mode === 'search') {
            reveal = false;
        } else if (this.mode === 'manual') {
            reveal = true;
        } else {
            reveal = this.hasAnyAddressValue();
        }
        if (this.hasSearchSectionTarget) this.searchSectionTarget.classList.toggle('hidden', reveal);
        if (this.hasManualSectionTarget) this.manualSectionTarget.classList.toggle('hidden', !reveal);
    }

    showManual(event) {
        if (event) event.preventDefault();
        this.mode = 'manual';
        this.cancelSearch();
        this.applyMode();
        if (this.hasStreetInputTarget) this.streetInputTarget.focus();
    }

    showSearch(event) {
        if (event) event.preventDefault();
        // Show the search box WITHOUT touching the field values — the user may be
        // looking up a replacement, or may keep what's there. The explicit 'search'
        // mode keeps this sticky across morphs (applyMode re-asserts on
        // live:render:finished), so the fields don't need clearing to stay hidden.
        this.mode = 'search';
        this.setStatus('');
        this.applyMode();
        if (this.hasSearchInputTarget) {
            this.searchInputTarget.value = '';
            this.searchInputTarget.focus();
        }
    }

    // --- Search / suggest ---------------------------------------------------

    onSearchFocus() {
        this.cancelHide();
        const query = (this.searchInputTarget.value ?? '').trim();
        if (query.length >= MIN_QUERY_LENGTH && this.suggestions.length > 0) {
            this.renderDropdown(this.suggestions);
        }
    }

    onSearchInput() {
        const query = (this.searchInputTarget.value ?? '').trim();
        if (this.debounceHandle) clearTimeout(this.debounceHandle);

        if (query.length < MIN_QUERY_LENGTH) {
            this.hideSpinner();
            this.removeDropdown();
            return;
        }

        if (this.cache.has(query)) {
            this.hideSpinner();
            this.renderDropdown(this.cache.get(query));
            return;
        }

        // Show the spinner up front (before the debounce + network) so the user has
        // immediate feedback that something is happening — the missing-feedback gap
        // was the "it feels broken, then a panel pops in a second later" complaint.
        this.showSpinner();
        this.debounceHandle = setTimeout(() => this.fetchSuggestions(query), DEBOUNCE_MS);
    }

    async fetchSuggestions(query) {
        this.lastQuery = query;

        if (this.cache.has(query)) {
            this.hideSpinner();
            this.renderDropdown(this.cache.get(query));
            return;
        }

        // Cancel any in-flight request so a slow earlier response can't arrive late
        // and re-open the dropdown after the user has already moved on / selected.
        if (this.abortController) this.abortController.abort();
        this.abortController = new AbortController();

        try {
            const response = await fetch(`/api/address/suggest?q=${encodeURIComponent(query)}`, {
                headers: { Accept: 'application/json' },
                signal: this.abortController.signal,
            });
            if (!response.ok) {
                this.hideSpinner();
                this.removeDropdown();
                return;
            }
            const data = await response.json();
            const suggestions = Array.isArray(data.suggestions) ? data.suggestions : [];
            this.cache.set(query, suggestions);

            // The user may have kept typing while this was in flight — ignore a stale
            // result that no longer matches the box.
            if (query !== (this.searchInputTarget.value ?? '').trim()) {
                this.hideSpinner();
                return;
            }

            this.hideSpinner();
            this.renderDropdown(suggestions);
        } catch (error) {
            if (error.name === 'AbortError') return; // superseded by a newer query/selection
            this.hideSpinner();
            this.removeDropdown();
        }
    }

    renderDropdown(suggestions) {
        this.suggestions = suggestions;
        this.activeIndex = -1;
        this.ensureDropdown();
        if (!this.dropdown) return;

        this.dropdown.innerHTML = '';

        if (suggestions.length === 0) {
            const li = document.createElement('li');
            li.className = 'px-3 py-2 text-gray-500';
            li.append('Nenašli jsme žádnou adresu. ');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'text-accent hover:underline font-medium';
            button.textContent = 'Zadat ručně';
            button.addEventListener('mousedown', (event) => {
                event.preventDefault();
                this.showManual();
            });
            li.appendChild(button);
            this.dropdown.appendChild(li);
            this.setSearchExpanded(true);
            return;
        }

        suggestions.forEach((suggestion, index) => {
            const li = document.createElement('li');
            li.id = `${this.dropdown.id}-opt-${index}`;
            li.className = 'px-3 py-2 cursor-pointer hover:bg-accent/10';
            li.setAttribute('role', 'option');
            li.textContent = suggestion.displayLabel;
            li.addEventListener('mousedown', (event) => {
                event.preventDefault();
                this.applySuggestion(index);
            });
            this.dropdown.appendChild(li);
        });
        this.setSearchExpanded(true);
    }

    ensureDropdown() {
        if (this.dropdown || !this.hasSearchInputTarget) return;
        this.dropdown = document.createElement('ul');
        this.dropdown.id = `${this.searchInputTarget.id}-listbox`;
        this.dropdown.className = 'absolute z-20 mt-1 w-full bg-white border border-gray-200 rounded-md shadow-lg max-h-72 overflow-auto text-sm';
        // The search UI sits inside a data-live-ignore wrapper, but mark the dropdown
        // too so a morph that does reach it can't strip an open list mid-interaction.
        this.dropdown.setAttribute('data-live-ignore', '');
        this.dropdown.setAttribute('role', 'listbox');
        this.searchInputTarget.parentElement.appendChild(this.dropdown);
    }

    removeDropdown() {
        if (this.dropdown && this.dropdown.parentNode) {
            this.dropdown.parentNode.removeChild(this.dropdown);
        }
        this.dropdown = null;
        this.activeIndex = -1;
        this.setSearchExpanded(false);
        if (this.hasSearchInputTarget) this.searchInputTarget.removeAttribute('aria-activedescendant');
    }

    applySuggestion(index) {
        const suggestion = this.suggestions[index];
        if (!suggestion) return;

        if (this.abortController) this.abortController.abort();
        if (this.debounceHandle) clearTimeout(this.debounceHandle);

        const fullStreet = [suggestion.street, suggestion.houseNumber].filter(Boolean).join(' ').trim();
        this.setFieldValue(this.streetInputTarget, fullStreet);
        if (this.hasCityInputTarget) this.setFieldValue(this.cityInputTarget, suggestion.city);
        if (this.hasPostalCodeInputTarget) this.setFieldValue(this.postalCodeInputTarget, this.formatPostalCode(suggestion.postalCode));

        // The address came from Photon, so any prior soft-warn override no longer applies.
        if (this.hasOverrideCheckboxTarget && this.overrideCheckboxTarget.checked) {
            this.overrideCheckboxTarget.checked = false;
            this.overrideCheckboxTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }

        this.setStatus('Adresa vyplněna z vyhledávání');
        this.suggestions = [];
        this.lastQuery = null;
        this.removeDropdown();
        this.hideSpinner();
        if (this.hasSearchInputTarget) {
            this.searchInputTarget.value = '';
            this.searchInputTarget.blur();
        }
        // Back to value-driven reveal: the fields now hold the picked address.
        this.mode = 'auto';
        this.applyMode();
    }

    onAddressEdit(event) {
        // A real (user-typed) edit to any field invalidates a soft-warn override —
        // the address changed, so the customer must re-confirm. Synthetic events
        // from setFieldValue() are isTrusted=false and skip this.
        if (this.hasOverrideCheckboxTarget && event.isTrusted && this.overrideCheckboxTarget.checked) {
            this.overrideCheckboxTarget.checked = false;
            this.overrideCheckboxTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    // --- Keyboard navigation ------------------------------------------------

    onSearchKeydown(event) {
        if (!this.dropdown || this.suggestions.length === 0) return;

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
        this.activeIndex = (this.activeIndex + delta + this.suggestions.length) % this.suggestions.length;
        const items = this.dropdown.querySelectorAll('li[role="option"]');
        items.forEach((item, index) => item.classList.toggle('bg-accent/10', index === this.activeIndex));
        const active = items[this.activeIndex];
        if (active && this.hasSearchInputTarget) {
            this.searchInputTarget.setAttribute('aria-activedescendant', active.id);
            active.scrollIntoView({ block: 'nearest' });
        }
    }

    // --- Dropdown lifecycle helpers ----------------------------------------

    onSearchBlur() {
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
        if (!this.element.contains(event.target)) this.removeDropdown();
    }

    cancelSearch() {
        if (this.debounceHandle) clearTimeout(this.debounceHandle);
        if (this.abortController) this.abortController.abort();
        this.hideSpinner();
        this.removeDropdown();
        this.suggestions = [];
    }

    // --- Small DOM helpers --------------------------------------------------

    setFieldValue(input, value) {
        if (!input) return;
        input.value = value;
        // Dispatch synthetic events so Live Components re-sync the model. The address
        // fields have no suggest listener, so this can't retrigger a fetch.
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    formatPostalCode(postalCode) {
        const digits = (postalCode ?? '').replace(/\s+/g, '');
        return digits.length === 5 ? `${digits.slice(0, 3)} ${digits.slice(3)}` : (postalCode ?? '');
    }

    setStatus(text) {
        if (this.hasStatusTextTarget) this.statusTextTarget.textContent = text;
    }

    showSpinner() {
        if (this.hasSpinnerTarget) this.spinnerTarget.classList.remove('hidden');
        if (this.hasSearchInputTarget) this.searchInputTarget.setAttribute('aria-busy', 'true');
    }

    hideSpinner() {
        if (this.hasSpinnerTarget) this.spinnerTarget.classList.add('hidden');
        if (this.hasSearchInputTarget) this.searchInputTarget.removeAttribute('aria-busy');
    }

    setSearchExpanded(expanded) {
        if (this.hasSearchInputTarget) this.searchInputTarget.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }
}
