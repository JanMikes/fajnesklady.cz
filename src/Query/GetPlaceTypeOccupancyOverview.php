<?php

declare(strict_types=1);

namespace App\Query;

use Symfony\Component\Uid\Uuid;

/**
 * @implements QueryMessage<GetPlaceTypeOccupancyOverviewResult>
 */
final readonly class GetPlaceTypeOccupancyOverview implements QueryMessage
{
    public function __construct(
        public Uuid $placeId,
        public ?Uuid $landlordId = null,
    ) {
    }
}
