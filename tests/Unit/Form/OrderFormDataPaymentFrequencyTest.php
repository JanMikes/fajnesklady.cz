<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Form\OrderFormData;
use App\Tests\Mock\MockAddressValidator;
use App\Validator\AddressExistsValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;

final class OrderFormDataPaymentFrequencyTest extends TestCase
{
    public function testShortRentalYearlyFails(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->startDate = new \DateTimeImmutable('+1 day');
        $data->endDate = new \DateTimeImmutable('+181 days'); // ~6 months
        $data->paymentFrequency = PaymentFrequency::YEARLY;

        $violations = $this->validator()->validate($data);
        $messages = array_map(static fn ($v): string => (string) $v->getMessage(), iterator_to_array($violations));

        self::assertContains('Roční platba je dostupná pouze pro pronájem na 12 měsíců a déle.', $messages);
    }

    public function testAtThresholdYearlyPasses(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->startDate = new \DateTimeImmutable('+1 day');
        $data->endDate = new \DateTimeImmutable('+361 days'); // 360 days exactly
        $data->paymentFrequency = PaymentFrequency::YEARLY;

        $violations = $this->validator()->validate($data);
        $frequencyViolations = array_filter(
            iterator_to_array($violations),
            static fn ($v): bool => 'paymentFrequency' === $v->getPropertyPath(),
        );

        self::assertCount(0, $frequencyViolations);
    }

    public function testYearlyDerivesManualRecurring(): void
    {
        // Yearly cadence can never run on a stored card — the derivation
        // overwrites a stale AUTO value (e.g. restored from session).
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->startDate = new \DateTimeImmutable('+1 day');
        $data->endDate = new \DateTimeImmutable('+366 days'); // 365 days
        $data->paymentFrequency = PaymentFrequency::YEARLY;
        $data->billingMode = BillingMode::AUTO_RECURRING;

        $violations = $this->validator()->validate($data);

        self::assertCount(0, $violations);
        self::assertSame(BillingMode::MANUAL_RECURRING, $data->billingMode);
    }

    public function testGopayYearlyFails(): void
    {
        // Cards only establish recurring monthly payments (spec 076) — yearly
        // is bank-transfer territory even when the rental is long enough.
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::GOPAY;
        $data->startDate = new \DateTimeImmutable('+1 day');
        $data->endDate = new \DateTimeImmutable('+366 days'); // 365 days
        $data->paymentFrequency = PaymentFrequency::YEARLY;

        $violations = $this->validator()->validate($data);
        $frequencyViolations = array_filter(
            iterator_to_array($violations),
            static fn ($v): bool => 'paymentFrequency' === $v->getPropertyPath(),
        );
        $messages = array_map(static fn ($v): string => (string) $v->getMessage(), $frequencyViolations);

        self::assertContains('Roční platbu lze platit pouze bankovním převodem.', $messages);
    }

    public function testGopayUpfrontFails(): void
    {
        // Spec 078: the whole-rental upfront payment is bank-transfer only.
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::GOPAY;
        $data->startDate = new \DateTimeImmutable('+1 day');
        $data->endDate = new \DateTimeImmutable('+46 days'); // 45 days
        $data->paymentFrequency = PaymentFrequency::ONE_TIME;

        $violations = $this->validator()->validate($data);
        $frequencyViolations = array_filter(
            iterator_to_array($violations),
            static fn ($v): bool => 'paymentFrequency' === $v->getPropertyPath(),
        );
        $messages = array_map(static fn ($v): string => (string) $v->getMessage(), $frequencyViolations);

        self::assertContains('Jednorázovou platbu celé částky lze provést pouze bankovním převodem.', $messages);
    }

    public function testBankUpfrontDerivesOneTimeBillingMode(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->startDate = new \DateTimeImmutable('+1 day');
        $data->endDate = new \DateTimeImmutable('+46 days'); // 45 days
        $data->paymentFrequency = PaymentFrequency::ONE_TIME;
        $data->billingMode = BillingMode::AUTO_RECURRING; // stale session value gets overwritten

        $violations = $this->validator()->validate($data);

        self::assertCount(0, $violations);
        self::assertSame(BillingMode::ONE_TIME, $data->billingMode);
    }

    public function testBankUpfrontShortRentalHasNoFrequencyViolation(): void
    {
        // < 31 days + ONE_TIME is semantically what already happens for short
        // bank rentals — derive returns ONE_TIME regardless, so a stale session
        // value can never produce a wrong order (spec 078).
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->startDate = new \DateTimeImmutable('+1 day');
        $data->endDate = new \DateTimeImmutable('+15 days'); // 14 days
        $data->paymentFrequency = PaymentFrequency::ONE_TIME;

        $violations = $this->validator()->validate($data);

        self::assertCount(0, $violations);
        self::assertSame(BillingMode::ONE_TIME, $data->billingMode);
    }

    private function validData(): OrderFormData
    {
        $data = new OrderFormData();
        $data->email = 'jan@example.com';
        $data->firstName = 'Jan';
        $data->lastName = 'Novak';
        $data->phone = '+420123456789';
        $data->birthDate = new \DateTimeImmutable('1990-01-01');
        $data->billingStreet = 'Hlavní 1';
        $data->billingCity = 'Praha';
        $data->billingPostalCode = '110 00';

        return $data;
    }

    private function validator(): \Symfony\Component\Validator\Validator\ValidatorInterface
    {
        $factory = new ConstraintValidatorFactory([
            AddressExistsValidator::class => new AddressExistsValidator(new MockAddressValidator()),
        ]);

        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->setConstraintValidatorFactory($factory)
            ->getValidator();
    }
}
