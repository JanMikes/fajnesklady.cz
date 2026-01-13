<?php

declare(strict_types=1);

namespace App\Command;

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
        $this->storageRepository->delete($storage);
    }
}
