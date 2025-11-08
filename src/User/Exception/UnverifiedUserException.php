<?php

declare(strict_types=1);

namespace App\User\Exception;

class UnverifiedUserException extends \RuntimeException
{
    public static function forEmail(string $email): self
    {
        return new self(sprintf('User "%s" has not verified their email address.', $email));
    }
}
