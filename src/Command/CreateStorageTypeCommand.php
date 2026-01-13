<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

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
        public int $pricePerWeek,
        public int $pricePerMonth,
        public ?string $description,
        public Uuid $placeId,
    ) {
    }
}
