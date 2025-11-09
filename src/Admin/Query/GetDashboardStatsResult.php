<?php

declare(strict_types=1);

namespace App\Admin\Query;

final readonly class GetDashboardStatsResult
{
    public function __construct(
        public int $totalUsers,
        public int $verifiedUsers,
        public int $adminUsers,
        public int $unverifiedUsers,
    ) {
    }
}
