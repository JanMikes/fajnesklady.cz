<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(403)]
final class UnverifiedUser extends \RuntimeException
{
    public static function forEmail(string $email): self
    {
        return new self(sprintf('User "%s" has not verified their email address.', $email));
    }
}
