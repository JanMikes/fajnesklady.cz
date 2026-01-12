<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Place;
use App\Repository\PlaceRepository;
use App\Repository\UserRepository;
use App\Service\Identity\ProvideIdentity;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CreatePlaceHandler
{
    public function __construct(
        private PlaceRepository $placeRepository,
        private UserRepository $userRepository,
        private ClockInterface $clock,
        private ProvideIdentity $identityProvider,
    ) {
    }

    public function __invoke(CreatePlaceCommand $command): Place
    {
        $owner = $this->userRepository->get($command->ownerId);

        $place = new Place(
            id: $this->identityProvider->next(),
            name: $command->name,
            address: $command->address,
            city: $command->city,
            postalCode: $command->postalCode,
            description: $command->description,
            owner: $owner,
            createdAt: $this->clock->now(),
        );

        $this->placeRepository->save($place);

        return $place;
    }
}
