import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'type',
        'amount',
        'nonReturnDays',
        'nonReturnDaysWrapper',
        'latePaymentBase',
        'latePaymentDays',
        'latePaymentWrapper',
        'amountDisplay',
    ];

    typeChanged() {
        const type = this.typeTarget.value;

        // Hide all aux fields first
        this.nonReturnDaysWrapperTarget.classList.add('hidden');
        this.latePaymentWrapperTarget.classList.add('hidden');

        switch (type) {
            case 'dirty_storage':
                this.amountTarget.value = 600000;
                this.updateDisplay();
                break;
            case 'non_return':
                this.nonReturnDaysWrapperTarget.classList.remove('hidden');
                this.amountTarget.value = '';
                this.updateDisplay();
                break;
            case 'late_payment':
                this.latePaymentWrapperTarget.classList.remove('hidden');
                this.amountTarget.value = '';
                this.updateDisplay();
                break;
            case 'other':
                this.amountTarget.value = '';
                this.updateDisplay();
                break;
        }
    }

    calculateAmount() {
        const type = this.typeTarget.value;

        if (type === 'non_return') {
            const days = parseInt(this.nonReturnDaysTarget.value) || 0;
            if (days > 0) {
                this.amountTarget.value = 200000 * days;
            }
        } else if (type === 'late_payment') {
            const base = parseInt(this.latePaymentBaseTarget.value) || 0;
            const days = parseInt(this.latePaymentDaysTarget.value) || 0;
            if (base > 0 && days > 0) {
                const percentCalc = Math.round(base * 0.0025 * days);
                const minCalc = 25000 * days;
                this.amountTarget.value = Math.max(percentCalc, minCalc);
            }
        }

        this.updateDisplay();
    }

    updateDisplay() {
        if (this.hasAmountDisplayTarget) {
            const amount = parseInt(this.amountTarget.value) || 0;
            if (amount > 0) {
                this.amountDisplayTarget.textContent = `= ${(amount / 100).toLocaleString('cs-CZ')} Kč`;
            } else {
                this.amountDisplayTarget.textContent = '';
            }
        }
    }
}
