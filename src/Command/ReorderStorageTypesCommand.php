<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class ReorderStorageTypesCommand
{
    /**
     * @param list<Uuid> $orderedStorageTypeIds
     */
    public function __construct(
        public Uuid $placeId,
        public array $orderedStorageTypeIds,
    ) {
    }
}
