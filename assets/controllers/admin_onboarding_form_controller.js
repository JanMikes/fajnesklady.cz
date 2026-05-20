import { Controller } from '@hotwired/stimulus';

// Mirrors the customer-facing OrderForm LiveComponent (templates/components/OrderForm.html.twig +
// src/Twig/Components/OrderForm.php) for the admin onboarding forms, which are
// plain Symfony forms — no live re-renders. Show/hide rules:
//
//   invoiceToCompany unchecked → companyId/Name/VatId hidden, birthDate shown,
//                                 address heading = "Adresa bydliště"
//   invoiceToCompany checked   → companyId/Name/VatId shown, birthDate hidden,
//                                 address heading = "Sídlo společnosti"
//   rentalType=limited         → expectedDuration hidden, endDate shown
//   rentalType=unlimited       → expectedDuration shown, endDate hidden
//   isExternallyPrepaid (digital only) → paidThroughDate shown when checked
//   paymentFrequency / billingMode      → see WEEKLY/YEARLY thresholds below
//
// Mirrors PriceCalculator::WEEKLY_THRESHOLD_DAYS / YEARLY_THRESHOLD_DAYS and
// OrderForm::isEligibleForBillingModeChoice / isEligibleForFrequencyChoice:
//
//   paymentFrequency visible only for UNLIMITED or LIMITED ≥ YEARLY_THRESHOLD_DAYS
//   billingMode      visible only for LIMITED ≥ WEEKLY_THRESHOLD_DAYS && non-YEARLY
//   UNLIMITED non-YEARLY pins AUTO_RECURRING
//   YEARLY pins MANUAL_RECURRING and surfaces the explanatory blue notice
//
// FormData validators on AdminMigrateCustomerFormData / AdminCreateOnboardingFormData
// remain authoritative — this controller is UX only. A tampered form payload
// still gets caught server-side.
const WEEKLY_THRESHOLD_DAYS = 28;
const YEARLY_THRESHOLD_DAYS = 360;

export default class extends Controller {
    static targets = [
        // paymentFrequency / billingMode block
        'billingModeContainer',
        'paymentFrequencyContainer',
        'yearlyNotice',
        'billingModeAuto',
        'billingModeManual',
        'paymentFrequencyMonthly',
        'paymentFrequencyYearly',
        // invoiceToCompany block
        'companyFieldsContainer',
        'birthDateContainer',
        'addressHeadingPerson',
        'addressHeadingCompany',
        // rentalType block
        'expectedDurationContainer',
        'endDateContainer',
        // isExternallyPrepaid block (digital only)
        'paidThroughDateContainer',
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
        this.applyCompanyRules();
        this.applyRentalTypeRules();
        this.applyExternalPrepaymentRules();
        this.applyPaymentRules();
    }

    applyCompanyRules() {
        const isCompany = this.isCompany();

        if (this.hasCompanyFieldsContainerTarget) {
            this.toggle(this.companyFieldsContainerTarget, isCompany);
        }
        if (this.hasBirthDateContainerTarget) {
            this.toggle(this.birthDateContainerTarget, !isCompany);
        }
        if (this.hasAddressHeadingPersonTarget) {
            this.toggle(this.addressHeadingPersonTarget, !isCompany);
        }
        if (this.hasAddressHeadingCompanyTarget) {
            this.toggle(this.addressHeadingCompanyTarget, isCompany);
        }
    }

    applyRentalTypeRules() {
        const rentalType = this.rentalType();

        if (this.hasExpectedDurationContainerTarget) {
            this.toggle(this.expectedDurationContainerTarget, rentalType === 'unlimited');
        }
        if (this.hasEndDateContainerTarget) {
            this.toggle(this.endDateContainerTarget, rentalType === 'limited');
        }
    }

    applyExternalPrepaymentRules() {
        if (!this.hasPaidThroughDateContainerTarget) return;
        const checkbox = this.element.querySelector('input[type="checkbox"][name$="[isExternallyPrepaid]"]');
        this.toggle(this.paidThroughDateContainerTarget, !!(checkbox && checkbox.checked));
    }

    applyPaymentRules() {
        const rentalType = this.rentalType();
        const days = this.durationDays();

        const frequencyEligible =
            rentalType === 'unlimited' ||
            (rentalType === 'limited' && days !== null && days >= YEARLY_THRESHOLD_DAYS);

        if (this.hasPaymentFrequencyContainerTarget) {
            this.toggle(this.paymentFrequencyContainerTarget, frequencyEligible);
        }

        // If the field is hidden and YEARLY was previously selected, snap back to
        // MONTHLY so the submitted value matches what the admin can see.
        if (
            !frequencyEligible &&
            this.hasPaymentFrequencyMonthlyTarget &&
            this.hasPaymentFrequencyYearlyTarget &&
            this.paymentFrequencyYearlyTarget.checked
        ) {
            this.paymentFrequencyMonthlyTarget.checked = true;
        }

        const isYearly =
            frequencyEligible &&
            this.hasPaymentFrequencyYearlyTarget &&
            this.paymentFrequencyYearlyTarget.checked;

        if (isYearly) {
            // YEARLY → MANUAL_RECURRING always. Hide the billingMode radios and
            // surface the same explanatory notice the customer form shows.
            if (this.hasBillingModeContainerTarget) this.toggle(this.billingModeContainerTarget, false);
            if (this.hasYearlyNoticeTarget) this.toggle(this.yearlyNoticeTarget, true);
            if (this.hasBillingModeManualTarget) this.billingModeManualTarget.checked = true;
            return;
        }

        if (this.hasYearlyNoticeTarget) this.toggle(this.yearlyNoticeTarget, false);

        if (rentalType === 'unlimited') {
            // UNLIMITED (non-yearly) → AUTO_RECURRING is the only legal value.
            if (this.hasBillingModeContainerTarget) this.toggle(this.billingModeContainerTarget, false);
            if (this.hasBillingModeAutoTarget) this.billingModeAutoTarget.checked = true;
            return;
        }

        // LIMITED branch: ≥ WEEKLY_THRESHOLD_DAYS exposes the choice. Below the
        // threshold (or when dates aren't filled yet) we hide it; the admin
        // forms don't actually need a ONE_TIME radio because migrate captures
        // the external payment via paidAt/totalPriceInCzk and the recurring
        // amount kicks in after paidThroughDate.
        const billingModeEligible = days !== null && days >= WEEKLY_THRESHOLD_DAYS;
        if (this.hasBillingModeContainerTarget) {
            this.toggle(this.billingModeContainerTarget, billingModeEligible);
        }
    }

    toggle(element, visible) {
        if (!element) return;
        element.hidden = !visible;
    }

    isCompany() {
        const checkbox = this.element.querySelector('input[type="checkbox"][name$="[invoiceToCompany]"]');
        return !!(checkbox && checkbox.checked);
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
