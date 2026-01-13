<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

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
            ->with('Datum konce musí být po datu začátku.')
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
            ->with('Datum konce musí být po datu začátku.')
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
}
