<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(409)]
final class UserAlreadyExists extends \DomainException
{
    public static function withEmail(string $email): self
    {
        return new self(sprintf('Uživatel s e-mailem "%s" již existuje.', $email));
    }
}
