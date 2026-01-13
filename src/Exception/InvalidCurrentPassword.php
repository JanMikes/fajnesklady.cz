<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(400)]
final class InvalidCurrentPassword extends \DomainException
{
    public static function create(): self
    {
        return new self('Aktuální heslo není správné.');
    }
}
