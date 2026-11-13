<?php

declare(strict_types=1);

namespace App\Service\Order;

final readonly class OrderPaymentOverview
{
    /**
     * @param list<PaymentOverviewRow> $rows chronological, oldest first
     */
    public function __construct(
        public array $rows,
        public int $totalPaidInHaler,
        public int $overdueTotalInHaler,
        public int $outstandingTotalInHaler,
        /** Confirmed card refunds — already netted out of totalPaidInHaler. */
        public int $refundedTotalInHaler = 0,
    ) {
    }

    public function hasRows(): bool
    {
        return [] !== $this->rows;
    }
}
