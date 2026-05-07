<?php

declare(strict_types=1);

namespace App\Query;

use App\Entity\Storage;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Repository\UserRepository;
use App\Service\Storage\StorageOccupancyService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GetPlaceTypeOccupancyOverviewQuery
{
    public function __construct(
        private PlaceRepository $placeRepository,
        private StorageTypeRepository $storageTypeRepository,
        private StorageRepository $storageRepository,
        private UserRepository $userRepository,
        private StorageOccupancyService $occupancyService,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(GetPlaceTypeOccupancyOverview $query): GetPlaceTypeOccupancyOverviewResult
    {
        $place = $this->placeRepository->get($query->placeId);
        $now = $this->clock->now();

        $owner = null !== $query->landlordId
            ? $this->userRepository->get($query->landlordId)
            : null;

        $storages = null === $owner
            ? $this->storageRepository->findByPlace($place)
            : $this->storageRepository->findByOwnerAndPlace($owner, $place);

        $views = $this->occupancyService->currentViews($storages, $now);

        $storageTypes = $this->storageTypeRepository->findByPlace($place);

        $rows = [];
        foreach ($storageTypes as $storageType) {
            $typeStorages = array_filter(
                $storages,
                static fn (Storage $storage): bool => $storage->storageType->id->equals($storageType->id),
            );

            $totalCount = 0;
            $occupiedCount = 0;
            $availableCount = 0;
            $blockedCount = 0;
            $nextFreeing = null;
            $nextBooked = null;

            foreach ($typeStorages as $storage) {
                ++$totalCount;
                $view = $views[$storage->id->toRfc4122()] ?? null;
                if (null === $view) {
                    continue;
                }

                if ($view->isOccupied) {
                    ++$occupiedCount;
                    if (null !== $view->rentedUntil
                        && (null === $nextFreeing || $view->rentedUntil < $nextFreeing)) {
                        $nextFreeing = $view->rentedUntil;
                    }
                } elseif ($view->isBlocked) {
                    ++$blockedCount;
                } else {
                    ++$availableCount;
                    if (null !== $view->nextBookedFrom
                        && (null === $nextBooked || $view->nextBookedFrom < $nextBooked)) {
                        $nextBooked = $view->nextBookedFrom;
                    }
                }
            }

            if (0 === $totalCount) {
                continue;
            }

            $rows[] = new GetPlaceTypeOccupancyRow(
                storageType: $storageType,
                totalCount: $totalCount,
                occupiedCount: $occupiedCount,
                availableCount: $availableCount,
                blockedCount: $blockedCount,
                nextFreeingDate: $nextFreeing,
                nextBookedDate: $nextBooked,
            );
        }

        usort(
            $rows,
            static fn (GetPlaceTypeOccupancyRow $a, GetPlaceTypeOccupancyRow $b): int => strnatcmp($a->storageType->name, $b->storageType->name)
        );

        return new GetPlaceTypeOccupancyOverviewResult(rows: $rows);
    }
}
