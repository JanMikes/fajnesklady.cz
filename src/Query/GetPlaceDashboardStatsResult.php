<?php

declare(strict_types=1);

namespace App\Query;

use App\Value\OverdueContractView;

final readonly class GetPlaceDashboardStatsResult
{
    /**
     * @param OverdueContractView[] $overdueTop
     */
    public function __construct(
        public int $totalStorages,
        public int $occupiedStorages,
        public int $availableStorages,
        public int $blockedStorages,
        public float $occupancyRate,
        public int $lastMonthRevenue,
        public int $expectedThisMonthRevenue,
        public int $activeContractsCount,
        public int $activeRecurringContracts,
        public int $overdueCount,
        public int $overdueAmount,
        public array $overdueTop,
        public bool $missingOperatingRules,
        public bool $missingInstructions,
        public bool $missingMap,
        public bool $missingStorageTypes,
        public bool $missingLockCodes,
        public bool $hasCoOwners,
    ) {
    }
}
