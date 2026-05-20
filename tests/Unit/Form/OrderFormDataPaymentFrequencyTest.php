<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use App\Form\OrderFormData;
use App\Tests\Mock\MockAddressValidator;
use App\Validator\AddressExistsValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;

final class OrderFormDataPaymentFrequencyTest extends TestCase
{
    public function testShortLimitedYearlyFails(): void
    {
        $data = $this->validData();
        $data->rentalType = RentalType::LIMITED;
        $data->startDate = new \DateTimeImmutable('2026-06-01');
        $data->endDate = new \DateTimeImmutable('2026-12-01'); // ~6 months
        $data->paymentFrequency = PaymentFrequency::YEARLY;
        $data->billingMode = BillingMode::MANUAL_RECURRING;

        $violations = $this->validator()->validate($data);
        $messages = array_map(static fn ($v): string => (string) $v->getMessage(), iterator_to_array($violations));

        self::assertContains('Roční platba je dostupná pouze pro pronájem na 12 měsíců a déle.', $messages);
    }

    public function testLimitedAtThresholdYearlyPasses(): void
    {
        $data = $this->validData();
        $data->rentalType = RentalType::LIMITED;
        $data->startDate = new \DateTimeImmutable('2026-06-01');
        $data->endDate = new \DateTimeImmutable('2027-05-27'); // 360 days exactly
        $data->paymentFrequency = PaymentFrequency::YEARLY;
        $data->billingMode = BillingMode::MANUAL_RECURRING;

        $violations = $this->validator()->validate($data);
        $frequencyViolations = array_filter(
            iterator_to_array($violations),
            static fn ($v): bool => 'paymentFrequency' === $v->getPropertyPath(),
        );

        self::assertCount(0, $frequencyViolations);
    }

    public function testUnlimitedYearlyAlwaysEligible(): void
    {
        $data = $this->validData();
        $data->rentalType = RentalType::UNLIMITED;
        $data->endDate = null;
        $data->expectedDuration = \App\Enum\ExpectedDuration::MEDIUM;
        $data->paymentFrequency = PaymentFrequency::YEARLY;
        $data->billingMode = BillingMode::MANUAL_RECURRING;

        $violations = $this->validator()->validate($data);
        $frequencyViolations = array_filter(
            iterator_to_array($violations),
            static fn ($v): bool => 'paymentFrequency' === $v->getPropertyPath(),
        );

        self::assertCount(0, $frequencyViolations);
    }

    public function testYearlyAutoCorrectsAutoToManual(): void
    {
        $data = $this->validData();
        $data->rentalType = RentalType::UNLIMITED;
        $data->endDate = null;
        $data->expectedDuration = \App\Enum\ExpectedDuration::MEDIUM;
        $data->paymentFrequency = PaymentFrequency::YEARLY;
        $data->billingMode = BillingMode::AUTO_RECURRING;

        $this->validator()->validate($data);

        // Validate side-effects: AUTO got silently corrected to MANUAL because
        // yearly cadence can never run on a stored card.
        self::assertSame(BillingMode::MANUAL_RECURRING, $data->billingMode);
    }

    public function testYearlySuppressesUnlimitedAutoRule(): void
    {
        // UNLIMITED + MANUAL would normally fail with "only AUTO available"
        // but YEARLY short-circuits the validateBillingMode rule.
        $data = $this->validData();
        $data->rentalType = RentalType::UNLIMITED;
        $data->endDate = null;
        $data->expectedDuration = \App\Enum\ExpectedDuration::MEDIUM;
        $data->paymentFrequency = PaymentFrequency::YEARLY;
        $data->billingMode = BillingMode::MANUAL_RECURRING;

        $violations = $this->validator()->validate($data);
        $messages = array_map(static fn ($v): string => (string) $v->getMessage(), iterator_to_array($violations));

        self::assertNotContains('Pro pronájem na dobu neurčitou je dostupná pouze automatická platba kartou.', $messages);
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
