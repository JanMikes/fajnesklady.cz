<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class RecurringPaymentFailed
{
    public function __construct(
        public Uuid $contractId,
        public int $attempt,
        public string $reason,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
