<?php

declare(strict_types=1);

namespace App\User\Exception;

class AccountLockedException extends \RuntimeException
{
    public static function until(\DateTimeImmutable $lockedUntil): self
    {
        return new self(sprintf(
            'Account is locked until %s due to multiple failed login attempts.',
            $lockedUntil->format('Y-m-d H:i:s')
        ));
    }
}
