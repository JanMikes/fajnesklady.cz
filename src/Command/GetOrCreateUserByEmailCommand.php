<?php

declare(strict_types=1);

namespace App\Command;

/**
 * Get existing user or create passwordless user by email.
 * Used during order flow when user provides email.
 */
final readonly class GetOrCreateUserByEmailCommand
{
    public function __construct(
        public string $email,
        public string $firstName,
        public string $lastName,
        public ?string $phone = null,
    ) {
    }
}
