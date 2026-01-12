<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Place;
use App\Exception\PlaceNotFoundException;
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

    public function __invoke(UpdatePlaceCommand $command): Place
    {
        $place = $this->placeRepository->findById($command->placeId);
        if (null === $place) {
            throw PlaceNotFoundException::withId($command->placeId);
        }

        $place->updateDetails(
            name: $command->name,
            address: $command->address,
            description: $command->description,
            now: $this->clock->now(),
        );

        $this->placeRepository->save($place);

        return $place;
    }
}
