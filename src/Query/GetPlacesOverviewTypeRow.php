<?php

declare(strict_types=1);

namespace App\Query;

use App\Entity\StorageType;

final readonly class GetPlacesOverviewTypeRow
{
    public function __construct(
        public StorageType $storageType,
        public bool $isAvailable,
    ) {
    }
}
