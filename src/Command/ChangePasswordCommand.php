<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class ChangePasswordCommand
{
    public function __construct(
        public Uuid $userId,
        public string $currentPassword,
        public string $newPassword,
    ) {
    }
}
