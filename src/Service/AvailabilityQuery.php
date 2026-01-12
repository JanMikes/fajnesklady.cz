<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Place;
use App\Entity\StorageType;
use App\Repository\StorageRepository;

/**
 * Service for querying availability for calendars.
 *
 * Calculates per-day availability counts for StorageTypes and Places.
 */
final readonly class AvailabilityQuery
{
    public function __construct(
        private StorageRepository $storageRepository,
        private StorageAvailabilityChecker $availabilityChecker,
    ) {
    }

    /**
     * Get daily availability for a storage type over a date range.
     *
     * @return array<string, array{date: string, total: int, available: int, occupied: int}>
     */
    public function getStorageTypeAvailability(
        StorageType $storageType,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
    ): array {
        $storages = $this->storageRepository->findByStorageType($storageType);
        $totalStorages = count($storages);

        $result = [];
        $currentDate = $startDate;

        while ($currentDate <= $endDate) {
            $dateKey = $currentDate->format('Y-m-d');
            $availableCount = 0;

            foreach ($storages as $storage) {
                if ($this->availabilityChecker->isAvailableOnDate($storage, $currentDate)) {
                    ++$availableCount;
                }
            }

            $result[$dateKey] = [
                'date' => $dateKey,
                'total' => $totalStorages,
                'available' => $availableCount,
                'occupied' => $totalStorages - $availableCount,
            ];

            $currentDate = $currentDate->modify('+1 day');
        }

        return $result;
    }

    /**
     * Get daily availability for all storage types in a place.
     *
     * @return array<string, array<string, array{date: string, storage_type_id: string, storage_type_name: string, total: int, available: int, occupied: int}>>
     */
    public function getPlaceAvailability(
        Place $place,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
    ): array {
        $storages = $this->storageRepository->findByPlace($place);

        // Group storages by storage type
        $storagesByType = [];
        foreach ($storages as $storage) {
            $typeId = $storage->storageType->id->toRfc4122();
            if (!isset($storagesByType[$typeId])) {
                $storagesByType[$typeId] = [
                    'type' => $storage->storageType,
                    'storages' => [],
                ];
            }
            $storagesByType[$typeId]['storages'][] = $storage;
        }

        $result = [];
        $currentDate = $startDate;

        while ($currentDate <= $endDate) {
            $dateKey = $currentDate->format('Y-m-d');
            $result[$dateKey] = [];

            foreach ($storagesByType as $typeId => $typeData) {
                $totalStorages = count($typeData['storages']);
                $availableCount = 0;

                foreach ($typeData['storages'] as $storage) {
                    if ($this->availabilityChecker->isAvailableOnDate($storage, $currentDate)) {
                        ++$availableCount;
                    }
                }

                $result[$dateKey][$typeId] = [
                    'date' => $dateKey,
                    'storage_type_id' => $typeId,
                    'storage_type_name' => $typeData['type']->name,
                    'total' => $totalStorages,
                    'available' => $availableCount,
                    'occupied' => $totalStorages - $availableCount,
                ];
            }

            $currentDate = $currentDate->modify('+1 day');
        }

        return $result;
    }

    /**
     * Get availability summary for a storage type on a specific date.
     *
     * @return array{total: int, available: int, occupied: int, percentage: float}
     */
    public function getStorageTypeSummary(
        StorageType $storageType,
        \DateTimeImmutable $date,
    ): array {
        $storages = $this->storageRepository->findByStorageType($storageType);
        $totalStorages = count($storages);
        $availableCount = 0;

        foreach ($storages as $storage) {
            if ($this->availabilityChecker->isAvailableOnDate($storage, $date)) {
                ++$availableCount;
            }
        }

        $occupiedCount = $totalStorages - $availableCount;
        $percentage = $totalStorages > 0 ? ($occupiedCount / $totalStorages) * 100 : 0;

        return [
            'total' => $totalStorages,
            'available' => $availableCount,
            'occupied' => $occupiedCount,
            'percentage' => round($percentage, 1),
        ];
    }

    /**
     * Get first available date for a storage type starting from given date.
     */
    public function getFirstAvailableDate(
        StorageType $storageType,
        \DateTimeImmutable $startDate,
        int $maxDaysToSearch = 365,
    ): ?\DateTimeImmutable {
        $storages = $this->storageRepository->findByStorageType($storageType);

        if (0 === count($storages)) {
            return null;
        }

        $currentDate = $startDate;
        $endDate = $startDate->modify("+{$maxDaysToSearch} days");

        while ($currentDate <= $endDate) {
            foreach ($storages as $storage) {
                if ($this->availabilityChecker->isAvailableOnDate($storage, $currentDate)) {
                    return $currentDate;
                }
            }
            $currentDate = $currentDate->modify('+1 day');
        }

        return null;
    }

    /**
     * Check if storage type has any availability in a date range.
     */
    public function hasAvailabilityInRange(
        StorageType $storageType,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
    ): bool {
        $storages = $this->storageRepository->findByStorageType($storageType);

        foreach ($storages as $storage) {
            if ($this->availabilityChecker->isAvailable($storage, $startDate, $endDate)) {
                return true;
            }
        }

        return false;
    }
}
