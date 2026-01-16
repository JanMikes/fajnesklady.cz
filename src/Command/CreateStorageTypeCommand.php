<?php

declare(strict_types=1);

namespace App\Command;

final readonly class CreateStorageTypeCommand
{
    public function __construct(
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
    ) {
    }
}
