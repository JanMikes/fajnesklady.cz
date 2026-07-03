<?php

declare(strict_types=1);

namespace App\Query;

use App\Entity\StorageType;

final readonly class GetHomepagePlaceTypeRow
{
    public function __construct(
        public StorageType $storageType,
        public bool $isAvailable,
    ) {
    }
}
