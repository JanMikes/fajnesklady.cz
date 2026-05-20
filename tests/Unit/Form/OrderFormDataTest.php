<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Enum\ExpectedDuration;
use App\Enum\RentalType;
use App\Form\OrderFormData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

class OrderFormDataTest extends TestCase
{
    public function testValidatesStartDateCannotBeInPast(): void
    {
        $formData = new OrderFormData();
        $formData->startDate = new \DateTimeImmutable('-1 day');
        $formData->rentalType = RentalType::LIMITED;
        $formData->endDate = new \DateTimeImmutable('+7 days');

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects($this->once())
            ->method('atPath')
            ->with('startDate')
            ->willReturnSelf();
        $violationBuilder->expects($this->once())
            ->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->once())
            ->method('buildViolation')
            ->with('Datum začátku nemůže být v minulosti.')
            ->willReturn($violationBuilder);

        $formData->validateDates($context);
    }

    public function testValidatesEndDateRequiredForLimitedRental(): void
    {
        $formData = new OrderFormData();
        $formData->startDate = new \DateTimeImmutable('+1 day');
        $formData->rentalType = RentalType::LIMITED;
        $formData->endDate = null;

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects($this->once())
            ->method('atPath')
            ->with('endDate')
            ->willReturnSelf();
        $violationBuilder->expects($this->once())
            ->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->once())
            ->method('buildViolation')
            ->with('Pro omezený pronájem je vyžadováno datum konce.')
            ->willReturn($violationBuilder);

        $formData->validateDates($context);
    }

    public function testValidatesEndDateMustBeAfterStartDate(): void
    {
        $formData = new OrderFormData();
        $formData->startDate = new \DateTimeImmutable('+7 days');
        $formData->rentalType = RentalType::LIMITED;
        $formData->endDate = new \DateTimeImmutable('+1 day');

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects($this->once())
            ->method('atPath')
            ->with('endDate')
            ->willReturnSelf();
        $violationBuilder->expects($this->once())
            ->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->once())
            ->method('buildViolation')
            ->with('Minimální doba pronájmu je 7 dní.')
            ->willReturn($violationBuilder);

        $formData->validateDates($context);
    }

    public function testValidatesEndDateEqualToStartDateIsInvalid(): void
    {
        $sameDate = new \DateTimeImmutable('+5 days');
        $formData = new OrderFormData();
        $formData->startDate = $sameDate;
        $formData->rentalType = RentalType::LIMITED;
        $formData->endDate = $sameDate;

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects($this->once())
            ->method('atPath')
            ->with('endDate')
            ->willReturnSelf();
        $violationBuilder->expects($this->once())
            ->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->once())
            ->method('buildViolation')
            ->with('Minimální doba pronájmu je 7 dní.')
            ->willReturn($violationBuilder);

        $formData->validateDates($context);
    }

    public function testValidatesLimitedRentalShorterThanSevenDaysIsInvalid(): void
    {
        $formData = new OrderFormData();
        $formData->startDate = new \DateTimeImmutable('+1 day');
        $formData->rentalType = RentalType::LIMITED;
        $formData->endDate = new \DateTimeImmutable('+6 days');

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects($this->once())
            ->method('atPath')
            ->with('endDate')
            ->willReturnSelf();
        $violationBuilder->expects($this->once())
            ->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->once())
            ->method('buildViolation')
            ->with('Minimální doba pronájmu je 7 dní.')
            ->willReturn($violationBuilder);

        $formData->validateDates($context);
    }

    public function testUnlimitedRentalAllowsNullEndDate(): void
    {
        $formData = new OrderFormData();
        $formData->startDate = new \DateTimeImmutable('+1 day');
        $formData->rentalType = RentalType::UNLIMITED;
        $formData->endDate = null;

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())
            ->method('buildViolation');

        $formData->validateDates($context);
    }

    public function testValidDatesProduceNoViolations(): void
    {
        $formData = new OrderFormData();
        $formData->startDate = new \DateTimeImmutable('+1 day');
        $formData->rentalType = RentalType::LIMITED;
        $formData->endDate = new \DateTimeImmutable('+30 days');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())
            ->method('buildViolation');

        $formData->validateDates($context);
    }

    public function testNullStartDateDoesNothing(): void
    {
        $formData = new OrderFormData();
        $formData->startDate = null;
        $formData->rentalType = RentalType::LIMITED;
        $formData->endDate = null;

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())
            ->method('buildViolation');

        $formData->validateDates($context);
    }

    public function testValidatesBirthDateRequiredForNonCompany(): void
    {
        $formData = new OrderFormData();
        $formData->invoiceToCompany = false;
        $formData->birthDate = null;

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects($this->once())
            ->method('atPath')
            ->with('birthDate')
            ->willReturnSelf();
        $violationBuilder->expects($this->once())
            ->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->once())
            ->method('buildViolation')
            ->with('Zadejte datum narození.')
            ->willReturn($violationBuilder);

        $formData->validateBirthDate($context);
    }

    public function testValidatesBirthDateSkippedForCompany(): void
    {
        $formData = new OrderFormData();
        $formData->invoiceToCompany = true;
        $formData->birthDate = null;

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())
            ->method('buildViolation');

        $formData->validateBirthDate($context);
    }

    public function testValidatesBirthDateRejectsUnder18(): void
    {
        $formData = new OrderFormData();
        $formData->invoiceToCompany = false;
        // Birthday is tomorrow's date 17 years ago → 17 years and 364 days old → < 18.
        $formData->birthDate = (new \DateTimeImmutable('today'))->modify('-18 years')->modify('+1 day');

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects($this->once())
            ->method('atPath')
            ->with('birthDate')
            ->willReturnSelf();
        $violationBuilder->expects($this->once())
            ->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->once())
            ->method('buildViolation')
            ->with('Nájemce musí být starší 18 let.')
            ->willReturn($violationBuilder);

        $formData->validateBirthDate($context);
    }

    public function testValidatesBirthDateAcceptsExactly18(): void
    {
        $formData = new OrderFormData();
        $formData->invoiceToCompany = false;
        // 18th birthday is today.
        $formData->birthDate = (new \DateTimeImmutable('today'))->modify('-18 years');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())
            ->method('buildViolation');

        $formData->validateBirthDate($context);
    }

    public function testValidatesBirthDateAcceptsAdult(): void
    {
        $formData = new OrderFormData();
        $formData->invoiceToCompany = false;
        $formData->birthDate = new \DateTimeImmutable('1992-06-01');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())
            ->method('buildViolation');

        $formData->validateBirthDate($context);
    }

    public function testValidatesBirthDateRejectsFutureDate(): void
    {
        $formData = new OrderFormData();
        $formData->invoiceToCompany = false;
        $formData->birthDate = (new \DateTimeImmutable('today'))->modify('+1 day');

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects($this->once())
            ->method('atPath')
            ->with('birthDate')
            ->willReturnSelf();
        $violationBuilder->expects($this->once())
            ->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->once())
            ->method('buildViolation')
            ->with('Nájemce musí být starší 18 let.')
            ->willReturn($violationBuilder);

        $formData->validateBirthDate($context);
    }

    public function testHasCompleteAddressReturnsTrueWhenAllThreeFieldsArePresent(): void
    {
        $formData = new OrderFormData();
        $formData->billingStreet = 'Vinohradská 52';
        $formData->billingCity = 'Praha';
        $formData->billingPostalCode = '120 00';

        self::assertTrue($formData->hasCompleteAddress());
    }

    public function testHasCompleteAddressReturnsFalseWhenAnyFieldIsBlank(): void
    {
        $formData = new OrderFormData();
        $formData->billingStreet = 'Vinohradská 52';
        $formData->billingCity = '';
        $formData->billingPostalCode = '120 00';

        self::assertFalse($formData->hasCompleteAddress());
    }

    public function testSessionRoundTripPersistsAddressOverride(): void
    {
        $formData = new OrderFormData();
        $formData->billingStreet = 'Asdfghj 999';
        $formData->billingCity = 'Tatratata';
        $formData->billingPostalCode = '99999';
        $formData->addressOverride = true;

        $restored = OrderFormData::fromSessionArray($formData->toSessionArray());

        self::assertTrue($restored->addressOverride);
        self::assertSame('Asdfghj 999', $restored->billingStreet);
    }

    public function testValidatesExpectedDurationRequiredForUnlimitedRental(): void
    {
        $formData = new OrderFormData();
        $formData->rentalType = RentalType::UNLIMITED;
        $formData->expectedDuration = null;

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects($this->once())
            ->method('atPath')
            ->with('expectedDuration')
            ->willReturnSelf();
        $violationBuilder->expects($this->once())
            ->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->once())
            ->method('buildViolation')
            ->with('Vyberte předpokládanou dobu pronájmu.')
            ->willReturn($violationBuilder);

        $formData->validateExpectedDuration($context);
    }

    public function testValidatesExpectedDurationAcceptsValueForUnlimitedRental(): void
    {
        $formData = new OrderFormData();
        $formData->rentalType = RentalType::UNLIMITED;
        $formData->expectedDuration = ExpectedDuration::SHORT;

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())
            ->method('buildViolation');

        $formData->validateExpectedDuration($context);
    }

    public function testValidatesExpectedDurationSkippedForLimitedRental(): void
    {
        $formData = new OrderFormData();
        $formData->rentalType = RentalType::LIMITED;
        $formData->expectedDuration = null;

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())
            ->method('buildViolation');

        $formData->validateExpectedDuration($context);
    }

    public function testSessionRoundTripPersistsExpectedDuration(): void
    {
        $formData = new OrderFormData();
        $formData->rentalType = RentalType::UNLIMITED;
        $formData->expectedDuration = ExpectedDuration::LONG;

        $restored = OrderFormData::fromSessionArray($formData->toSessionArray());

        self::assertSame(ExpectedDuration::LONG, $restored->expectedDuration);
    }
}
