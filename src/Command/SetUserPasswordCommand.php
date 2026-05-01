<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

/**
 * Admin-driven password change for a user. Always emits PasswordChangedByAdmin domain event.
 */
final readonly class SetUserPasswordCommand
{
    public function __construct(
        public Uuid $userId,
        public string $plainPassword,
    ) {
    }
}
