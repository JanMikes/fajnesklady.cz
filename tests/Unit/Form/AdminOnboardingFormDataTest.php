<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Enum\BillingMode;
use App\Enum\ExpectedDuration;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Enum\RentalType;
use App\Form\AdminOnboardingFormData;
use App\Tests\Mock\MockAddressValidator;
use App\Validator\AddressExistsValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AdminOnboardingFormDataTest extends TestCase
{
    public function testValidDataPassesValidation(): void
    {
        $data = $this->validData();

        $violations = $this->validator()->validate($data);

        self::assertCount(0, $violations);
    }

    public function testCompanyInfoRequiredWhenInvoiceToCompany(): void
    {
        $data = $this->validData();
        $data->invoiceToCompany = true;
        $data->companyId = null;
        $data->companyName = null;

        $violations = $this->violationsAt('companyId', $data);
        self::assertNotEmpty($violations);

        $violations = $this->violationsAt('companyName', $data);
        self::assertNotEmpty($violations);
    }

    public function testBirthDateRequiredForNonCompany(): void
    {
        $data = $this->validData();
        $data->invoiceToCompany = false;
        $data->birthDate = null;

        $violations = $this->violationsAt('birthDate', $data);
        self::assertNotEmpty($violations);
    }

    public function testBirthDateMinAge18(): void
    {
        $data = $this->validData();
        $data->invoiceToCompany = false;
        $data->birthDate = new \DateTimeImmutable('today -17 years');

        $violations = $this->violationsAt('birthDate', $data);
        self::assertNotEmpty($violations);
    }

    public function testLimitedRequiresEndDate(): void
    {
        $data = $this->validData();
        $data->rentalType = RentalType::LIMITED;
        $data->endDate = null;

        $violations = $this->violationsAt('endDate', $data);
        self::assertNotEmpty($violations);
    }

    public function testLimitedMinimum7Days(): void
    {
        $data = $this->validData();
        $data->rentalType = RentalType::LIMITED;
        $data->startDate = new \DateTimeImmutable('today');
        $data->endDate = new \DateTimeImmutable('today +3 days');

        $violations = $this->violationsAt('endDate', $data);
        self::assertNotEmpty($violations);
    }

    public function testBankTransferForcesManualRecurring(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->billingMode = BillingMode::AUTO_RECURRING;

        $this->validator()->validate($data);

        self::assertSame(BillingMode::MANUAL_RECURRING, $data->billingMode);
    }

    public function testUnlimitedRequiresAutoRecurring(): void
    {
        $data = $this->validData();
        $data->rentalType = RentalType::UNLIMITED;
        $data->paymentMethod = PaymentMethod::GOPAY;
        $data->billingMode = BillingMode::MANUAL_RECURRING;

        $violations = $this->violationsAt('billingMode', $data);
        self::assertNotEmpty($violations);
    }

    public function testYearlyForcesManualRecurring(): void
    {
        $data = $this->validData();
        $data->paymentFrequency = PaymentFrequency::YEARLY;
        $data->billingMode = BillingMode::AUTO_RECURRING;

        $this->validator()->validate($data);

        self::assertSame(BillingMode::MANUAL_RECURRING, $data->billingMode);
    }

    public function testYearlyWithCustomPriceIsRejected(): void
    {
        // A per-customer monthly price is not supported for yearly billing —
        // it would undercharge the first payment and be ignored on every
        // recurring yearly charge. The form must block the combination.
        $data = $this->validData();
        $data->rentalType = RentalType::LIMITED;
        $data->startDate = new \DateTimeImmutable('today');
        $data->endDate = new \DateTimeImmutable('today +400 days');
        $data->paymentFrequency = PaymentFrequency::YEARLY;
        $data->monthlyPriceMode = 'custom';
        $data->customMonthlyPriceInCzk = 1500.0;

        $violations = $this->violationsAt('customMonthlyPriceInCzk', $data);
        self::assertNotEmpty($violations);
    }

    public function testYearlyWithStandardPricePasses(): void
    {
        $data = $this->validData();
        $data->rentalType = RentalType::LIMITED;
        $data->startDate = new \DateTimeImmutable('today');
        $data->endDate = new \DateTimeImmutable('today +400 days');
        $data->paymentFrequency = PaymentFrequency::YEARLY;
        $data->monthlyPriceMode = 'standard';

        $violations = $this->violationsAt('customMonthlyPriceInCzk', $data);
        self::assertEmpty($violations);
    }

    public function testExternalNonFreeRequiresPrepaidDate(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::EXTERNAL;
        $data->monthlyPriceMode = 'standard';
        $data->isExternallyPrepaid = false;
        $data->paidThroughDate = null;

        $violations = $this->violationsAt('paidThroughDate', $data);
        self::assertNotEmpty($violations);
    }

    public function testExternalFreeSkipsPrepaidDateRequirement(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::EXTERNAL;
        $data->monthlyPriceMode = 'free';
        $data->isExternallyPrepaid = false;
        $data->paidThroughDate = null;

        $violations = $this->violationsAt('paidThroughDate', $data);
        self::assertEmpty($violations);
    }

    public function testCustomPriceModeRequiresPositiveAmount(): void
    {
        $data = $this->validData();
        $data->monthlyPriceMode = 'custom';
        $data->customMonthlyPriceInCzk = null;

        $violations = $this->violationsAt('customMonthlyPriceInCzk', $data);
        self::assertNotEmpty($violations);
    }

    public function testExpectedDurationRequiredForUnlimited(): void
    {
        $data = $this->validData();
        $data->rentalType = RentalType::UNLIMITED;
        $data->expectedDuration = null;

        $violations = $this->violationsAt('expectedDuration', $data);
        self::assertNotEmpty($violations);
    }

    public function testVariableSymbolMustBeNumeric(): void
    {
        $data = $this->validData();
        $data->variableSymbol = 'abc123';

        $violations = $this->violationsAt('variableSymbol', $data);
        self::assertNotEmpty($violations);
    }

    public function testVariableSymbolNumericPasses(): void
    {
        $data = $this->validData();
        $data->variableSymbol = '1234567890';

        $violations = $this->violationsAt('variableSymbol', $data);
        self::assertEmpty($violations);
    }

    public function testDebtWithExternalPaymentMethodFails(): void
    {
        $data = $this->validData();
        $data->debtAmountInCzk = 500.0;
        $data->paymentMethod = PaymentMethod::EXTERNAL;
        $data->isExternallyPrepaid = true;
        $data->paidThroughDate = (new \DateTimeImmutable('today'))->modify('+6 months');

        $violations = $this->violationsAt('paymentMethod', $data);
        self::assertNotEmpty($violations);
    }

    public function testDebtWithGoPayPaymentMethodPasses(): void
    {
        $data = $this->validData();
        $data->debtAmountInCzk = 500.0;
        $data->paymentMethod = PaymentMethod::GOPAY;

        $violations = $this->violationsAt('paymentMethod', $data);
        self::assertEmpty($violations);
    }

    public function testDebtWithBankTransferPaymentMethodPasses(): void
    {
        $data = $this->validData();
        $data->debtAmountInCzk = 500.0;
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->billingMode = BillingMode::MANUAL_RECURRING;

        $violations = $this->violationsAt('paymentMethod', $data);
        self::assertEmpty($violations);
    }

    public function testNoDebtWithExternalPaymentMethodPasses(): void
    {
        $data = $this->validData();
        $data->debtAmountInCzk = null;
        $data->paymentMethod = PaymentMethod::EXTERNAL;
        $data->monthlyPriceMode = 'free';

        $violations = $this->violationsAt('paymentMethod', $data);
        self::assertEmpty($violations);
    }

    public function testZeroDebtWithExternalPaymentMethodPasses(): void
    {
        $data = $this->validData();
        $data->debtAmountInCzk = 0.0;
        $data->paymentMethod = PaymentMethod::EXTERNAL;
        $data->monthlyPriceMode = 'free';

        $violations = $this->violationsAt('paymentMethod', $data);
        self::assertEmpty($violations);
    }

    public function testNegativeDebtFails(): void
    {
        $data = $this->validData();
        $data->debtAmountInCzk = -100.0;

        $violations = $this->violationsAt('debtAmountInCzk', $data);
        self::assertNotEmpty($violations);
    }

    public function testPaidThroughDateInThePastIsRejected(): void
    {
        $data = $this->validData();
        $data->isExternallyPrepaid = true;
        $data->paidThroughDate = new \DateTimeImmutable('today -1 day');

        $violations = $this->violationsAt('paidThroughDate', $data);
        self::assertNotEmpty($violations);
    }

    public function testPaidThroughDateTodayPasses(): void
    {
        $data = $this->validData();
        $data->isExternallyPrepaid = true;
        $data->paidThroughDate = new \DateTimeImmutable('today');

        $violations = $this->violationsAt('paidThroughDate', $data);
        self::assertEmpty($violations);
    }

    public function testBackdatedNonFreeStartRequiresPrepaidDate(): void
    {
        $data = $this->validData();
        $data->startDate = new \DateTimeImmutable('today -1 day');
        $data->monthlyPriceMode = 'standard';
        $data->isExternallyPrepaid = false;
        $data->paidThroughDate = null;

        $violations = $this->violationsAt('paidThroughDate', $data);
        self::assertNotEmpty($violations);
    }

    public function testBackdatedFreeStartDoesNotRequirePrepaidDate(): void
    {
        $data = $this->validData();
        $data->startDate = new \DateTimeImmutable('today -1 day');
        $data->monthlyPriceMode = 'free';
        $data->isExternallyPrepaid = false;
        $data->paidThroughDate = null;

        $violations = $this->violationsAt('paidThroughDate', $data);
        self::assertEmpty($violations);
    }

    public function testBackdatedNonFreeStartWithFutureDatePasses(): void
    {
        $data = $this->validData();
        $data->startDate = new \DateTimeImmutable('today -1 day');
        $data->monthlyPriceMode = 'standard';
        $data->paidThroughDate = new \DateTimeImmutable('today +30 days');

        $violations = $this->violationsAt('paidThroughDate', $data);
        self::assertEmpty($violations);
    }

    public function testStartsInPastReflectsStartDate(): void
    {
        $data = $this->validData();

        $data->startDate = new \DateTimeImmutable('today -1 day');
        self::assertTrue($data->startsInPast());

        $data->startDate = new \DateTimeImmutable('today');
        self::assertFalse($data->startsInPast());

        $data->startDate = new \DateTimeImmutable('today +1 day');
        self::assertFalse($data->startsInPast());
    }

    /**
     * @return array<int, \Symfony\Component\Validator\ConstraintViolationInterface>
     */
    private function violationsAt(string $path, AdminOnboardingFormData $data): array
    {
        $violations = $this->validator()->validate($data);

        return array_values(array_filter(
            iterator_to_array($violations),
            static fn ($v) => $v->getPropertyPath() === $path,
        ));
    }

    private function validator(): ValidatorInterface
    {
        $factory = new ConstraintValidatorFactory([
            AddressExistsValidator::class => new AddressExistsValidator(new MockAddressValidator()),
        ]);

        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->setConstraintValidatorFactory($factory)
            ->getValidator();
    }

    private function validData(): AdminOnboardingFormData
    {
        $data = new AdminOnboardingFormData();
        $data->email = 'test@example.com';
        $data->firstName = 'Jan';
        $data->lastName = 'Novák';
        $data->billingStreet = 'Hlavní 1';
        $data->billingCity = 'Praha';
        $data->billingPostalCode = '110 00';
        $data->addressOverride = true;
        $data->rentalType = RentalType::UNLIMITED;
        $data->expectedDuration = ExpectedDuration::MEDIUM;
        $data->startDate = new \DateTimeImmutable('today');
        $data->paymentMethod = PaymentMethod::GOPAY;
        $data->billingMode = BillingMode::AUTO_RECURRING;
        $data->paymentFrequency = PaymentFrequency::MONTHLY;
        $data->monthlyPriceMode = 'standard';
        $data->invoiceToCompany = false;
        $data->birthDate = new \DateTimeImmutable('1990-01-01');

        return $data;
    }
}
