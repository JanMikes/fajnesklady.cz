<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class UpdateStorageTypeCommand
{
    public function __construct(
        public Uuid $storageTypeId,
        public string $name,
        public int $innerWidth,
        public int $innerHeight,
        public int $innerLength,
        public ?int $outerWidth,
        public ?int $outerHeight,
        public ?int $outerLength,
        public int $defaultPricePerWeek,
        public int $defaultPricePerMonth,
        public ?string $description,
        public bool $uniformStorages = true,
    ) {
    }
}
