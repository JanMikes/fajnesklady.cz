<?php

declare(strict_types=1);

namespace App\Query;

/**
 * @implements QueryMessage<GetAdminRevenueChartResult>
 */
final readonly class GetAdminRevenueChart implements QueryMessage
{
    public function __construct(
        public int $months = 12,
    ) {
    }
}
