<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Place;
use App\Repository\PlaceRepository;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CreatePlaceHandler
{
    public function __construct(
        private PlaceRepository $placeRepository,
        private UserRepository $userRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CreatePlaceCommand $command): Place
    {
        $owner = $this->userRepository->get($command->ownerId);
        $now = $this->clock->now();

        $place = new Place(
            id: $command->placeId,
            name: $command->name,
            address: $command->address,
            city: $command->city,
            postalCode: $command->postalCode,
            description: $command->description,
            owner: $owner,
            createdAt: $now,
        );

        if (null !== $command->mapImagePath) {
            $place->updateMapImage($command->mapImagePath, $now);
        }

        if (null !== $command->contractTemplatePath) {
            $place->updateContractTemplate($command->contractTemplatePath, $now);
        }

        $this->placeRepository->save($place);

        return $place;
    }
}
