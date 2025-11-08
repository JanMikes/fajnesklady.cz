<?php

declare(strict_types=1);

namespace App\User\Event;

use Symfony\Component\Uid\Uuid;

final readonly class PasswordResetRequested
{
    public function __construct(
        public Uuid $userId,
        public string $email,
        public string $resetToken,
        public \DateTimeImmutable $occurredOn = new \DateTimeImmutable(),
    ) {
    }
}
