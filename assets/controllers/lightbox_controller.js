import { Controller } from '@hotwired/stimulus';
import GLightbox from 'glightbox';
import 'glightbox/dist/css/glightbox.min.css';

export default class extends Controller {
    connect() {
        this.lightbox = GLightbox({
            selector: '.glightbox',
            touchNavigation: true,
            loop: true,
        });
    }

    disconnect() {
        if (this.lightbox) {
            this.lightbox.destroy();
        }
    }
}
