<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

/**
 * Set password for a passwordless user account.
 */
final readonly class SetUserPasswordCommand
{
    public function __construct(
        public Uuid $userId,
        public string $plainPassword,
    ) {
    }
}
