<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class OrderFormData
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

    public ?string $plainPassword = null;

    public bool $invoiceToCompany = false;

    #[Assert\Length(max: 255, maxMessage: 'Název firmy může mít maximálně {{ limit }} znaků.')]
    public ?string $companyName = null;

    #[Assert\Length(exactly: 8, exactMessage: 'IČO musí mít přesně {{ limit }} číslic.')]
    #[Assert\Regex(pattern: '/^\d{8}$/', message: 'IČO musí obsahovat pouze číslice.')]
    public ?string $companyId = null;

    #[Assert\Length(max: 14, maxMessage: 'DIČ může mít maximálně {{ limit }} znaků.')]
    #[Assert\Regex(pattern: '/^(CZ\d{8,10})?$/', message: 'DIČ musí být ve formátu CZxxxxxxxx.')]
    public ?string $companyVatId = null;

    #[Assert\Length(max: 255, maxMessage: 'Ulice může mít maximálně {{ limit }} znaků.')]
    public ?string $billingStreet = null;

    #[Assert\Length(max: 100, maxMessage: 'Město může mít maximálně {{ limit }} znaků.')]
    public ?string $billingCity = null;

    #[Assert\Length(max: 10, maxMessage: 'PSČ může mít maximálně {{ limit }} znaků.')]
    public ?string $billingPostalCode = null;

    #[Assert\NotNull(message: 'Vyberte typ pronájmu.')]
    public ?RentalType $rentalType = RentalType::LIMITED;

    public ?PaymentFrequency $paymentFrequency = PaymentFrequency::MONTHLY;

    #[Assert\NotNull(message: 'Vyberte datum začátku.')]
    public ?\DateTimeImmutable $startDate = null;

    public ?\DateTimeImmutable $endDate = null;

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

        if (null === $this->billingStreet || '' === $this->billingStreet) {
            $context->buildViolation('Zadejte fakturační ulici.')
                ->atPath('billingStreet')
                ->addViolation();
        }

        if (null === $this->billingCity || '' === $this->billingCity) {
            $context->buildViolation('Zadejte fakturační město.')
                ->atPath('billingCity')
                ->addViolation();
        }

        if (null === $this->billingPostalCode || '' === $this->billingPostalCode) {
            $context->buildViolation('Zadejte fakturační PSČ.')
                ->atPath('billingPostalCode')
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

            if ($this->endDate <= $this->startDate) {
                $context->buildViolation('Datum konce musí být po datu začátku.')
                    ->atPath('endDate')
                    ->addViolation();
            }
        }
    }

    public static function fromUser(User $user): self
    {
        $formData = new self();
        $formData->email = $user->email;
        $formData->firstName = $user->firstName;
        $formData->lastName = $user->lastName;
        $formData->phone = $user->phone;
        $formData->companyName = $user->companyName;
        $formData->companyId = $user->companyId;
        $formData->companyVatId = $user->companyVatId;
        $formData->billingStreet = $user->billingStreet;
        $formData->billingCity = $user->billingCity;
        $formData->billingPostalCode = $user->billingPostalCode;
        $formData->invoiceToCompany = null !== $user->companyId && '' !== $user->companyId;

        return $formData;
    }
}
