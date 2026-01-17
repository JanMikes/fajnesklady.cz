<?php

declare(strict_types=1);

namespace App\Query;

final readonly class GetAdminRevenueChartResult
{
    /**
     * @param array<string> $labels
     * @param array<int>    $revenues
     */
    public function __construct(
        public array $labels,
        public array $revenues,
    ) {
    }
}
