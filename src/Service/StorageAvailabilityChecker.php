<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Storage;
use App\Enum\StorageStatus;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Repository\StorageUnavailabilityRepository;
use App\Value\StorageAvailability;

/**
 * Service for checking storage availability.
 *
 * A storage is NOT available for a period if ANY of these overlap:
 * - StorageUnavailability record (manual blocks)
 * - Order with status in (reserved, awaiting_payment, paid)
 * - Active Contract (not terminated, not past end date)
 */
final readonly class StorageAvailabilityChecker
{
    public function __construct(
        private StorageUnavailabilityRepository $unavailabilityRepository,
        private OrderRepository $orderRepository,
        private ContractRepository $contractRepository,
    ) {
    }

    /**
     * Check if a storage is available for a given period.
     *
     * @param Order|null    $excludeOrder    Order to exclude from check (for updates)
     * @param Contract|null $excludeContract Contract to exclude from check (for updates)
     */
    public function isAvailable(
        Storage $storage,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        ?Order $excludeOrder = null,
        ?Contract $excludeContract = null,
    ): bool {
        // Check current storage status - if manually unavailable, it's not available
        if (StorageStatus::MANUALLY_UNAVAILABLE === $storage->status) {
            return false;
        }

        // Check for overlapping manual unavailability
        $unavailabilities = $this->unavailabilityRepository->findOverlappingByStorage(
            $storage,
            $startDate,
            $endDate,
        );
        if (count($unavailabilities) > 0) {
            return false;
        }

        // Check for overlapping orders
        $overlappingOrders = $this->orderRepository->findOverlappingByStorage(
            $storage,
            $startDate,
            $endDate,
            $excludeOrder,
        );
        if (count($overlappingOrders) > 0) {
            return false;
        }

        // Check for overlapping contracts
        $overlappingContracts = $this->contractRepository->findOverlappingByStorage(
            $storage,
            $startDate,
            $endDate,
            $excludeContract,
        );
        if (count($overlappingContracts) > 0) {
            return false;
        }

        return true;
    }

    /**
     * "Clean" = no engagement of any kind overlapping [$from, ∞): no live or
     * future contract, no blocking order, no manual block. Spec 084: manual map
     * picks require clean units so a stranger can't grab a unit whose sitting
     * tenant may still prolong; engaged-but-free units remain auto-assignable.
     */
    public function isClean(Storage $storage, \DateTimeImmutable $from): bool
    {
        return $this->isAvailable($storage, $from, null);
    }

    /**
     * Bulk variant of {@see self::isClean()} — same three-query shape as
     * availabilityForStorages().
     *
     * @param Storage[] $storages
     *
     * @return array<string, bool> keyed by Storage->id->toRfc4122()
     */
    public function cleanForStorages(array $storages, \DateTimeImmutable $from): array
    {
        return array_map(
            static fn (StorageAvailability $a): bool => $a->isAvailable,
            $this->availabilityForStorages($storages, $from, null),
        );
    }

    /**
     * Bulk, date-range availability for a set of storages over ONE window.
     * Reuses the EXACT predicates of {@see self::isAvailable()} — manual block
     * status + overlapping unavailability records + overlapping orders +
     * overlapping contracts — so the availability map can never disagree with
     * order-acceptance enforcement. Runs three queries total (not per storage).
     *
     * @param Storage[] $storages
     *
     * @return array<string, StorageAvailability> keyed by Storage->id->toRfc4122()
     */
    public function availabilityForStorages(
        array $storages,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
    ): array {
        if ([] === $storages) {
            return [];
        }

        $blockedIds = [];
        foreach ($this->unavailabilityRepository->findOverlappingByStorages($storages, $startDate, $endDate) as $block) {
            $blockedIds[$block->storage->id->toRfc4122()] = true;
        }

        $contractedIds = [];
        foreach ($this->contractRepository->findOverlappingByStorages($storages, $startDate, $endDate) as $contract) {
            $contractedIds[$contract->storage->id->toRfc4122()] = true;
        }

        $orderedIds = [];
        foreach ($this->orderRepository->findOverlappingByStorages($storages, $startDate, $endDate) as $order) {
            $orderedIds[$order->storage->id->toRfc4122()] = true;
        }

        $result = [];
        foreach ($storages as $storage) {
            $key = $storage->id->toRfc4122();
            $result[$key] = $this->decide(
                $storage,
                hasOverlappingBlock: isset($blockedIds[$key]),
                hasOverlappingContract: isset($contractedIds[$key]),
                hasOverlappingOrder: isset($orderedIds[$key]),
            );
        }

        return $result;
    }

    /**
     * Decide a storage's availability + derived status from its booking state.
     * Precedence mirrors {@see self::getBlockingReasons()}: manual block, then
     * active contract, then blocking order. A storage manually marked
     * unavailable is blocked on every date, regardless of overlaps.
     */
    private function decide(
        Storage $storage,
        bool $hasOverlappingBlock,
        bool $hasOverlappingContract,
        bool $hasOverlappingOrder,
    ): StorageAvailability {
        if (StorageStatus::MANUALLY_UNAVAILABLE === $storage->status || $hasOverlappingBlock) {
            return new StorageAvailability(false, StorageStatus::MANUALLY_UNAVAILABLE);
        }

        if ($hasOverlappingContract) {
            return new StorageAvailability(false, StorageStatus::OCCUPIED);
        }

        if ($hasOverlappingOrder) {
            return new StorageAvailability(false, StorageStatus::RESERVED);
        }

        return new StorageAvailability(true, StorageStatus::AVAILABLE);
    }

    /**
     * Earliest start of anything blocking $storage from $from onward,
     * excluding the given contract and its order (spec 077: computes the
     * latest possible prolongation end — the day before this date). Null
     * means the horizon is free. A result ≤ $from means the unit is already
     * taken right after the current contract (prolongation impossible).
     */
    public function earliestConflictStart(
        Storage $storage,
        \DateTimeImmutable $from,
        ?Contract $excludeContract = null,
        ?Order $excludeOrder = null,
    ): ?\DateTimeImmutable {
        $starts = [];

        foreach ($this->unavailabilityRepository->findOverlappingByStorage($storage, $from, null) as $block) {
            $starts[] = $block->startDate;
        }

        foreach ($this->orderRepository->findOverlappingByStorage($storage, $from, null, $excludeOrder) as $order) {
            $starts[] = $order->startDate;
        }

        foreach ($this->contractRepository->findOverlappingByStorage($storage, $from, null, $excludeContract) as $contract) {
            $starts[] = $contract->startDate;
        }

        if ([] === $starts) {
            return null;
        }

        return min($starts);
    }

    /**
     * Get blocking reasons for a storage in a given period.
     *
     * @return array<string, array<object>>
     */
    public function getBlockingReasons(
        Storage $storage,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
    ): array {
        $reasons = [];

        if (StorageStatus::MANUALLY_UNAVAILABLE === $storage->status) {
            $reasons['status'] = [$storage];
        }

        $unavailabilities = $this->unavailabilityRepository->findOverlappingByStorage(
            $storage,
            $startDate,
            $endDate,
        );
        if (count($unavailabilities) > 0) {
            $reasons['unavailabilities'] = $unavailabilities;
        }

        $overlappingOrders = $this->orderRepository->findOverlappingByStorage(
            $storage,
            $startDate,
            $endDate,
        );
        if (count($overlappingOrders) > 0) {
            $reasons['orders'] = $overlappingOrders;
        }

        $overlappingContracts = $this->contractRepository->findOverlappingByStorage(
            $storage,
            $startDate,
            $endDate,
        );
        if (count($overlappingContracts) > 0) {
            $reasons['contracts'] = $overlappingContracts;
        }

        return $reasons;
    }

    /**
     * Check if a storage is available on a specific date.
     */
    public function isAvailableOnDate(Storage $storage, \DateTimeImmutable $date): bool
    {
        return $this->isAvailable($storage, $date, $date);
    }
}
