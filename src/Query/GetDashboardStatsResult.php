<?php

declare(strict_types=1);

namespace App\Query;

use App\Value\OverdueContractView;

final readonly class GetDashboardStatsResult
{
    /**
     * @param OverdueContractView[] $overdueTop
     */
    public function __construct(
        public int $totalUsers,
        public int $verifiedUsers,
        public int $adminUsers,
        public int $unverifiedUsers,
        public int $landlordCount,
        public int $lastMonthRevenue,
        public int $lastMonthCommission,
        public int $expectedThisMonthRevenue,
        public int $totalPlaces,
        public int $totalStorages,
        public int $occupiedStorages,
        public float $platformOccupancyRate,
        public int $activeRecurringContracts,
        public int $overdueCount,
        public int $overdueAmount,
        public array $overdueTop,
    ) {
    }
}
