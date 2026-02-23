<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class CreateStorageCommand
{
    /**
     * @param array{x: int|float, y: int|float, width: int|float, height: int|float, rotation: int|float, normalized?: bool} $coordinates
     */
    public function __construct(
        public string $number,
        public array $coordinates,
        public Uuid $storageTypeId,
        public Uuid $placeId,
        public ?Uuid $ownerId = null,
    ) {
    }
}
