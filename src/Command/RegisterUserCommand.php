<?php

declare(strict_types=1);

namespace App\Command;

final readonly class RegisterUserCommand
{
    public function __construct(
        public string $email,
        public string $password,
        public string $firstName,
        public string $lastName,
    ) {
    }
}
