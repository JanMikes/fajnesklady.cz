<?php

declare(strict_types=1);

namespace App\Query;

use Symfony\Component\Uid\Uuid;

/**
 * @implements QueryMessage<GetStorageTypeOccupancyResult>
 */
final readonly class GetStorageTypeOccupancy implements QueryMessage
{
    public function __construct(
        public Uuid $placeId,
        public Uuid $storageTypeId,
        public ?Uuid $landlordId = null,
    ) {
    }
}
