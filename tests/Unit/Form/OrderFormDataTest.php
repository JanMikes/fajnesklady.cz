<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
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

    public function testValidatesEndDateRequired(): void
    {
        $formData = new OrderFormData();
        $formData->startDate = new \DateTimeImmutable('+1 day');
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
            ->with('Vyberte datum konce pronájmu.')
            ->willReturn($violationBuilder);

        $formData->validateDates($context);
    }

    public function testValidatesEndDateMustBeAfterStartDate(): void
    {
        $formData = new OrderFormData();
        $formData->startDate = new \DateTimeImmutable('+7 days');
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

    public function testValidatesRentalShorterThanSevenDaysIsInvalid(): void
    {
        $formData = new OrderFormData();
        $formData->startDate = new \DateTimeImmutable('+1 day');
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

    public function testRentalLongerThanOneYearIsValid(): void
    {
        $formData = new OrderFormData();
        $formData->startDate = new \DateTimeImmutable('+1 day');
        $formData->endDate = new \DateTimeImmutable('+3 years');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())
            ->method('buildViolation');

        $formData->validateDates($context);
    }

    public function testValidDatesProduceNoViolations(): void
    {
        $formData = new OrderFormData();
        $formData->startDate = new \DateTimeImmutable('+1 day');
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

    public function testValidatesGopayWithYearlyFrequencyIsRejected(): void
    {
        $formData = new OrderFormData();
        $formData->paymentMethod = PaymentMethod::GOPAY;
        $formData->paymentFrequency = PaymentFrequency::YEARLY;

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects($this->once())
            ->method('atPath')
            ->with('paymentFrequency')
            ->willReturnSelf();
        $violationBuilder->expects($this->once())
            ->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->once())
            ->method('buildViolation')
            ->with('Roční platbu lze platit pouze bankovním převodem.')
            ->willReturn($violationBuilder);

        $formData->validatePaymentMethod($context);
    }

    public function testValidatesGopayShortRentalIsRejected(): void
    {
        $formData = new OrderFormData();
        $formData->paymentMethod = PaymentMethod::GOPAY;
        $formData->paymentFrequency = PaymentFrequency::MONTHLY;
        $formData->startDate = new \DateTimeImmutable('+1 day');
        $formData->endDate = new \DateTimeImmutable('+11 days');

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects($this->once())
            ->method('atPath')
            ->with('paymentMethod')
            ->willReturnSelf();
        $violationBuilder->expects($this->once())
            ->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->once())
            ->method('buildViolation')
            ->with('Platba kartou je dostupná pro pronájmy od 31 dnů. Kratší pronájem zaplatíte bankovním převodem.')
            ->willReturn($violationBuilder);

        $formData->validatePaymentMethod($context);
    }

    public function testValidatesBankTransferShortRentalIsAllowed(): void
    {
        $formData = new OrderFormData();
        $formData->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $formData->paymentFrequency = PaymentFrequency::MONTHLY;
        $formData->startDate = new \DateTimeImmutable('+1 day');
        $formData->endDate = new \DateTimeImmutable('+11 days');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())
            ->method('buildViolation');

        $formData->validatePaymentMethod($context);
    }

    public function testValidatesYearlyFrequencyBelowThresholdIsRejected(): void
    {
        $formData = new OrderFormData();
        $formData->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $formData->paymentFrequency = PaymentFrequency::YEARLY;
        $formData->startDate = new \DateTimeImmutable('+1 day');
        $formData->endDate = new \DateTimeImmutable('+90 days');

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects($this->once())
            ->method('atPath')
            ->with('paymentFrequency')
            ->willReturnSelf();
        $violationBuilder->expects($this->once())
            ->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->once())
            ->method('buildViolation')
            ->with('Roční platba je dostupná pouze pro pronájem na 12 měsíců a déle.')
            ->willReturn($violationBuilder);

        $formData->validatePaymentFrequency($context);
    }

    public function testDeriveBillingModeSetsAutoRecurringForGopay(): void
    {
        $formData = new OrderFormData();
        $formData->paymentMethod = PaymentMethod::GOPAY;
        $formData->paymentFrequency = PaymentFrequency::MONTHLY;
        $formData->startDate = new \DateTimeImmutable('+1 day');
        $formData->endDate = new \DateTimeImmutable('+90 days');
        $formData->billingMode = null;

        $formData->deriveBillingMode($this->createStub(ExecutionContextInterface::class));

        self::assertSame(BillingMode::AUTO_RECURRING, $formData->billingMode);
    }

    public function testDeriveBillingModeSetsOneTimeForShortBankTransfer(): void
    {
        $formData = new OrderFormData();
        $formData->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $formData->paymentFrequency = PaymentFrequency::MONTHLY;
        $formData->startDate = new \DateTimeImmutable('+1 day');
        $formData->endDate = new \DateTimeImmutable('+11 days');
        $formData->billingMode = null;

        $formData->deriveBillingMode($this->createStub(ExecutionContextInterface::class));

        self::assertSame(BillingMode::ONE_TIME, $formData->billingMode);
    }

    public function testSessionRoundTripPersistsPaymentSelection(): void
    {
        $formData = new OrderFormData();
        $formData->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $formData->paymentFrequency = PaymentFrequency::YEARLY;
        $formData->billingMode = BillingMode::MANUAL_RECURRING;

        $restored = OrderFormData::fromSessionArray($formData->toSessionArray());

        self::assertSame(PaymentMethod::BANK_TRANSFER, $restored->paymentMethod);
        self::assertSame(PaymentFrequency::YEARLY, $restored->paymentFrequency);
        self::assertSame(BillingMode::MANUAL_RECURRING, $restored->billingMode);
    }
}
