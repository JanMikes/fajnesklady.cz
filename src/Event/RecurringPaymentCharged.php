<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class RecurringPaymentCharged
{
    public function __construct(
        public Uuid $contractId,
        public string $paymentId,
        public int $amount,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
