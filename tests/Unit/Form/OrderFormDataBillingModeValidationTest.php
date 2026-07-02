<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Enum\BillingMode;
use App\Enum\PaymentMethod;
use App\Form\OrderFormData;
use App\Tests\Mock\MockAddressValidator;
use App\Validator\AddressExistsValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;

final class OrderFormDataBillingModeValidationTest extends TestCase
{
    public function testGopayDerivesAutoRecurring(): void
    {
        // Billing mode is never a user choice (spec 076): a stale MANUAL value
        // (e.g. restored from session) gets overwritten by the derivation.
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::GOPAY;
        $data->startDate = new \DateTimeImmutable('+1 day');
        $data->endDate = new \DateTimeImmutable('+46 days'); // 45 days
        $data->billingMode = BillingMode::MANUAL_RECURRING;

        $violations = $this->validator()->validate($data);

        self::assertCount(0, $violations);
        self::assertSame(BillingMode::AUTO_RECURRING, $data->billingMode);
    }

    public function testGopayAtThresholdPasses(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::GOPAY;
        $data->startDate = new \DateTimeImmutable('+1 day');
        $data->endDate = new \DateTimeImmutable('+32 days'); // 31 days exactly

        $violations = $this->validator()->validate($data);

        self::assertCount(0, $violations);
        self::assertSame(BillingMode::AUTO_RECURRING, $data->billingMode);
    }

    public function testShortBankTransferDerivesOneTime(): void
    {
        // Rentals shorter than 31 days paid by bank transfer are a single
        // one-shot payment — the FormData default AUTO_RECURRING never survives
        // the derivation callback.
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->startDate = new \DateTimeImmutable('+1 day');
        $data->endDate = new \DateTimeImmutable('+8 days'); // 7 days
        $data->billingMode = BillingMode::AUTO_RECURRING;

        $violations = $this->validator()->validate($data);

        self::assertCount(0, $violations);
        self::assertSame(BillingMode::ONE_TIME, $data->billingMode);
    }

    public function testShortGopayFailsOnPaymentMethod(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::GOPAY;
        $data->startDate = new \DateTimeImmutable('+1 day');
        $data->endDate = new \DateTimeImmutable('+8 days'); // 7 days

        $violations = $this->validator()->validate($data);
        $paymentMethodViolations = array_filter(
            iterator_to_array($violations),
            static fn ($v): bool => 'paymentMethod' === $v->getPropertyPath(),
        );
        $messages = array_map(static fn ($v): string => (string) $v->getMessage(), $paymentMethodViolations);

        self::assertContains('Platba kartou je dostupná pro pronájmy od 31 dnů. Kratší pronájem zaplatíte bankovním převodem.', $messages);
    }

    public function testLongBankTransferDerivesManualRecurring(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->startDate = new \DateTimeImmutable('+1 day');
        $data->endDate = new \DateTimeImmutable('+46 days'); // 45 days
        $data->billingMode = BillingMode::AUTO_RECURRING;

        $violations = $this->validator()->validate($data);

        self::assertCount(0, $violations);
        self::assertSame(BillingMode::MANUAL_RECURRING, $data->billingMode);
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
