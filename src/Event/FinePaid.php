<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class FinePaid
{
    public function __construct(
        public Uuid $fineId,
        public Uuid $contractId,
        public Uuid $userId,
        public int $amountInHaler,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
