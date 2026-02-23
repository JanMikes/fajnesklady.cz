<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Storage;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class UpdateStorageHandler
{
    public function __construct(
        private StorageRepository $storageRepository,
        private StorageTypeRepository $storageTypeRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(UpdateStorageCommand $command): Storage
    {
        $storage = $this->storageRepository->get($command->storageId);
        $now = $this->clock->now();

        $storage->updateDetails(
            number: $command->number,
            coordinates: $command->coordinates,
            now: $now,
        );

        if (null !== $command->storageTypeId && !$storage->storageType->id->equals($command->storageTypeId)) {
            $storageType = $this->storageTypeRepository->get($command->storageTypeId);
            $storage->changeStorageType($storageType, $now);
        }

        if ($command->updatePrices) {
            $storage->updatePrices(
                pricePerWeek: $command->pricePerWeek,
                pricePerMonth: $command->pricePerMonth,
                now: $now,
            );
        }

        if ($command->updateCommissionRate) {
            $storage->updateCommissionRate(
                commissionRate: $command->commissionRate,
                now: $now,
            );
        }

        $this->storageRepository->save($storage);

        return $storage;
    }
}
