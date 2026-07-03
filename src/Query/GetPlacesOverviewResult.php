<?php

declare(strict_types=1);

namespace App\Query;

final readonly class GetPlacesOverviewResult
{
    /**
     * @param list<GetPlacesOverviewRow> $places
     */
    public function __construct(
        public array $places,
    ) {
    }
}
