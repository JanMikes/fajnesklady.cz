<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class FinePaymentReminderRequested
{
    public function __construct(
        public Uuid $fineId,
        public int $stage,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
