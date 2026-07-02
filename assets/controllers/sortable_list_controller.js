import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/*
 * Drag-and-drop reordering of a list (e.g. table rows). Each sortable item
 * carries data-sortable-id; on drop the new order is POSTed as {ids: [...]}
 * to the configured URL. On a failed save the page reloads so the UI never
 * shows an order the server did not accept.
 */
export default class extends Controller {
    static values = { url: String };

    connect() {
        this.sortable = Sortable.create(this.element, {
            animation: 150,
            handle: '[data-sortable-handle]',
            draggable: '[data-sortable-id]',
            onEnd: () => this.save(),
        });
    }

    disconnect() {
        this.sortable?.destroy();
        this.sortable = null;
    }

    async save() {
        const ids = Array.from(this.element.querySelectorAll('[data-sortable-id]'))
            .map((item) => item.dataset.sortableId);

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ids }),
            });

            if (!response.ok) {
                throw new Error(`Reorder failed with status ${response.status}`);
            }
        } catch (error) {
            console.error(error);
            window.location.reload();
        }
    }
}
