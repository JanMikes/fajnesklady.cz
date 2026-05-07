<?php

declare(strict_types=1);

namespace App\Value;

final readonly class OverdueSummary
{
    /**
     * @param OverdueContractView[] $top up to 5 highest-severity, then highest-days
     */
    public function __construct(
        public int $count,
        public int $totalAmount,
        public array $top,
    ) {
    }
}
