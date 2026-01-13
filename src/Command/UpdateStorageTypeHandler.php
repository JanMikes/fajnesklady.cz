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
            innerWidth: $command->innerWidth,
            innerHeight: $command->innerHeight,
            innerLength: $command->innerLength,
            outerWidth: $command->outerWidth,
            outerHeight: $command->outerHeight,
            outerLength: $command->outerLength,
            pricePerWeek: $command->pricePerWeek,
            pricePerMonth: $command->pricePerMonth,
            description: $command->description,
            now: $this->clock->now(),
        );

        $this->storageTypeRepository->save($storageType);
    }
}
