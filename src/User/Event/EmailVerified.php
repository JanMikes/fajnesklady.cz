<?php

declare(strict_types=1);

namespace App\User\Event;

use Symfony\Component\Uid\Uuid;

final readonly class EmailVerified
{
    public function __construct(
        public Uuid $userId,
        public \DateTimeImmutable $occurredOn = new \DateTimeImmutable(),
    ) {
    }
}
