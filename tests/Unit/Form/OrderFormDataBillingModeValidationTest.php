<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Enum\BillingMode;
use App\Enum\RentalType;
use App\Form\OrderFormData;
use App\Tests\Mock\MockAddressValidator;
use App\Validator\AddressExistsValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;

final class OrderFormDataBillingModeValidationTest extends TestCase
{
    public function testUnlimitedWithManualFails(): void
    {
        $data = $this->validData();
        $data->rentalType = RentalType::UNLIMITED;
        $data->endDate = null;
        $data->billingMode = BillingMode::MANUAL_RECURRING;

        $violations = $this->validator()->validate($data);
        $messages = array_map(static fn ($v): string => (string) $v->getMessage(), iterator_to_array($violations));

        self::assertContains('Pro pronájem na dobu neurčitou je dostupná pouze automatická platba kartou.', $messages);
    }

    public function testUnlimitedWithAutoPasses(): void
    {
        $data = $this->validData();
        $data->rentalType = RentalType::UNLIMITED;
        $data->endDate = null;
        $data->billingMode = BillingMode::AUTO_RECURRING;

        $violations = $this->validator()->validate($data);
        $billingModeViolations = array_filter(
            iterator_to_array($violations),
            static fn ($v): bool => 'billingMode' === $v->getPropertyPath(),
        );

        self::assertCount(0, $billingModeViolations);
    }

    public function testShortLimitedSilentlyPinsToOneTime(): void
    {
        // Short LIMITED hides the billingMode radio (only LIMITED ≥ 28 days
        // surfaces the choice), so the FormData default AUTO_RECURRING reaches
        // validation unchanged. Auto-correct silently — raising a violation on
        // a hidden field would stall the form with no visible error and a
        // generic 422 in the console.
        $data = $this->validData();
        $data->rentalType = RentalType::LIMITED;
        $data->startDate = new \DateTimeImmutable('2025-06-16');
        $data->endDate = new \DateTimeImmutable('2025-06-23'); // 7 days
        $data->billingMode = BillingMode::AUTO_RECURRING;

        $violations = $this->validator()->validate($data);
        $billingModeViolations = array_filter(
            iterator_to_array($violations),
            static fn ($v): bool => 'billingMode' === $v->getPropertyPath(),
        );

        self::assertCount(0, $billingModeViolations);
        self::assertSame(BillingMode::ONE_TIME, $data->billingMode);
    }

    public function testShortLimitedAutoCorrectsManualToOneTime(): void
    {
        $data = $this->validData();
        $data->rentalType = RentalType::LIMITED;
        $data->startDate = new \DateTimeImmutable('2025-06-16');
        $data->endDate = new \DateTimeImmutable('2025-06-23'); // 7 days
        $data->billingMode = BillingMode::MANUAL_RECURRING;

        $violations = $this->validator()->validate($data);
        $billingModeViolations = array_filter(
            iterator_to_array($violations),
            static fn ($v): bool => 'billingMode' === $v->getPropertyPath(),
        );

        self::assertCount(0, $billingModeViolations);
        self::assertSame(BillingMode::ONE_TIME, $data->billingMode);
    }

    public function testLongLimitedWithManualPasses(): void
    {
        $data = $this->validData();
        $data->rentalType = RentalType::LIMITED;
        $data->startDate = new \DateTimeImmutable('2025-06-16');
        $data->endDate = new \DateTimeImmutable('2025-07-31'); // 45 days
        $data->billingMode = BillingMode::MANUAL_RECURRING;

        $violations = $this->validator()->validate($data);
        $billingModeViolations = array_filter(
            iterator_to_array($violations),
            static fn ($v): bool => 'billingMode' === $v->getPropertyPath(),
        );

        self::assertCount(0, $billingModeViolations);
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
