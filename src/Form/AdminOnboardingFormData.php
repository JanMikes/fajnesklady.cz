<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Form\Address\HasBillingAddress;
use App\Service\PriceCalculator;
use App\Validator\AddressExists;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[AddressExists]
final class AdminOnboardingFormData implements HasBillingAddress
{
    #[Assert\NotBlank(message: 'Zadejte e-mailovou adresu.')]
    #[Assert\Email(message: 'Zadejte platnou e-mailovou adresu.')]
    public string $email = '';

    #[Assert\NotBlank(message: 'Zadejte jméno.')]
    #[Assert\Length(max: 100, maxMessage: 'Jméno může mít maximálně {{ limit }} znaků.')]
    public string $firstName = '';

    #[Assert\NotBlank(message: 'Zadejte příjmení.')]
    #[Assert\Length(max: 100, maxMessage: 'Příjmení může mít maximálně {{ limit }} znaků.')]
    public string $lastName = '';

    #[Assert\Length(max: 20, maxMessage: 'Telefon může mít maximálně {{ limit }} znaků.')]
    public ?string $phone = null;

    public ?\DateTimeImmutable $birthDate = null;

    public bool $invoiceToCompany = false;

    #[Assert\Length(max: 255, maxMessage: 'Název firmy může mít maximálně {{ limit }} znaků.')]
    public ?string $companyName = null;

    #[Assert\Length(exactly: 8, exactMessage: 'IČO musí mít přesně {{ limit }} číslic.')]
    #[Assert\Regex(pattern: '/^\d{8}$/', message: 'IČO musí obsahovat pouze číslice.')]
    public ?string $companyId = null;

    #[Assert\Length(max: 14, maxMessage: 'DIČ může mít maximálně {{ limit }} znaků.')]
    #[Assert\Regex(pattern: '/^([A-Z]{2}[A-Z0-9]{2,12})?$/', message: 'Neplatný formát DIČ. Začněte kódem země (např. CZ12345678).')]
    public ?string $companyVatId = null;

    #[Assert\NotBlank(message: 'Zadejte ulici.')]
    #[Assert\Length(max: 255, maxMessage: 'Ulice může mít maximálně {{ limit }} znaků.')]
    public ?string $billingStreet = null;

    #[Assert\NotBlank(message: 'Zadejte město.')]
    #[Assert\Length(max: 100, maxMessage: 'Město může mít maximálně {{ limit }} znaků.')]
    public ?string $billingCity = null;

    #[Assert\NotBlank(message: 'Zadejte PSČ.')]
    #[Assert\Length(max: 10, maxMessage: 'PSČ může mít maximálně {{ limit }} znaků.')]
    public ?string $billingPostalCode = null;

    public bool $addressOverride = false;

    #[Assert\NotNull(message: 'Zadejte datum začátku.')]
    public ?\DateTimeImmutable $startDate = null;

    #[Assert\NotNull(message: 'Zadejte datum konce.')]
    public ?\DateTimeImmutable $endDate = null;

    #[Assert\NotNull(message: 'Vyberte způsob platby.')]
    public ?PaymentMethod $paymentMethod = null;

    /**
     * Never an admin choice (spec 076) — derived from paymentMethod +
     * frequency + rental length by {@see self::deriveBillingMode()} via
     * {@see BillingMode::derive()}. Read by the component's submit().
     */
    public ?BillingMode $billingMode = null;

    #[Assert\NotNull(message: 'Vyberte frekvenci platby.')]
    public ?PaymentFrequency $paymentFrequency = null;

    #[Assert\NotBlank(message: 'Vyberte cenový model.')]
    public ?string $monthlyPriceMode = null;

    /**
     * Individual price in CZK. Its meaning follows the payment frequency:
     * per month (MONTHLY), per year (YEARLY), or the whole-rental total
     * (ONE_TIME upfront). The 15 000 Kč legal recurring-payment cap applies
     * only to the monthly figure — see {@see self::validateCustomPriceCap()}.
     */
    #[Assert\PositiveOrZero(message: 'Cena nemůže být záporná.')]
    public ?float $customMonthlyPriceInCzk = null;

    public bool $isExternallyPrepaid = false;

    public ?\DateTimeImmutable $paidThroughDate = null;

    #[Assert\File(
        maxSize: '10M',
        mimeTypes: ['application/pdf', 'image/jpeg', 'image/png'],
        mimeTypesMessage: 'Povolené formáty: PDF, JPEG, PNG.',
    )]
    public ?UploadedFile $contractDocument = null;

    #[Assert\Length(max: 10, maxMessage: 'Variabilní symbol může mít maximálně {{ limit }} znaků.')]
    #[Assert\Regex(pattern: '/^\d*$/', message: 'Variabilní symbol musí obsahovat pouze číslice.')]
    public ?string $variableSymbol = null;

    #[Assert\PositiveOrZero(message: 'Dluh nemůže být záporný.')]
    public ?float $debtAmountInCzk = null;

    public function hasCompleteAddress(): bool
    {
        return null !== $this->billingStreet && '' !== $this->billingStreet
            && null !== $this->billingCity && '' !== $this->billingCity
            && null !== $this->billingPostalCode && '' !== $this->billingPostalCode;
    }

    public function startsInPast(): bool
    {
        return null !== $this->startDate
            && $this->startDate < new \DateTimeImmutable('today');
    }

    #[Assert\Callback]
    public function validateCompanyInfo(ExecutionContextInterface $context): void
    {
        if (!$this->invoiceToCompany) {
            return;
        }

        if (null === $this->companyId || '' === $this->companyId) {
            $context->buildViolation('Zadejte IČO.')
                ->atPath('companyId')
                ->addViolation();
        }

        if (null === $this->companyName || '' === $this->companyName) {
            $context->buildViolation('Zadejte název firmy.')
                ->atPath('companyName')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateBirthDate(ExecutionContextInterface $context): void
    {
        if ($this->invoiceToCompany) {
            return;
        }

        if (null === $this->birthDate) {
            $context->buildViolation('Zadejte datum narození.')
                ->atPath('birthDate')
                ->addViolation();

            return;
        }

        if ($this->birthDate->modify('+18 years') > new \DateTimeImmutable('today')) {
            $context->buildViolation('Nájemce musí být starší 18 let.')
                ->atPath('birthDate')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateDates(ExecutionContextInterface $context): void
    {
        if (null === $this->startDate || null === $this->endDate) {
            return;
        }

        $rentalDays = $this->endDate <= $this->startDate
            ? 0
            : (int) $this->startDate->diff($this->endDate)->days;

        if ($rentalDays < 7) {
            $context->buildViolation('Minimální doba pronájmu je 7 dní.')
                ->atPath('endDate')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateMonthlyPriceMode(ExecutionContextInterface $context): void
    {
        if ('custom' !== $this->monthlyPriceMode) {
            return;
        }

        if (null === $this->customMonthlyPriceInCzk || $this->customMonthlyPriceInCzk <= 0) {
            $context->buildViolation('Zadejte individuální cenu.')
                ->atPath('customMonthlyPriceInCzk')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateCustomPriceCap(ExecutionContextInterface $context): void
    {
        // The 15 000 Kč cap is the legal maximum for a single recurring card
        // charge (Podmínky opakovaných plateb čl. III) and therefore binds the
        // MONTHLY figure only — yearly and upfront payments are bank-transfer
        // territory (validatePaymentMethod) and routinely exceed it.
        if ('custom' !== $this->monthlyPriceMode || null === $this->customMonthlyPriceInCzk) {
            return;
        }

        $frequency = $this->paymentFrequency ?? PaymentFrequency::MONTHLY;
        if (PaymentFrequency::MONTHLY === $frequency && $this->customMonthlyPriceInCzk > 15000) {
            $context->buildViolation('Maximální měsíční cena je 15 000 Kč (zákonný strop pro opakované platby).')
                ->atPath('customMonthlyPriceInCzk')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateUpfrontCustomPriceIsSinglePayment(ExecutionContextInterface $context): void
    {
        // An upfront custom price is the WHOLE-rental total and lands in
        // firstPaymentPrice as a single payment. Rentals longer than 12 monthly
        // periods pay in yearly tranches (spec 078) whose math recovers the
        // locked monthly rate as firstPaymentPrice / 12 — an arbitrary total
        // would corrupt every follow-up tranche. Mirrored by the hard guard in
        // OrderService::createOrder.
        if (PaymentFrequency::ONE_TIME !== $this->paymentFrequency || 'custom' !== $this->monthlyPriceMode) {
            return;
        }

        if (null === $this->startDate || null === $this->endDate || $this->endDate <= $this->startDate) {
            return;
        }

        if (PriceCalculator::isUpfrontSplitIntoTranches($this->startDate, $this->endDate)) {
            $context->buildViolation('Individuální celkovou cenu lze zadat jen u jednorázové platby do 12 měsíců — delší pronájem se platí v ročních platbách podle ceníku. Zvolte roční nebo měsíční frekvenci.')
                ->atPath('customMonthlyPriceInCzk')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validatePaymentMethod(ExecutionContextInterface $context): void
    {
        // Spec 076: cards only establish recurring monthly payments.
        if (PaymentMethod::GOPAY === $this->paymentMethod && PaymentFrequency::YEARLY === $this->paymentFrequency) {
            $context->buildViolation('Roční platbu lze platit pouze bankovním převodem.')
                ->atPath('paymentFrequency')
                ->addViolation();
        }

        // Spec 078: the whole-rental upfront payment is bank-transfer only.
        if (PaymentMethod::GOPAY === $this->paymentMethod && PaymentFrequency::ONE_TIME === $this->paymentFrequency) {
            $context->buildViolation('Jednorázovou platbu celé částky lze provést pouze bankovním převodem.')
                ->atPath('paymentFrequency')
                ->addViolation();
        }

        // Spec 078: payments handled outside the system are recorded via
        // "Externí předplatné / Předplaceno do", never as an upfront order.
        if (PaymentMethod::EXTERNAL === $this->paymentMethod && PaymentFrequency::ONE_TIME === $this->paymentFrequency) {
            $context->buildViolation('Pro pronájem uhrazený mimo systém použijte „Externí předplatné" s datem „Předplaceno do". Jednorázová platba předem je určena pro bankovní převod.')
                ->atPath('paymentFrequency')
                ->addViolation();
        }

        $rentalDays = $this->rentalDays();
        if (null === $rentalDays) {
            return;
        }

        if (PaymentMethod::GOPAY === $this->paymentMethod && $rentalDays < PriceCalculator::WEEKLY_THRESHOLD_DAYS) {
            $context->buildViolation('Platba kartou je dostupná pro pronájmy od 31 dnů. Kratší pronájem se platí bankovním převodem.')
                ->atPath('paymentMethod')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validatePaymentFrequency(ExecutionContextInterface $context): void
    {
        if (PaymentFrequency::YEARLY !== $this->paymentFrequency) {
            return;
        }

        $rentalDays = $this->rentalDays();
        if (null === $rentalDays) {
            return;
        }

        if ($rentalDays < PriceCalculator::YEARLY_THRESHOLD_DAYS) {
            $context->buildViolation('Roční platba je dostupná pouze pro pronájem na 12 měsíců a déle.')
                ->atPath('paymentFrequency')
                ->addViolation();
        }
    }

    /**
     * Runs after the other payment callbacks (declaration order): billing mode
     * always follows {@see BillingMode::derive()}. EXTERNAL derives to
     * MANUAL_RECURRING (payments handled outside the system, no token).
     */
    #[Assert\Callback]
    public function deriveBillingMode(ExecutionContextInterface $context): void
    {
        $rentalDays = $this->rentalDays();
        if (null === $this->paymentMethod || null === $rentalDays) {
            return;
        }

        $this->billingMode = BillingMode::derive(
            $this->paymentMethod,
            $this->paymentFrequency ?? PaymentFrequency::MONTHLY,
            $rentalDays,
        );
    }

    private function rentalDays(): ?int
    {
        if (null === $this->startDate || null === $this->endDate) {
            return null;
        }

        return $this->endDate <= $this->startDate
            ? 0
            : (int) $this->startDate->diff($this->endDate)->days;
    }

    #[Assert\Callback]
    public function validateExternalIsPrepaid(ExecutionContextInterface $context): void
    {
        if (PaymentMethod::EXTERNAL !== $this->paymentMethod) {
            return;
        }

        if ('free' === $this->monthlyPriceMode) {
            return;
        }

        if ($this->startsInPast()) {
            return; // backdated → handled by validatePaidThroughDate
        }

        if ($this->isExternallyPrepaid) {
            return;
        }

        if (null === $this->paidThroughDate) {
            $context->buildViolation('Externí platba znamená, že zákazník již zaplatil — vyplňte datum, do kdy je předplaceno (zaškrtněte „Externí předplatné" a vyberte datum). Pro pronájem bez nutnosti platby zvolte „Zdarma".')
                ->atPath('paidThroughDate')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validatePaidThroughDate(ExecutionContextInterface $context): void
    {
        // The "Předplaceno do" date is in play when the customer prepaid externally, or
        // when the rental starts in the past (elapsed period must already be covered).
        // Never for free rentals. When not in play it is nulled at submit — don't validate
        // a stale value.
        $collected = 'free' !== $this->monthlyPriceMode
            && ($this->isExternallyPrepaid || $this->startsInPast());

        if (!$collected) {
            return;
        }

        if (null === $this->paidThroughDate) {
            $context->buildViolation($this->startsInPast()
                ? 'Datum začátku je v minulosti — zadejte datum, do kdy má zákazník předplaceno (dnes nebo v budoucnosti).'
                : 'Zadejte datum, do kdy je předplaceno.')
                ->atPath('paidThroughDate')
                ->addViolation();

            return;
        }

        // Must be today or in the future.
        if ($this->paidThroughDate < new \DateTimeImmutable('today')) {
            $context->buildViolation('Datum „Předplaceno do" musí být dnes nebo v budoucnosti.')
                ->atPath('paidThroughDate')
                ->addViolation();
        }

        // Cannot precede the start date.
        if (null !== $this->startDate && $this->paidThroughDate < $this->startDate) {
            $context->buildViolation('Datum předplatby nemůže být před datem začátku.')
                ->atPath('paidThroughDate')
                ->addViolation();
        }

        // Cannot exceed the contract end.
        if (null !== $this->endDate && $this->paidThroughDate > $this->endDate) {
            $context->buildViolation('Datum předplatby nemůže být po datu konce smlouvy.')
                ->atPath('paidThroughDate')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateDebtPaymentMethod(ExecutionContextInterface $context): void
    {
        if (null === $this->debtAmountInCzk || $this->debtAmountInCzk <= 0) {
            return;
        }

        if (PaymentMethod::EXTERNAL === $this->paymentMethod) {
            $context->buildViolation('Při dluhu nelze použít externě — zákazník musí mít možnost zaplatit. Zvolte GoPay nebo bankovní převod.')
                ->atPath('paymentMethod')
                ->addViolation();
        }
    }
}
