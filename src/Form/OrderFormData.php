<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\ExpectedDuration;
use App\Enum\RentalType;
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

    #[Assert\NotNull(message: 'Vyberte typ pronájmu.')]
    public ?RentalType $rentalType = RentalType::LIMITED;

    public ?ExpectedDuration $expectedDuration = null;

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
     * Selected by the customer when their rental is fixed-term LIMITED ≥ 28 days.
     * Other durations are forced (UNLIMITED → AUTO_RECURRING, short LIMITED → ONE_TIME)
     * by {@see self::validateBillingMode()} so a forged payload cannot bypass eligibility.
     *
     * Nullable so the Form DataMapper can map an empty radio submission back
     * to the FormData without a TypeError (the per-field live-validation flow
     * submits the form while individual fields are still empty). Consumers
     * read it via {@see self::resolvedBillingMode()} which falls back to
     * AUTO_RECURRING when not yet selected.
     */
    public ?BillingMode $billingMode = BillingMode::AUTO_RECURRING;

    public function resolvedBillingMode(): BillingMode
    {
        return $this->billingMode ?? BillingMode::AUTO_RECURRING;
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

        if (RentalType::LIMITED === $this->rentalType) {
            if (null === $this->endDate) {
                $context->buildViolation('Pro omezený pronájem je vyžadováno datum konce.')
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

                return;
            }

            $maxEnd = $this->startDate->modify('+1 year');
            if ($this->endDate > $maxEnd) {
                $context->buildViolation('Doba určitá může být maximálně 1 rok. Pro delší pronájem zvolte dobu neurčitou.')
                    ->atPath('endDate')
                    ->addViolation();
            }
        }
    }

    #[Assert\Callback]
    public function validateExpectedDuration(ExecutionContextInterface $context): void
    {
        if (RentalType::UNLIMITED !== $this->rentalType) {
            return;
        }

        if (null === $this->expectedDuration) {
            $context->buildViolation('Vyberte předpokládanou dobu pronájmu.')
                ->atPath('expectedDuration')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateBillingMode(ExecutionContextInterface $context): void
    {
        if (RentalType::UNLIMITED === $this->rentalType && BillingMode::AUTO_RECURRING !== $this->billingMode) {
            $context->buildViolation('Pro pronájem na dobu neurčitou je dostupná pouze automatická platba kartou.')
                ->atPath('billingMode')
                ->addViolation();
        }

        if (RentalType::LIMITED === $this->rentalType
            && null !== $this->startDate && null !== $this->endDate
            && (int) $this->startDate->diff($this->endDate)->days < PriceCalculator::WEEKLY_THRESHOLD_DAYS
            && BillingMode::ONE_TIME !== $this->billingMode) {
            $context->buildViolation('Pro krátkodobé pronájmy se platí jednorázově.')
                ->atPath('billingMode')
                ->addViolation();
        }
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
            'rentalType' => $this->rentalType?->value,
            'expectedDuration' => $this->expectedDuration?->value,
            'startDate' => $this->startDate?->format('Y-m-d'),
            'endDate' => $this->endDate?->format('Y-m-d'),
            'selectionMode' => $this->selectionMode,
            'billingMode' => $this->resolvedBillingMode()->value,
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
        $formData->rentalType = isset($data['rentalType']) ? RentalType::tryFrom($data['rentalType']) : RentalType::LIMITED;
        if (isset($data['expectedDuration'])) {
            $formData->expectedDuration = ExpectedDuration::tryFrom($data['expectedDuration']);
        }
        $formData->startDate = isset($data['startDate']) ? new \DateTimeImmutable($data['startDate']) : null;
        $formData->endDate = isset($data['endDate']) ? new \DateTimeImmutable($data['endDate']) : null;
        $mode = $data['selectionMode'] ?? null;
        $formData->selectionMode = 'manual' === $mode ? 'manual' : 'auto';
        if (isset($data['billingMode'])) {
            $formData->billingMode = BillingMode::tryFrom($data['billingMode']) ?? BillingMode::AUTO_RECURRING;
        }

        return $formData;
    }
}
