<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Service\PriceCalculator;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Spec 088: the customer's payment method + frequency choice for a deferred
 * admin onboarding, made on the dedicated choice step before signing.
 */
final class OnboardingPaymentChoiceFormData
{
    #[Assert\NotNull(message: 'Vyberte způsob platby.')]
    public ?PaymentMethod $paymentMethod = null;

    #[Assert\NotNull(message: 'Vyberte frekvenci platby.')]
    public ?PaymentFrequency $paymentFrequency = PaymentFrequency::MONTHLY;

    /**
     * The fixed rental length (days) of the order being signed, injected by the
     * component so the matrix rules can reference it. Not a form field.
     */
    public ?int $rentalDays = null;

    #[Assert\Callback]
    public function validateMatrix(ExecutionContextInterface $context): void
    {
        if (null === $this->rentalDays) {
            return;
        }

        if (PaymentMethod::GOPAY === $this->paymentMethod) {
            if ($this->rentalDays < PriceCalculator::WEEKLY_THRESHOLD_DAYS) {
                $context->buildViolation('Platba kartou je dostupná pro pronájmy od 31 dnů. Kratší pronájem zaplatíte bankovním převodem.')
                    ->atPath('paymentMethod')
                    ->addViolation();
            }

            if (PaymentFrequency::YEARLY === $this->paymentFrequency || PaymentFrequency::ONE_TIME === $this->paymentFrequency) {
                $context->buildViolation('Roční ani jednorázovou platbu nelze platit kartou — zvolte bankovní převod.')
                    ->atPath('paymentFrequency')
                    ->addViolation();
            }
        }

        if (PaymentFrequency::YEARLY === $this->paymentFrequency && $this->rentalDays < PriceCalculator::YEARLY_THRESHOLD_DAYS) {
            $context->buildViolation('Roční platba je dostupná pouze pro pronájem na 12 měsíců a déle.')
                ->atPath('paymentFrequency')
                ->addViolation();
        }
    }
}
