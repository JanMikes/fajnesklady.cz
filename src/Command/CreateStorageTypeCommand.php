<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class CreateStorageTypeCommand
{
    public function __construct(
        public string $name,
        public int $width,
        public int $height,
        public int $length,
        public int $pricePerWeek,
        public int $pricePerMonth,
        public ?string $description,
        public Uuid $placeId,
    ) {
    }
}
