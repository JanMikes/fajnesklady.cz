<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Enum\BillingMode;
use App\Enum\PaymentMethod;
use App\Enum\RentalType;
use App\Form\AdminCreateOnboardingFormData;
use App\Tests\Mock\MockAddressValidator;
use App\Validator\AddressExistsValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;

final class AdminCreateOnboardingFormDataTest extends TestCase
{
    public function testExternalWithStandardPriceRequiresPrepaidDate(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::EXTERNAL;
        $data->monthlyPriceMode = 'standard';
        $data->isExternallyPrepaid = false;
        $data->paidThroughDate = null;

        $messages = $this->violationsAt('paidThroughDate', $data);

        self::assertNotEmpty($messages, 'Expected validation error at paidThroughDate');
        self::assertStringContainsString('Externí platba', $messages[0][1]);
    }

    public function testExternalWithFreePassesWithoutDate(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::EXTERNAL;
        $data->monthlyPriceMode = 'free';
        $data->isExternallyPrepaid = false;
        $data->paidThroughDate = null;

        self::assertEmpty(
            $this->violationsAt('paidThroughDate', $data),
            'Free contracts should not require paidThroughDate',
        );
    }

    public function testExternalWithPrepaidDatePasses(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::EXTERNAL;
        $data->monthlyPriceMode = 'standard';
        $data->isExternallyPrepaid = true;
        $data->paidThroughDate = new \DateTimeImmutable('2026-12-31');

        self::assertEmpty($this->violationsAt('paidThroughDate', $data));
    }

    public function testExternalWithPrepaidCheckedButNoDateProducesSingleViolation(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::EXTERNAL;
        $data->monthlyPriceMode = 'standard';
        $data->isExternallyPrepaid = true;
        $data->paidThroughDate = null;

        $messages = $this->violationsAt('paidThroughDate', $data);

        // Both validateExternalIsPrepaid and validatePaidThroughDate previously
        // fired here — keep exactly one error message on this field.
        self::assertCount(1, $messages, sprintf(
            'Expected exactly one violation on paidThroughDate, got: %s',
            implode(' | ', array_column($messages, 1)),
        ));
    }

    public function testGoPayDoesNotRequirePrepaidDate(): void
    {
        $data = $this->validData();
        $data->paymentMethod = PaymentMethod::GOPAY;
        $data->monthlyPriceMode = 'standard';
        $data->isExternallyPrepaid = false;
        $data->paidThroughDate = null;

        self::assertEmpty($this->violationsAt('paidThroughDate', $data));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function violationsAt(string $path, AdminCreateOnboardingFormData $data): array
    {
        $violations = $this->validator()->validate($data);

        return array_values(array_filter(
            array_map(
                static fn ($v): array => [$v->getPropertyPath(), (string) $v->getMessage()],
                iterator_to_array($violations),
            ),
            static fn (array $pair): bool => $pair[0] === $path,
        ));
    }

    private function validData(): AdminCreateOnboardingFormData
    {
        $data = new AdminCreateOnboardingFormData();
        $data->email = 'jan@example.com';
        $data->firstName = 'Jan';
        $data->lastName = 'Novak';
        $data->phone = '+420123456789';
        $data->birthDate = new \DateTimeImmutable('1990-01-01');
        $data->billingStreet = 'Hlavní 1';
        $data->billingCity = 'Praha';
        $data->billingPostalCode = '110 00';
        $data->storageId = 'abc';
        $data->rentalType = RentalType::UNLIMITED;
        $data->startDate = new \DateTimeImmutable('+1 day');
        $data->billingMode = BillingMode::AUTO_RECURRING;

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
