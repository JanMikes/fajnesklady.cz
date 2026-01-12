<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class OrderExpired
{
    public function __construct(
        public Uuid $orderId,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
