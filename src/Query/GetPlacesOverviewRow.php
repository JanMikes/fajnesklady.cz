<?php

declare(strict_types=1);

namespace App\Query;

use App\Entity\Place;

final readonly class GetPlacesOverviewRow
{
    /**
     * @param list<GetPlacesOverviewTypeRow> $storageTypes publicly orderable types at this place (position, name order)
     * @param float|null                     $lowestPrice  lowest long-term monthly price in CZK across the types, null when the place has none
     * @param float|null                     $lowestAreaM2 smallest floor area in m² across the types (rounded to 1 decimal), null when the place has none
     */
    public function __construct(
        public Place $place,
        public array $storageTypes,
        public ?float $lowestPrice,
        public ?float $lowestAreaM2,
        public bool $isAvailable,
    ) {
    }
}
