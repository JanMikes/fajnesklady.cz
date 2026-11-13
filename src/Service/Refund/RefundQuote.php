<?php

declare(strict_types=1);

namespace App\Service\Refund;

final readonly class RefundQuote
{
    public function __construct(
        /** Haléře to return. */
        public int $amount,
        /** Days of the paid period returned to the customer (null = whole payment, period unknown). */
        public ?int $refundedDays,
        /** Days the refunded payment covered (null when unknown). */
        public ?int $periodDays,
    ) {
    }
}
