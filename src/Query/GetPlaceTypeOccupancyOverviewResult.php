<?php

declare(strict_types=1);

namespace App\Query;

final readonly class GetPlaceTypeOccupancyOverviewResult
{
    /**
     * @param GetPlaceTypeOccupancyRow[] $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
