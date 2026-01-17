<?php

declare(strict_types=1);

namespace App\Query;

final readonly class GetLandlordDashboardStatsResult
{
    public function __construct(
        public int $lastMonthRevenue,
        public int $lastMonthCommission,
        public int $expectedThisMonthRevenue,
        public int $activeRecurringContracts,
        public int $placesCount,
        public int $totalStorages,
        public int $occupiedStorages,
        public int $availableStorages,
        public float $occupancyRate,
    ) {
    }
}
