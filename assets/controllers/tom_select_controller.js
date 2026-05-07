import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

export default class extends Controller {
    connect() {
        this.tomSelect = new TomSelect(this.element, {
            plugins: ['dropdown_input'],
            create: false,
            allowEmptyOption: true,
            maxOptions: 1000,
            sortField: null,
            searchField: ['text', 'optgroup'],
            onChange: () => {
                if (this.element.dataset.tomSelectAutosubmitValue === 'true') {
                    this.element.form?.requestSubmit();
                }
            },
        });

        this.forwardLabelToControl();
    }

    /**
     * TomSelect hides the native <select> and renders its own combobox UI, which
     * breaks the `<label for="…">` association — screen readers announce nothing
     * meaningful when the dropdown opens. Forward the label via aria-labelledby
     * so assistive tech can name the control.
     */
    forwardLabelToControl() {
        if (!this.element.id) {
            return;
        }

        const label = document.querySelector(`label[for="${this.element.id}"]`);
        if (label === null) {
            return;
        }

        if (label.id === '') {
            label.id = `label-${this.element.id}`;
        }

        const wrapper = this.tomSelect?.wrapper;
        const control = this.tomSelect?.control;
        const controlInput = this.tomSelect?.control_input;

        // The combobox role lives on the wrapper that the user clicks; the
        // search input inside the dropdown is a separate searchbox.
        if (wrapper instanceof HTMLElement) {
            wrapper.setAttribute('aria-labelledby', label.id);
        }
        if (control instanceof HTMLElement) {
            control.setAttribute('aria-labelledby', label.id);
        }
        if (controlInput instanceof HTMLElement) {
            controlInput.setAttribute('aria-labelledby', label.id);
        }
    }

    disconnect() {
        this.tomSelect?.destroy();
        this.tomSelect = null;
    }
}
