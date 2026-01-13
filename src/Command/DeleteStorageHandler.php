<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\StorageCannotBeDeleted;
use App\Repository\StorageRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DeleteStorageHandler
{
    public function __construct(
        private StorageRepository $storageRepository,
    ) {
    }

    public function __invoke(DeleteStorageCommand $command): void
    {
        $storage = $this->storageRepository->get($command->storageId);

        if ($storage->isOccupied()) {
            throw StorageCannotBeDeleted::becauseItIsOccupied($storage);
        }

        if ($storage->isReserved()) {
            throw StorageCannotBeDeleted::becauseItIsReserved($storage);
        }

        $this->storageRepository->delete($storage);
    }
}
