<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class RecurringPaymentCancelled
{
    public function __construct(
        public Uuid $contractId,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
