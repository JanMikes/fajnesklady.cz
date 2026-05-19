<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validator;

use App\Form\OrderFormData;
use App\Service\Address\AddressValidator;
use App\Validator\AddressExists;
use App\Validator\AddressExistsValidator;
use App\Value\Address\AddressValidationResult;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<AddressExistsValidator>
 */
final class AddressExistsValidatorTest extends ConstraintValidatorTestCase
{
    private AddressValidator&MockObject $addressValidator;

    protected function setUp(): void
    {
        $this->addressValidator = $this->createMock(AddressValidator::class);
        parent::setUp();
    }

    protected function createValidator(): AddressExistsValidator
    {
        return new AddressExistsValidator($this->addressValidator);
    }

    public function testNoViolationWhenOverrideIsTicked(): void
    {
        $formData = $this->formDataWithAddress('Asdfghj 999', 'Tatratata', '99999');
        $formData->addressOverride = true;

        $this->addressValidator->expects(self::never())->method('validate');

        $this->validator->validate($formData, new AddressExists());

        $this->assertNoViolation();
    }

    public function testNoViolationWhenAddressIncomplete(): void
    {
        $formData = new OrderFormData();
        $formData->billingStreet = '';
        $formData->billingCity = '';
        $formData->billingPostalCode = '';

        $this->addressValidator->expects(self::never())->method('validate');

        $this->validator->validate($formData, new AddressExists());

        $this->assertNoViolation();
    }

    public function testNoViolationForVerifiedAddress(): void
    {
        $formData = $this->formDataWithAddress('Vinohradská 52', 'Praha', '120 00');

        $this->addressValidator
            ->expects(self::once())
            ->method('validate')
            ->willReturn(AddressValidationResult::verified());

        $this->validator->validate($formData, new AddressExists());

        $this->assertNoViolation();
    }

    public function testNoViolationWhenPhotonIsSkipped(): void
    {
        $formData = $this->formDataWithAddress('Vinohradská 52', 'Praha', '120 00');

        $this->addressValidator
            ->expects(self::once())
            ->method('validate')
            ->willReturn(AddressValidationResult::skipped());

        $this->validator->validate($formData, new AddressExists());

        $this->assertNoViolation();
    }

    public function testViolationAtBillingStreetWhenAddressNotFound(): void
    {
        $formData = $this->formDataWithAddress('Asdfghj 999', 'Tatratata', '99999');

        $this->addressValidator
            ->expects(self::once())
            ->method('validate')
            ->willReturn(AddressValidationResult::notFound());

        $constraint = new AddressExists();
        $this->validator->validate($formData, $constraint);

        $this->buildViolation($constraint->message)
            ->atPath('property.path.billingStreet')
            ->assertRaised();
    }

    public function testIgnoresValueThatDoesNotImplementInterface(): void
    {
        $this->addressValidator->expects(self::never())->method('validate');

        $this->validator->validate(new \stdClass(), new AddressExists());

        $this->assertNoViolation();
    }

    private function formDataWithAddress(string $street, string $city, string $postalCode): OrderFormData
    {
        $formData = new OrderFormData();
        $formData->billingStreet = $street;
        $formData->billingCity = $city;
        $formData->billingPostalCode = $postalCode;

        return $formData;
    }
}
