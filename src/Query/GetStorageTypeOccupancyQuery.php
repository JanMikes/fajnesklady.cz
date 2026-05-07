<?php

declare(strict_types=1);

namespace App\Query;

use App\Entity\Storage;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Repository\UserRepository;
use App\Service\Storage\StorageOccupancyService;
use App\Value\StorageRentalView;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GetStorageTypeOccupancyQuery
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

    public function __invoke(GetStorageTypeOccupancy $query): GetStorageTypeOccupancyResult
    {
        $place = $this->placeRepository->get($query->placeId);
        $storageType = $this->storageTypeRepository->get($query->storageTypeId);
        $now = $this->clock->now();

        $storages = $this->storageRepository->findByStorageTypeAndPlace($storageType, $place);
        if (null !== $query->landlordId) {
            $landlord = $this->userRepository->get($query->landlordId);
            $storages = array_values(array_filter(
                $storages,
                static fn (Storage $storage): bool => null !== $storage->owner && $storage->owner->id->equals($landlord->id),
            ));
        }

        $views = $this->occupancyService->currentViews($storages, $now);

        $occupiedCount = 0;
        $availableCount = 0;
        $blockedCount = 0;
        $nextFreeing = null;
        $nextBooked = null;

        foreach ($views as $view) {
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

        $rows = array_values($views);
        usort($rows, static function (StorageRentalView $a, StorageRentalView $b): int {
            $rank = static fn (StorageRentalView $v): int => match (true) {
                $v->isOccupied => 0,
                $v->isBlocked => 2,
                default => 1,
            };

            $byRank = $rank($a) <=> $rank($b);
            if (0 !== $byRank) {
                return $byRank;
            }

            if ($a->isOccupied && $b->isOccupied) {
                if (null === $a->rentedUntil && null === $b->rentedUntil) {
                    return strnatcmp($a->storage->number, $b->storage->number);
                }
                if (null === $a->rentedUntil) {
                    return 1;
                }
                if (null === $b->rentedUntil) {
                    return -1;
                }

                return $a->rentedUntil <=> $b->rentedUntil;
            }

            return strnatcmp($a->storage->number, $b->storage->number);
        });

        return new GetStorageTypeOccupancyResult(
            storageType: $storageType,
            totalCount: count($views),
            occupiedCount: $occupiedCount,
            availableCount: $availableCount,
            blockedCount: $blockedCount,
            nextFreeingDate: $nextFreeing,
            nextBookedDate: $nextBooked,
            rows: $rows,
        );
    }
}
