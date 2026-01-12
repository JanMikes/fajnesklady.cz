<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\StorageTypeRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class UpdateStorageTypeHandler
{
    public function __construct(
        private StorageTypeRepository $storageTypeRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(UpdateStorageTypeCommand $command): void
    {
        $storageType = $this->storageTypeRepository->get($command->storageTypeId);

        $storageType->updateDetails(
            name: $command->name,
            width: $command->width,
            height: $command->height,
            length: $command->length,
            pricePerWeek: $command->pricePerWeek,
            pricePerMonth: $command->pricePerMonth,
            now: $this->clock->now(),
        );

        $this->storageTypeRepository->save($storageType);
    }
}
