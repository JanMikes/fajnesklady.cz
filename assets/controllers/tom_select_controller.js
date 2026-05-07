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
    }

    disconnect() {
        this.tomSelect?.destroy();
        this.tomSelect = null;
    }
}
