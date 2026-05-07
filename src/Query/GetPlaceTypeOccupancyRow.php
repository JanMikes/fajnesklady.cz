<?php

declare(strict_types=1);

namespace App\Query;

use App\Entity\StorageType;

final readonly class GetPlaceTypeOccupancyRow
{
    public function __construct(
        public StorageType $storageType,
        public int $totalCount,
        public int $occupiedCount,
        public int $availableCount,
        public int $blockedCount,
        public ?\DateTimeImmutable $nextFreeingDate,
        public ?\DateTimeImmutable $nextBookedDate,
    ) {
    }
}
