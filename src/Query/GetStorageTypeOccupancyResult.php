<?php

declare(strict_types=1);

namespace App\Query;

use App\Entity\StorageType;
use App\Value\StorageRentalView;

final readonly class GetStorageTypeOccupancyResult
{
    /**
     * @param StorageRentalView[] $rows occupied first (by rentedUntil ASC, nulls last), then free, then blocked
     */
    public function __construct(
        public StorageType $storageType,
        public int $totalCount,
        public int $occupiedCount,
        public int $availableCount,
        public int $blockedCount,
        public ?\DateTimeImmutable $nextFreeingDate,
        public ?\DateTimeImmutable $nextBookedDate,
        public array $rows,
    ) {
    }
}
