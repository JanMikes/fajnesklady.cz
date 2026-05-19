<?php

declare(strict_types=1);

namespace App\Validator;

use App\Form\Address\HasBillingAddress;
use App\Service\Address\AddressValidator;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class AddressExistsValidator extends ConstraintValidator
{
    public function __construct(
        private readonly AddressValidator $addressValidator,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof AddressExists) {
            throw new UnexpectedTypeException($constraint, AddressExists::class);
        }

        if (!$value instanceof HasBillingAddress) {
            return;
        }

        if ($value->addressOverride || !$value->hasCompleteAddress()) {
            return;
        }

        $result = $this->addressValidator->validate(
            $value->billingStreet,
            $value->billingCity,
            $value->billingPostalCode,
        );

        if ($result->isNotFound()) {
            $this->context->buildViolation($constraint->message)
                ->atPath('billingStreet')
                ->addViolation();
        }
    }
}
