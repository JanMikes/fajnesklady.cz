import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'showIcon', 'hideIcon'];

    connect() {
        this.update(false);
    }

    toggle() {
        const isHidden = this.inputTarget.type === 'password';
        this.update(isHidden);
    }

    update(visible) {
        this.inputTarget.type = visible ? 'text' : 'password';
        this.showIconTarget.classList.toggle('hidden', visible);
        this.hideIconTarget.classList.toggle('hidden', !visible);
        this.element.querySelector('button')?.setAttribute(
            'aria-label',
            visible ? 'Skrýt heslo' : 'Zobrazit heslo',
        );
    }
}
