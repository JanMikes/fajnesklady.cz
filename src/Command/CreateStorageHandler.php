<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Storage;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Service\Identity\ProvideIdentity;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CreateStorageHandler
{
    public function __construct(
        private StorageRepository $storageRepository,
        private StorageTypeRepository $storageTypeRepository,
        private ClockInterface $clock,
        private ProvideIdentity $identityProvider,
    ) {
    }

    public function __invoke(CreateStorageCommand $command): Storage
    {
        $storageType = $this->storageTypeRepository->get($command->storageTypeId);

        $storage = new Storage(
            id: $this->identityProvider->next(),
            number: $command->number,
            coordinates: $command->coordinates,
            storageType: $storageType,
            createdAt: $this->clock->now(),
        );

        $this->storageRepository->save($storage);

        return $storage;
    }
}
