<?php

declare(strict_types=1);

namespace App\Command;

final readonly class RequestPasswordResetCommand
{
    public function __construct(
        public string $email,
    ) {
    }
}
