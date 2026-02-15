<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\PlaceType;
use Symfony\Component\Uid\Uuid;

final readonly class UpdatePlaceCommand
{
    public function __construct(
        public Uuid $placeId,
        public string $name,
        public ?string $address,
        public string $city,
        public string $postalCode,
        public ?string $description,
        public PlaceType $type = PlaceType::FAJNE_SKLADY,
        public ?string $mapImagePath = null,
        public ?string $latitude = null,
        public ?string $longitude = null,
    ) {
    }
}
