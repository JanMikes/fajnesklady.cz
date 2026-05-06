<?php

declare(strict_types=1);

namespace App\Value;

final readonly class PaymentScheduleEntry
{
    public function __construct(
        public \DateTimeImmutable $chargeDate,
        public int $amount,
    ) {
    }

    public function getAmountInCzk(): float
    {
        return $this->amount / 100;
    }
}
