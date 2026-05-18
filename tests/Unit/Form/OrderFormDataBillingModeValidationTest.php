<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Enum\BillingMode;
use App\Enum\RentalType;
use App\Form\OrderFormData;
use PHPUnit\Framework\TestCase;
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

    public function testShortLimitedWithManualFails(): void
    {
        $data = $this->validData();
        $data->rentalType = RentalType::LIMITED;
        $data->startDate = new \DateTimeImmutable('2025-06-16');
        $data->endDate = new \DateTimeImmutable('2025-06-23'); // 7 days
        $data->billingMode = BillingMode::MANUAL_RECURRING;

        $violations = $this->validator()->validate($data);
        $messages = array_map(static fn ($v): string => (string) $v->getMessage(), iterator_to_array($violations));

        self::assertContains('Pro krátkodobé pronájmy se platí jednorázově.', $messages);
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
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }
}
