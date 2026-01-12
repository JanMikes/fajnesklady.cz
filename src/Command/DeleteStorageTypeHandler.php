<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\StorageTypeNotFoundException;
use App\Repository\StorageTypeRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DeleteStorageTypeHandler
{
    public function __construct(
        private StorageTypeRepository $storageTypeRepository,
    ) {
    }

    public function __invoke(DeleteStorageTypeCommand $command): void
    {
        $storageType = $this->storageTypeRepository->findById($command->storageTypeId);
        if (null === $storageType) {
            throw StorageTypeNotFoundException::withId($command->storageTypeId);
        }

        $this->storageTypeRepository->delete($storageType);
    }
}
