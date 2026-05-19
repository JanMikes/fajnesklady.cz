<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AddressExists extends Constraint
{
    public string $message = 'Tuto adresu se nepodařilo ověřit. Zkontrolujte ji, nebo potvrďte zaškrtnutím.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
