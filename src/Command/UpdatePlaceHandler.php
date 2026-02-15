<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PlaceRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class UpdatePlaceHandler
{
    public function __construct(
        private PlaceRepository $placeRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(UpdatePlaceCommand $command): void
    {
        $place = $this->placeRepository->get($command->placeId);
        $now = $this->clock->now();

        $place->updateDetails(
            name: $command->name,
            address: $command->address,
            city: $command->city,
            postalCode: $command->postalCode,
            description: $command->description,
            type: $command->type,
            now: $now,
        );

        if (null !== $command->mapImagePath) {
            $place->updateMapImage($command->mapImagePath, $now);
        }

        $place->updateLocation($command->latitude, $command->longitude, $now);

        $this->placeRepository->save($place);
    }
}
