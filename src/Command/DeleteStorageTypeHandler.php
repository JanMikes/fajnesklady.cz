<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DeleteStorageTypeHandler
{
    public function __construct(
        private StorageTypeRepository $storageTypeRepository,
        private StorageRepository $storageRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(DeleteStorageTypeCommand $command): void
    {
        $storageType = $this->storageTypeRepository->get($command->storageTypeId);

        $now = $this->clock->now();

        foreach ($this->storageRepository->findByStorageType($storageType) as $storage) {
            if (!$storage->isDeleted()) {
                $storage->softDelete($now);
            }
        }

        $storageType->softDelete($now);
    }
}
