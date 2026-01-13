<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Storage;
use App\Repository\StorageRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class UpdateStorageHandler
{
    public function __construct(
        private StorageRepository $storageRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(UpdateStorageCommand $command): Storage
    {
        $storage = $this->storageRepository->get($command->storageId);

        $storage->updateDetails(
            number: $command->number,
            coordinates: $command->coordinates,
            now: $this->clock->now(),
        );

        $this->storageRepository->save($storage);

        return $storage;
    }
}
