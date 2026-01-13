<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class LandlordRegistered
{
    public function __construct(
        public Uuid $userId,
        public string $email,
        public string $companyName,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
