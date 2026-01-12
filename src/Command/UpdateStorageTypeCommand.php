<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class UpdateStorageTypeCommand
{
    public function __construct(
        public Uuid $storageTypeId,
        public string $name,
        public string $width,
        public string $height,
        public string $length,
        public int $pricePerWeek,
        public int $pricePerMonth,
    ) {
    }
}
