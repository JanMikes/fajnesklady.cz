<?php

declare(strict_types=1);

namespace App\Value;

/**
 * One rental period a payment paid: [start, end), end capped at the
 * contract's effective end.
 */
final readonly class BillingCycle
{
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
    }
}
