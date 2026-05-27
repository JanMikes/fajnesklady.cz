<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\BillingMode;
use App\Enum\ExpectedDuration;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Enum\RentalType;
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

    #[Assert\NotNull(message: 'Vyberte typ pronájmu.')]
    public ?RentalType $rentalType = null;

    public ?ExpectedDuration $expectedDuration = null;

    #[Assert\NotNull(message: 'Zadejte datum začátku.')]
    public ?\DateTimeImmutable $startDate = null;

    public ?\DateTimeImmutable $endDate = null;

    #[Assert\NotNull(message: 'Vyberte způsob platby.')]
    public ?PaymentMethod $paymentMethod = null;

    #[Assert\NotNull(message: 'Vyberte způsob následných plateb.')]
    public ?BillingMode $billingMode = null;

    #[Assert\NotNull(message: 'Vyberte frekvenci platby.')]
    public ?PaymentFrequency $paymentFrequency = null;

    #[Assert\NotBlank(message: 'Vyberte cenový model.')]
    public ?string $monthlyPriceMode = null;

    #[Assert\PositiveOrZero(message: 'Cena nemůže být záporná.')]
    #[Assert\LessThanOrEqual(value: 15000, message: 'Maximální měsíční cena je 15 000 Kč (zákonný strop pro opakované platby).')]
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
            }
        }
    }

    #[Assert\Callback]
    public function validateMonthlyPriceMode(ExecutionContextInterface $context): void
    {
        if ('custom' !== $this->monthlyPriceMode) {
            return;
        }

        if (null === $this->customMonthlyPriceInCzk || $this->customMonthlyPriceInCzk <= 0) {
            $context->buildViolation('Zadejte individuální měsíční cenu.')
                ->atPath('customMonthlyPriceInCzk')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateBillingMode(ExecutionContextInterface $context): void
    {
        if (null === $this->billingMode || null === $this->paymentMethod || null === $this->rentalType) {
            return;
        }

        if (PaymentFrequency::YEARLY === $this->paymentFrequency) {
            return;
        }

        if (PaymentMethod::BANK_TRANSFER === $this->paymentMethod) {
            if (BillingMode::AUTO_RECURRING === $this->billingMode) {
                $this->billingMode = BillingMode::MANUAL_RECURRING;
            }

            return;
        }

        if (RentalType::UNLIMITED === $this->rentalType && BillingMode::AUTO_RECURRING !== $this->billingMode) {
            $context->buildViolation('Pro pronájem na dobu neurčitou je dostupná pouze automatická platba kartou.')
                ->atPath('billingMode')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validatePaymentFrequency(ExecutionContextInterface $context): void
    {
        if (null === $this->paymentFrequency || PaymentFrequency::YEARLY !== $this->paymentFrequency) {
            return;
        }

        if (RentalType::LIMITED === $this->rentalType
            && null !== $this->startDate && null !== $this->endDate
            && (int) $this->startDate->diff($this->endDate)->days < PriceCalculator::YEARLY_THRESHOLD_DAYS
        ) {
            $context->buildViolation('Roční platba je dostupná pouze pro pronájem na 12 měsíců a déle.')
                ->atPath('paymentFrequency')
                ->addViolation();

            return;
        }

        if (null === $this->billingMode || BillingMode::MANUAL_RECURRING !== $this->billingMode) {
            $this->billingMode = BillingMode::MANUAL_RECURRING;
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
    public function validateExternalIsPrepaid(ExecutionContextInterface $context): void
    {
        if (PaymentMethod::EXTERNAL !== $this->paymentMethod) {
            return;
        }

        if ('free' === $this->monthlyPriceMode) {
            return;
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
        if (!$this->isExternallyPrepaid) {
            return;
        }

        if (null === $this->paidThroughDate) {
            $context->buildViolation('Zadejte datum, do kdy je předplaceno.')
                ->atPath('paidThroughDate')
                ->addViolation();

            return;
        }

        if (null !== $this->startDate && $this->paidThroughDate < $this->startDate) {
            $context->buildViolation('Datum předplatby nemůže být před datem začátku.')
                ->atPath('paidThroughDate')
                ->addViolation();
        }

        if (RentalType::LIMITED === $this->rentalType
            && null !== $this->endDate
            && $this->paidThroughDate > $this->endDate
        ) {
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
