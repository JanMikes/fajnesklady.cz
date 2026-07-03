<?php

declare(strict_types=1);

namespace App\Query;

final readonly class GetHomepagePlacesResult
{
    /**
     * @param list<GetHomepagePlaceRow> $places
     */
    public function __construct(
        public array $places,
    ) {
    }
}
