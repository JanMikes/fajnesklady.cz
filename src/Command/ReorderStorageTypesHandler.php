<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\StorageTypeNotFound;
use App\Repository\PlaceRepository;
use App\Repository\StorageTypeRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ReorderStorageTypesHandler
{
    public function __construct(
        private StorageTypeRepository $storageTypeRepository,
        private PlaceRepository $placeRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ReorderStorageTypesCommand $command): void
    {
        $now = $this->clock->now();
        $place = $this->placeRepository->get($command->placeId);

        $remaining = [];
        foreach ($this->storageTypeRepository->findByPlace($place) as $storageType) {
            $remaining[$storageType->id->toRfc4122()] = $storageType;
        }

        $position = 0;
        foreach ($command->orderedStorageTypeIds as $storageTypeId) {
            $storageType = $remaining[$storageTypeId->toRfc4122()]
                ?? throw StorageTypeNotFound::withId($storageTypeId);

            unset($remaining[$storageTypeId->toRfc4122()]);
            $storageType->updatePosition($position++, $now);
        }

        // Types missing from the submitted list (e.g. rows on another pagination
        // page) keep their current relative order, after the reordered ones.
        foreach ($remaining as $storageType) {
            $storageType->updatePosition($position++, $now);
        }
    }
}
