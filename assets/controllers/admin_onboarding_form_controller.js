import { Controller } from '@hotwired/stimulus';

// Mirrors PriceCalculator::WEEKLY_THRESHOLD_DAYS / YEARLY_THRESHOLD_DAYS and the
// eligibility rules in OrderForm::isEligibleForBillingModeChoice / isEligibleForFrequencyChoice.
// Customer order form runs these via LiveComponent re-renders; admin onboarding
// forms are plain Symfony forms, so this controller does the same show/hide
// client-side. Server-side validators (validateBillingMode / validatePaymentFrequency
// on the FormData) remain authoritative — this is UX only.
const WEEKLY_THRESHOLD_DAYS = 28;
const YEARLY_THRESHOLD_DAYS = 360;

export default class extends Controller {
    static targets = [
        'billingModeContainer',
        'paymentFrequencyContainer',
        'yearlyNotice',
        'billingModeAuto',
        'billingModeManual',
        'paymentFrequencyMonthly',
        'paymentFrequencyYearly',
    ];

    connect() {
        this.boundSync = () => this.update();
        this.element.addEventListener('change', this.boundSync);
        this.element.addEventListener('input', this.boundSync);
        this.update();
    }

    disconnect() {
        this.element.removeEventListener('change', this.boundSync);
        this.element.removeEventListener('input', this.boundSync);
    }

    update() {
        const rentalType = this.rentalType();
        const days = this.durationDays();

        const frequencyEligible =
            rentalType === 'unlimited' ||
            (rentalType === 'limited' && days !== null && days >= YEARLY_THRESHOLD_DAYS);

        this.toggle(this.paymentFrequencyContainerTarget, frequencyEligible);

        // If the field is hidden and YEARLY was previously selected, snap back to
        // MONTHLY so the submitted value matches what the admin can see.
        if (!frequencyEligible && this.hasPaymentFrequencyMonthlyTarget && this.paymentFrequencyYearlyTarget.checked) {
            this.paymentFrequencyMonthlyTarget.checked = true;
        }

        const isYearly = frequencyEligible && this.paymentFrequencyYearlyTarget.checked;

        if (isYearly) {
            // YEARLY → MANUAL_RECURRING always. Hide the billingMode radios and
            // surface the same explanatory notice the customer form shows.
            this.toggle(this.billingModeContainerTarget, false);
            this.toggle(this.yearlyNoticeTarget, true);
            if (this.hasBillingModeManualTarget) {
                this.billingModeManualTarget.checked = true;
            }
            return;
        }

        this.toggle(this.yearlyNoticeTarget, false);

        if (rentalType === 'unlimited') {
            // UNLIMITED (non-yearly) → AUTO_RECURRING is the only legal value.
            this.toggle(this.billingModeContainerTarget, false);
            if (this.hasBillingModeAutoTarget) {
                this.billingModeAutoTarget.checked = true;
            }
            return;
        }

        // LIMITED branch: ≥ WEEKLY_THRESHOLD_DAYS exposes the choice. Below the
        // threshold (or when dates aren't filled yet) we hide it; the admin
        // forms don't actually need a ONE_TIME radio because migrate captures
        // the external payment via paidAt/totalPriceInCzk and the recurring
        // amount kicks in after paidThroughDate.
        const billingModeEligible = days !== null && days >= WEEKLY_THRESHOLD_DAYS;
        this.toggle(this.billingModeContainerTarget, billingModeEligible);
    }

    toggle(element, visible) {
        if (!element) return;
        element.hidden = !visible;
    }

    rentalType() {
        const checked = this.element.querySelector('input[name$="[rentalType]"]:checked');
        return checked ? checked.value : null;
    }

    durationDays() {
        const start = parseIsoDate(this.findInput('startDate'));
        const end = parseIsoDate(this.findInput('endDate'));
        if (!start || !end || end <= start) return null;
        return Math.round((end.getTime() - start.getTime()) / 86400000);
    }

    findInput(field) {
        const el = this.element.querySelector(`input[name$="[${field}]"]`);
        return el ? el.value : '';
    }
}

function parseIsoDate(value) {
    if (!value) return null;
    const m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(value.trim());
    if (!m) return null;
    const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    return isNaN(d.getTime()) ? null : d;
}
