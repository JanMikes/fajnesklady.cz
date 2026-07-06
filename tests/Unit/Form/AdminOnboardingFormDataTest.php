<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
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

    public function testEndDateRequired(): void
    {
        $data = $this->validData();
        $data->endDate = null;

        $violations = $this->violationsAt('endDate', $data);
        self::assertNotEmpty($violations);
        self::assertSame('Zadejte datum konce.', (string) $violations[0]->getMessage());
    }

    public function testMinimumRentalIs7Days(): void
    {
        $data = $this->validData();
        $data->startDate = new \DateTimeImmutable('today');
        $data->endDate = new \DateTimeImmutable('today +3 days');

        $violations = $this->violationsAt('endDate', $data);
        self::assertNotEmpty($violations);
        self::assertSame('Minimální doba pronájmu je 7 dní.', (string) $violations[0]->getMessage());
    }

    public function testBankTransferDerivesManualRecurring(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->billingMode = null;

        $this->validator()->validate($data);

        self::assertSame(BillingMode::MANUAL_RECURRING, $data->billingMode);
    }

    public function testBankTransferShortRentalDerivesOneTime(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->startDate = new \DateTimeImmutable('today');
        $data->endDate = new \DateTimeImmutable('today +14 days');
        $data->billingMode = null;

        $violations = $this->validator()->validate($data);

        self::assertCount(0, $violations);
        self::assertSame(BillingMode::ONE_TIME, $data->billingMode);
    }

    public function testGoPayDerivesAutoRecurring(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::GOPAY;
        $data->billingMode = null;

        $this->validator()->validate($data);

        self::assertSame(BillingMode::AUTO_RECURRING, $data->billingMode);
    }

    public function testExternalDerivesManualRecurring(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::EXTERNAL;
        $data->monthlyPriceMode = 'free';
        $data->billingMode = null;

        $this->validator()->validate($data);

        self::assertSame(BillingMode::MANUAL_RECURRING, $data->billingMode);
    }

    public function testYearlyDerivesManualRecurring(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->paymentFrequency = PaymentFrequency::YEARLY;
        $data->startDate = new \DateTimeImmutable('today');
        $data->endDate = new \DateTimeImmutable('today +400 days');
        $data->billingMode = null;

        $this->validator()->validate($data);

        self::assertSame(BillingMode::MANUAL_RECURRING, $data->billingMode);
    }

    public function testGoPayWithYearlyFrequencyIsRejected(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::GOPAY;
        $data->paymentFrequency = PaymentFrequency::YEARLY;
        $data->startDate = new \DateTimeImmutable('today');
        $data->endDate = new \DateTimeImmutable('today +400 days');

        $violations = $this->violationsAt('paymentFrequency', $data);
        self::assertNotEmpty($violations);
        self::assertSame('Roční platbu lze platit pouze bankovním převodem.', (string) $violations[0]->getMessage());
    }

    public function testGoPayShortRentalIsRejected(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::GOPAY;
        $data->startDate = new \DateTimeImmutable('today');
        $data->endDate = new \DateTimeImmutable('today +14 days');

        $violations = $this->violationsAt('paymentMethod', $data);
        self::assertNotEmpty($violations);
        self::assertSame('Platba kartou je dostupná pro pronájmy od 31 dnů. Kratší pronájem se platí bankovním převodem.', (string) $violations[0]->getMessage());
    }

    public function testYearlyShortRentalIsRejected(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->paymentFrequency = PaymentFrequency::YEARLY;
        $data->startDate = new \DateTimeImmutable('today');
        $data->endDate = new \DateTimeImmutable('today +200 days');

        $violations = $this->violationsAt('paymentFrequency', $data);
        self::assertNotEmpty($violations);
        self::assertSame('Roční platba je dostupná pouze pro pronájem na 12 měsíců a déle.', (string) $violations[0]->getMessage());
    }

    public function testYearlyWithCustomPriceIsAllowed(): void
    {
        // The individual price on a yearly order is a per-YEAR figure and may
        // exceed the 15 000 Kč recurring-card cap — yearly billing is
        // bank-transfer only, so the cap does not apply.
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->startDate = new \DateTimeImmutable('today');
        $data->endDate = new \DateTimeImmutable('today +400 days');
        $data->paymentFrequency = PaymentFrequency::YEARLY;
        $data->monthlyPriceMode = 'custom';
        $data->customMonthlyPriceInCzk = 24000.0;

        $violations = $this->violationsAt('customMonthlyPriceInCzk', $data);
        self::assertEmpty($violations);
    }

    public function testMonthlyCustomPriceAboveLegalCapIsRejected(): void
    {
        // Monthly custom prices stay bound by the 15 000 Kč legal maximum for
        // a single recurring charge (Podmínky opakovaných plateb čl. III).
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->paymentFrequency = PaymentFrequency::MONTHLY;
        $data->monthlyPriceMode = 'custom';
        $data->customMonthlyPriceInCzk = 15001.0;

        $violations = $this->violationsAt('customMonthlyPriceInCzk', $data);
        self::assertNotEmpty($violations);
        self::assertSame('Maximální měsíční cena je 15 000 Kč (zákonný strop pro opakované platby).', (string) $violations[0]->getMessage());
    }

    public function testYearlyWithStandardPricePasses(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->startDate = new \DateTimeImmutable('today');
        $data->endDate = new \DateTimeImmutable('today +400 days');
        $data->paymentFrequency = PaymentFrequency::YEARLY;
        $data->monthlyPriceMode = 'standard';

        $violations = $this->violationsAt('customMonthlyPriceInCzk', $data);
        self::assertEmpty($violations);
    }

    public function testGoPayWithUpfrontFrequencyIsRejected(): void
    {
        // Spec 078: the whole-rental upfront payment is bank-transfer only.
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::GOPAY;
        $data->paymentFrequency = PaymentFrequency::ONE_TIME;
        $data->startDate = new \DateTimeImmutable('today');
        $data->endDate = new \DateTimeImmutable('today +90 days');

        $violations = $this->violationsAt('paymentFrequency', $data);
        self::assertNotEmpty($violations);
        self::assertSame('Jednorázovou platbu celé částky lze provést pouze bankovním převodem.', (string) $violations[0]->getMessage());
    }

    public function testExternalWithUpfrontFrequencyIsRejected(): void
    {
        // Outside payments are recorded via "Externí předplatné / Předplaceno do",
        // never as an upfront order (spec 078).
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::EXTERNAL;
        $data->paymentFrequency = PaymentFrequency::ONE_TIME;
        $data->isExternallyPrepaid = true;
        $data->paidThroughDate = new \DateTimeImmutable('today +30 days');
        $data->startDate = new \DateTimeImmutable('today');
        $data->endDate = new \DateTimeImmutable('today +90 days');

        $violations = $this->violationsAt('paymentFrequency', $data);
        self::assertNotEmpty($violations);
        self::assertSame('Pro pronájem uhrazený mimo systém použijte „Externí předplatné" s datem „Předplaceno do". Jednorázová platba předem je určena pro bankovní převod.', (string) $violations[0]->getMessage());
    }

    public function testUpfrontWithCustomTotalPriceIsAllowedForSinglePayment(): void
    {
        // A ≤ 12-month upfront rental is one whole-rental payment, so the
        // individual price is the TOTAL the customer pays — above the
        // recurring-card cap is fine (bank transfer only).
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->paymentFrequency = PaymentFrequency::ONE_TIME;
        $data->startDate = new \DateTimeImmutable('today');
        $data->endDate = new \DateTimeImmutable('today +90 days');
        $data->monthlyPriceMode = 'custom';
        $data->customMonthlyPriceInCzk = 18000.0;

        $violations = $this->violationsAt('customMonthlyPriceInCzk', $data);
        self::assertEmpty($violations);
    }

    public function testUpfrontCustomPriceRejectedWhenSplitIntoTranches(): void
    {
        // Longer than 12 monthly periods → yearly tranches (spec 078) whose
        // math derives the monthly rate from firstPaymentPrice / 12; an
        // arbitrary total would corrupt every follow-up tranche.
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->paymentFrequency = PaymentFrequency::ONE_TIME;
        $data->startDate = new \DateTimeImmutable('today');
        $data->endDate = new \DateTimeImmutable('today +400 days');
        $data->monthlyPriceMode = 'custom';
        $data->customMonthlyPriceInCzk = 18000.0;

        $violations = $this->violationsAt('customMonthlyPriceInCzk', $data);
        self::assertNotEmpty($violations);
    }

    public function testBankTransferUpfrontDerivesOneTime(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->paymentFrequency = PaymentFrequency::ONE_TIME;
        $data->startDate = new \DateTimeImmutable('today');
        $data->endDate = new \DateTimeImmutable('today +90 days');
        $data->billingMode = null;

        $violations = $this->validator()->validate($data);

        self::assertCount(0, $violations);
        self::assertSame(BillingMode::ONE_TIME, $data->billingMode);
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

    public function testPrepaidWithOneTimeFrequencyIsRejected(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $data->paymentFrequency = PaymentFrequency::ONE_TIME;
        $data->isExternallyPrepaid = true;
        $data->paidThroughDate = (new \DateTimeImmutable('today'))->modify('+1 month');

        $violations = $this->violationsAt('paymentFrequency', $data);
        self::assertNotEmpty($violations);
        self::assertStringContainsString('externím předplatným', (string) $violations[0]->getMessage());
    }

    public function testPrepaidWithGoPayDerivesManualRecurring(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::GOPAY;
        $data->isExternallyPrepaid = true;
        $data->paidThroughDate = (new \DateTimeImmutable('today'))->modify('+1 month');
        $data->billingMode = null;

        $this->validator()->validate($data);

        self::assertSame(BillingMode::MANUAL_RECURRING, $data->billingMode);
    }

    public function testCustomPriceModeRequiresPositiveAmount(): void
    {
        $data = $this->validData();
        $data->monthlyPriceMode = 'custom';
        $data->customMonthlyPriceInCzk = null;

        $violations = $this->violationsAt('customMonthlyPriceInCzk', $data);
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

    public function testPaidThroughDateAfterContractEndIsRejected(): void
    {
        $data = $this->validData();
        $data->isExternallyPrepaid = true;
        $data->startDate = new \DateTimeImmutable('today');
        $data->endDate = new \DateTimeImmutable('today +30 days');
        $data->paidThroughDate = new \DateTimeImmutable('today +60 days');

        $violations = $this->violationsAt('paidThroughDate', $data);
        self::assertNotEmpty($violations);
        self::assertSame('Datum předplatby nemůže být po datu konce smlouvy.', (string) $violations[0]->getMessage());
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
        $data->startDate = new \DateTimeImmutable('today');
        $data->endDate = new \DateTimeImmutable('today +12 months');
        $data->paymentMethod = PaymentMethod::GOPAY;
        $data->paymentFrequency = PaymentFrequency::MONTHLY;
        $data->monthlyPriceMode = 'standard';
        $data->invoiceToCompany = false;
        $data->birthDate = new \DateTimeImmutable('1990-01-01');

        return $data;
    }
}
