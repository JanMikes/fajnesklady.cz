<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Form\OnboardingPaymentChoiceFormData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class OnboardingPaymentChoiceFormDataTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function messages(OnboardingPaymentChoiceFormData $data): array
    {
        $violations = $this->validator()->validate($data);

        return array_values(array_map(static fn ($v): string => (string) $v->getMessage(), iterator_to_array($violations)));
    }

    public function testCardOnShortRentalFails(): void
    {
        $data = new OnboardingPaymentChoiceFormData();
        $data->rentalDays = 20;
        $data->paymentMethod = PaymentMethod::GOPAY;
        $data->paymentFrequency = PaymentFrequency::MONTHLY;

        self::assertContains('Platba kartou je dostupná pro pronájmy od 31 dnů. Kratší pronájem zaplatíte bankovním převodem.', $this->messages($data));
    }

    public function testCardWithYearlyFails(): void
    {
        $data = new OnboardingPaymentChoiceFormData();
        $data->rentalDays = 400;
        $data->paymentMethod = PaymentMethod::GOPAY;
        $data->paymentFrequency = PaymentFrequency::YEARLY;

        self::assertContains('Roční ani jednorázovou platbu nelze platit kartou — zvolte bankovní převod.', $this->messages($data));
    }

    public function testCardWithOneTimeFails(): void
    {
        $data = new OnboardingPaymentChoiceFormData();
        $data->rentalDays = 60;
        $data->paymentMethod = PaymentMethod::GOPAY;
        $data->paymentFrequency = PaymentFrequency::ONE_TIME;

        self::assertContains('Roční ani jednorázovou platbu nelze platit kartou — zvolte bankovní převod.', $this->messages($data));
    }

    public function testYearlyOnShortRentalFails(): void
    {
        $data = new OnboardingPaymentChoiceFormData();
        $data->rentalDays = 200;
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->paymentFrequency = PaymentFrequency::YEARLY;

        self::assertContains('Roční platba je dostupná pouze pro pronájem na 12 měsíců a déle.', $this->messages($data));
    }

    public function testMissingMethodFails(): void
    {
        $data = new OnboardingPaymentChoiceFormData();
        $data->rentalDays = 90;
        $data->paymentMethod = null;
        $data->paymentFrequency = PaymentFrequency::MONTHLY;

        self::assertContains('Vyberte způsob platby.', $this->messages($data));
    }

    public function testCardOnSixMonthMonthlyPasses(): void
    {
        $data = new OnboardingPaymentChoiceFormData();
        $data->rentalDays = 180;
        $data->paymentMethod = PaymentMethod::GOPAY;
        $data->paymentFrequency = PaymentFrequency::MONTHLY;

        self::assertSame([], $this->messages($data));
    }

    public function testBankMonthlyPasses(): void
    {
        $data = new OnboardingPaymentChoiceFormData();
        $data->rentalDays = 90;
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->paymentFrequency = PaymentFrequency::MONTHLY;

        self::assertSame([], $this->messages($data));
    }

    public function testBankYearlyOnLongRentalPasses(): void
    {
        $data = new OnboardingPaymentChoiceFormData();
        $data->rentalDays = 400;
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->paymentFrequency = PaymentFrequency::YEARLY;

        self::assertSame([], $this->messages($data));
    }

    public function testBankOneTimeOnMediumRentalPasses(): void
    {
        $data = new OnboardingPaymentChoiceFormData();
        $data->rentalDays = 60;
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->paymentFrequency = PaymentFrequency::ONE_TIME;

        self::assertSame([], $this->messages($data));
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }
}
