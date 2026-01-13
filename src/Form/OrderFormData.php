<?php

declare(strict_types=1);

namespace App\Form;

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

    #[Assert\NotNull(message: 'Vyberte typ pronájmu.')]
    public ?RentalType $rentalType = RentalType::LIMITED;

    public ?PaymentFrequency $paymentFrequency = PaymentFrequency::MONTHLY;

    #[Assert\NotNull(message: 'Vyberte datum začátku.')]
    public ?\DateTimeImmutable $startDate = null;

    public ?\DateTimeImmutable $endDate = null;

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
}
