<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;
use Symfony\Component\Uid\Uuid;

#[WithHttpStatus(404)]
final class UserNotFound extends \RuntimeException
{
    public static function withId(Uuid $id): self
    {
        return new self(sprintf('Uživatel s ID "%s" nebyl nalezen.', $id->toRfc4122()));
    }

    public static function withEmail(string $email): self
    {
        return new self(sprintf('Uživatel s e-mailem "%s" nebyl nalezen.', $email));
    }
}
