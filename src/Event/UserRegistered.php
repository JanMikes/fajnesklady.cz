<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class UserRegistered
{
    public function __construct(
        public Uuid $userId,
        public string $email,
        public string $firstName,
        public string $lastName,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
