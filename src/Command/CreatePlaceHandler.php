<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Place;
use App\Repository\PlaceRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CreatePlaceHandler
{
    public function __construct(
        private PlaceRepository $placeRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CreatePlaceCommand $command): Place
    {
        $now = $this->clock->now();

        $place = new Place(
            id: $command->placeId,
            name: $command->name,
            address: $command->address,
            city: $command->city,
            postalCode: $command->postalCode,
            description: $command->description,
            createdAt: $now,
        );

        if (null !== $command->mapImagePath) {
            $place->updateMapImage($command->mapImagePath, $now);
        }

        $this->placeRepository->save($place);

        return $place;
    }
}
