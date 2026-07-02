<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Form\Address\HasBillingAddress;
use App\Service\PriceCalculator;
use App\Validator\AddressExists;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[AddressExists]
final class OrderFormData implements HasBillingAddress
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

    #[Assert\NotBlank(message: 'Zadejte telefonní číslo.')]
    #[Assert\Length(max: 20, maxMessage: 'Telefon může mít maximálně {{ limit }} znaků.')]
    #[Assert\Regex(pattern: '/^\+?[\d\s]+$/', message: 'Telefon může obsahovat pouze číslice, mezery a volitelně znak + na začátku.')]
    #[Assert\Regex(pattern: '/(?:\D*\d){9}/', message: 'Telefon musí obsahovat alespoň 9 číslic.')]
    public ?string $phone = null;

    public ?\DateTimeImmutable $birthDate = null;

    public ?string $plainPassword = null;

    public bool $invoiceToCompany = false;

    #[Assert\Length(max: 255, maxMessage: 'Název firmy může mít maximálně {{ limit }} znaků.')]
    public ?string $companyName = null;

    #[Assert\Length(exactly: 8, exactMessage: 'IČO musí mít přesně {{ limit }} číslic.')]
    #[Assert\Regex(pattern: '/^\d{8}$/', message: 'IČO musí obsahovat pouze číslice.')]
    public ?string $companyId = null;

    #[Assert\Length(max: 14, maxMessage: 'DIČ může mít maximálně {{ limit }} znaků.')]
    #[Assert\Regex(pattern: '/^([A-Z]{2}[A-Z0-9]{2,12})?$/', message: 'Neplatný formát DIČ. Začněte kódem země (např. CZ12345678).')]
    public ?string $companyVatId = null;

    #[Assert\Length(max: 255, maxMessage: 'Ulice může mít maximálně {{ limit }} znaků.')]
    public ?string $billingStreet = null;

    #[Assert\Length(max: 100, maxMessage: 'Město může mít maximálně {{ limit }} znaků.')]
    public ?string $billingCity = null;

    #[Assert\Length(max: 10, maxMessage: 'PSČ může mít maximálně {{ limit }} znaků.')]
    public ?string $billingPostalCode = null;

    /**
     * Surfaced server-side only after an {@see AddressExists} violation has
     * fired; ticking it lets the customer proceed with an address that the
     * Photon registry didn't match (false-negatives are part of the trade-off).
     */
    public bool $addressOverride = false;

    #[Assert\NotNull(message: 'Vyberte datum začátku.')]
    public ?\DateTimeImmutable $startDate = null;

    public ?\DateTimeImmutable $endDate = null;

    /**
     * 'auto' = system picks a storage of the type (silent re-assign if the current pick conflicts).
     * 'manual' = user picked a specific unit from the map (must surface a clear error if it becomes unavailable).
     * Read by OrderAcceptController to decide between auto-swap and an error redirect.
     */
    public string $selectionMode = 'auto';

    /**
     * Never a user choice (spec 076): derived from paymentMethod + frequency +
     * rental length by {@see self::deriveBillingMode()} on every validation
     * pass via {@see BillingMode::derive()}. Kept as a property because the
     * session round-trip and OrderAcceptController's locking read it.
     * Consumers read it via {@see self::resolvedBillingMode()} which falls
     * back to AUTO_RECURRING when the dates are not filled in yet.
     */
    public ?BillingMode $billingMode = BillingMode::AUTO_RECURRING;

    /**
     * Customer-chosen payment frequency (spec 045). MONTHLY by default;
     * YEARLY surfaces only for rentals ≥ {@see PriceCalculator::YEARLY_THRESHOLD_DAYS}
     * days and is payable only by bank transfer (spec 076) — always
     * MANUAL_RECURRING via {@see BillingMode::derive()}.
     *
     * Nullable to survive partial-form submissions during Live UX validation
     * (mirrors {@see self::$billingMode}). Consumers read it via
     * {@see self::resolvedPaymentFrequency()} which falls back to MONTHLY.
     */
    public ?PaymentFrequency $paymentFrequency = PaymentFrequency::MONTHLY;

    public ?PaymentMethod $paymentMethod = PaymentMethod::GOPAY;

    public function resolvedBillingMode(): BillingMode
    {
        return $this->billingMode ?? BillingMode::AUTO_RECURRING;
    }

    public function resolvedPaymentFrequency(): PaymentFrequency
    {
        return $this->paymentFrequency ?? PaymentFrequency::MONTHLY;
    }

    #[Assert\Callback]
    public function validatePassword(ExecutionContextInterface $context): void
    {
        if (null === $this->plainPassword || '' === $this->plainPassword) {
            return;
        }

        if (strlen($this->plainPassword) < 8) {
            $context->buildViolation('Heslo musí mít alespoň 8 znaků.')
                ->atPath('plainPassword')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateAddress(ExecutionContextInterface $context): void
    {
        if (null === $this->billingStreet || '' === $this->billingStreet) {
            $context->buildViolation('Zadejte ulici.')
                ->atPath('billingStreet')
                ->addViolation();
        }

        if (null === $this->billingCity || '' === $this->billingCity) {
            $context->buildViolation('Zadejte město.')
                ->atPath('billingCity')
                ->addViolation();
        }

        if (null === $this->billingPostalCode || '' === $this->billingPostalCode) {
            $context->buildViolation('Zadejte PSČ.')
                ->atPath('billingPostalCode')
                ->addViolation();
        }
    }

    public function hasCompleteAddress(): bool
    {
        return null !== $this->billingStreet && '' !== $this->billingStreet
            && null !== $this->billingCity && '' !== $this->billingCity
            && null !== $this->billingPostalCode && '' !== $this->billingPostalCode;
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
        if (null === $this->startDate) {
            return;
        }

        $today = new \DateTimeImmutable('today');

        if ($this->startDate < $today) {
            $context->buildViolation('Datum začátku nemůže být v minulosti.')
                ->atPath('startDate')
                ->addViolation();
        }

        if (null === $this->endDate) {
            $context->buildViolation('Vyberte datum konce pronájmu.')
                ->atPath('endDate')
                ->addViolation();

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
    public function validatePaymentMethod(ExecutionContextInterface $context): void
    {
        // Spec 076: cards only establish recurring monthly payments.
        if (PaymentMethod::GOPAY === $this->paymentMethod && PaymentFrequency::YEARLY === $this->paymentFrequency) {
            $context->buildViolation('Roční platbu lze platit pouze bankovním převodem.')
                ->atPath('paymentFrequency')
                ->addViolation();
        }

        $rentalDays = $this->rentalDays();
        if (null === $rentalDays) {
            return;
        }

        if (PaymentMethod::GOPAY === $this->paymentMethod && $rentalDays < PriceCalculator::WEEKLY_THRESHOLD_DAYS) {
            $context->buildViolation('Platba kartou je dostupná pro pronájmy od 31 dnů. Kratší pronájem zaplatíte bankovním převodem.')
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
     * Runs after every other callback (declaration order): billing mode is
     * never a user choice, it always follows {@see BillingMode::derive()}.
     * With missing/invalid dates the default is left in place — the form is
     * invalid in that case anyway and nothing downstream reads the value.
     */
    #[Assert\Callback]
    public function deriveBillingMode(ExecutionContextInterface $context): void
    {
        $rentalDays = $this->rentalDays();
        if (null === $this->paymentMethod || null === $rentalDays) {
            return;
        }

        $this->billingMode = BillingMode::derive($this->paymentMethod, $this->resolvedPaymentFrequency(), $rentalDays);
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

    public static function fromUser(User $user): self
    {
        $formData = new self();
        $formData->email = $user->email;
        $formData->firstName = $user->firstName;
        $formData->lastName = $user->lastName;
        $formData->phone = $user->phone;
        $formData->birthDate = $user->birthDate;
        $formData->companyName = $user->companyName;
        $formData->companyId = $user->companyId;
        $formData->companyVatId = $user->companyVatId;
        $formData->billingStreet = $user->billingStreet;
        $formData->billingCity = $user->billingCity;
        $formData->billingPostalCode = $user->billingPostalCode;
        $formData->invoiceToCompany = null !== $user->companyId && '' !== $user->companyId;

        return $formData;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSessionArray(): array
    {
        return [
            'email' => $this->email,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'phone' => $this->phone,
            'birthDate' => $this->birthDate?->format('Y-m-d'),
            'plainPassword' => $this->plainPassword,
            'invoiceToCompany' => $this->invoiceToCompany,
            'companyName' => $this->companyName,
            'companyId' => $this->companyId,
            'companyVatId' => $this->companyVatId,
            'billingStreet' => $this->billingStreet,
            'billingCity' => $this->billingCity,
            'billingPostalCode' => $this->billingPostalCode,
            'addressOverride' => $this->addressOverride,
            'startDate' => $this->startDate?->format('Y-m-d'),
            'endDate' => $this->endDate?->format('Y-m-d'),
            'selectionMode' => $this->selectionMode,
            'billingMode' => $this->resolvedBillingMode()->value,
            'paymentFrequency' => $this->resolvedPaymentFrequency()->value,
            'paymentMethod' => $this->paymentMethod?->value,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromSessionArray(array $data): self
    {
        $formData = new self();
        $formData->email = (string) ($data['email'] ?? '');
        $formData->firstName = (string) ($data['firstName'] ?? '');
        $formData->lastName = (string) ($data['lastName'] ?? '');
        $formData->phone = $data['phone'] ?? null;
        $formData->birthDate = isset($data['birthDate']) ? new \DateTimeImmutable($data['birthDate']) : null;
        $formData->plainPassword = $data['plainPassword'] ?? null;
        $formData->invoiceToCompany = (bool) ($data['invoiceToCompany'] ?? false);
        $formData->companyName = $data['companyName'] ?? null;
        $formData->companyId = $data['companyId'] ?? null;
        $formData->companyVatId = $data['companyVatId'] ?? null;
        $formData->billingStreet = $data['billingStreet'] ?? null;
        $formData->billingCity = $data['billingCity'] ?? null;
        $formData->billingPostalCode = $data['billingPostalCode'] ?? null;
        $formData->addressOverride = (bool) ($data['addressOverride'] ?? false);
        $formData->startDate = isset($data['startDate']) ? new \DateTimeImmutable($data['startDate']) : null;
        $formData->endDate = isset($data['endDate']) ? new \DateTimeImmutable($data['endDate']) : null;
        $mode = $data['selectionMode'] ?? null;
        $formData->selectionMode = 'manual' === $mode ? 'manual' : 'auto';
        if (isset($data['billingMode'])) {
            $formData->billingMode = BillingMode::tryFrom($data['billingMode']) ?? BillingMode::AUTO_RECURRING;
        }
        if (isset($data['paymentFrequency'])) {
            $formData->paymentFrequency = PaymentFrequency::tryFrom($data['paymentFrequency']) ?? PaymentFrequency::MONTHLY;
        }
        if (isset($data['paymentMethod'])) {
            $formData->paymentMethod = PaymentMethod::tryFrom($data['paymentMethod']) ?? PaymentMethod::GOPAY;
        }

        return $formData;
    }
}
