<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class UpdateStorageCommand
{
    /**
     * @param array{x: int, y: int, width: int, height: int, rotation: int} $coordinates
     */
    public function __construct(
        public Uuid $storageId,
        public string $number,
        public array $coordinates,
    ) {
    }
}
