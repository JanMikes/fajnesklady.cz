<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class OrderCreated
{
    public function __construct(
        public Uuid $orderId,
        public Uuid $userId,
        public Uuid $storageId,
        public int $totalPrice,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
