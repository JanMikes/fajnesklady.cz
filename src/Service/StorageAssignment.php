<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Exception\NoStorageAvailable;
use App\Repository\ContractRepository;
use App\Repository\StorageRepository;

/**
 * Service for assigning storages to rentals.
 *
 * Priority:
 * 1. If extending rental, try to assign SAME storage user currently has
 * 2. Prefer storages currently in AVAILABLE status
 * 3. If all occupied, find storage that becomes free before requested start date
 *
 * CRITICAL: Never double-book a storage!
 */
final readonly class StorageAssignment
{
    public function __construct(
        private StorageRepository $storageRepository,
        private ContractRepository $contractRepository,
        private StorageAvailabilityChecker $availabilityChecker,
    ) {
    }

    /**
     * Assign an available storage of the given type at the given place for the requested period.
     *
     * @throws NoStorageAvailable When no storage is available for the period
     */
    public function assignStorage(
        StorageType $storageType,
        Place $place,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        ?User $user = null,
    ): Storage {
        // Priority 1: If user has an active contract for this storage type at this place, prefer same storage
        if (null !== $user) {
            $preferredStorage = $this->findPreferredStorageForUser($user, $storageType, $place, $startDate, $endDate);
            if (null !== $preferredStorage) {
                return $preferredStorage;
            }
        }

        // Priority 2: Find any available storage of this type at this place
        $availableStorage = $this->findFirstAvailableStorage($storageType, $place, $startDate, $endDate);
        if (null !== $availableStorage) {
            return $availableStorage;
        }

        // No storage available
        throw NoStorageAvailable::forStorageType($storageType, $startDate, $endDate);
    }

    /**
     * Find user's preferred storage (same one they currently have).
     * This is used for extensions to keep user in the same storage.
     */
    private function findPreferredStorageForUser(
        User $user,
        StorageType $storageType,
        Place $place,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
    ): ?Storage {
        // Find user's active contracts for this storage type
        $activeContracts = $this->contractRepository->findActiveByUser($user, $startDate);

        foreach ($activeContracts as $contract) {
            $storage = $contract->storage;

            // Check if storage is of the requested type and at the requested place
            if (!$storage->storageType->id->equals($storageType->id)) {
                continue;
            }
            if (!$storage->place->id->equals($place->id)) {
                continue;
            }

            // Check if this storage would be available for the new period
            // Exclude the current contract since it will end when the new one starts
            if ($this->availabilityChecker->isAvailable($storage, $startDate, $endDate, excludeContract: $contract)) {
                return $storage;
            }
        }

        return null;
    }

    /**
     * Find the first available storage of the given type at the given place.
     */
    private function findFirstAvailableStorage(
        StorageType $storageType,
        Place $place,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
    ): ?Storage {
        // Get all storages of this type at this place
        $storages = $this->storageRepository->findByStorageTypeAndPlace($storageType, $place);

        foreach ($storages as $storage) {
            if ($this->availabilityChecker->isAvailable($storage, $startDate, $endDate)) {
                return $storage;
            }
        }

        return null;
    }

    /**
     * Check if any storage of the given type at the given place is available for the period.
     */
    public function hasAvailableStorage(
        StorageType $storageType,
        Place $place,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
    ): bool {
        return null !== $this->findFirstAvailableStorage($storageType, $place, $startDate, $endDate);
    }

    /**
     * Count available storages of the given type at the given place for the period.
     */
    public function countAvailableStorages(
        StorageType $storageType,
        Place $place,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
    ): int {
        $storages = $this->storageRepository->findByStorageTypeAndPlace($storageType, $place);
        $count = 0;

        foreach ($storages as $storage) {
            if ($this->availabilityChecker->isAvailable($storage, $startDate, $endDate)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Get all available storages of the given type at the given place for the period.
     *
     * @return Storage[]
     */
    public function findAvailableStorages(
        StorageType $storageType,
        Place $place,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
    ): array {
        $storages = $this->storageRepository->findByStorageTypeAndPlace($storageType, $place);
        $available = [];

        foreach ($storages as $storage) {
            if ($this->availabilityChecker->isAvailable($storage, $startDate, $endDate)) {
                $available[] = $storage;
            }
        }

        return $available;
    }
}
