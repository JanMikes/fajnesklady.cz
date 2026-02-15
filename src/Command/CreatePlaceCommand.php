<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class CreatePlaceCommand
{
    public function __construct(
        public Uuid $placeId,
        public string $name,
        public ?string $address,
        public string $city,
        public string $postalCode,
        public ?string $description,
        public ?string $mapImagePath = null,
        public ?string $latitude = null,
        public ?string $longitude = null,
    ) {
    }
}
