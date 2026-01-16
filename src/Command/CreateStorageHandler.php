<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Storage;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Repository\UserRepository;
use App\Service\Identity\ProvideIdentity;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CreateStorageHandler
{
    public function __construct(
        private StorageRepository $storageRepository,
        private StorageTypeRepository $storageTypeRepository,
        private PlaceRepository $placeRepository,
        private UserRepository $userRepository,
        private ClockInterface $clock,
        private ProvideIdentity $identityProvider,
    ) {
    }

    public function __invoke(CreateStorageCommand $command): Storage
    {
        $storageType = $this->storageTypeRepository->get($command->storageTypeId);
        $place = $this->placeRepository->get($command->placeId);
        $owner = null !== $command->ownerId ? $this->userRepository->get($command->ownerId) : null;

        $storage = new Storage(
            id: $this->identityProvider->next(),
            number: $command->number,
            coordinates: $command->coordinates,
            storageType: $storageType,
            place: $place,
            createdAt: $this->clock->now(),
            owner: $owner,
        );

        $this->storageRepository->save($storage);

        return $storage;
    }
}
