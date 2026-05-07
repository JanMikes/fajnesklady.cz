<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Entity\Storage;
use App\Enum\OrderStatus;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Repository\StorageUnavailabilityRepository;
use App\Value\RentalSpan;
use App\Value\RentalSpanKind;
use App\Value\StorageRentalView;

/**
 * Bulk-fetches the live rental state for a set of storages so list / planning
 * surfaces can render "do kdy / od kdy / kdo" without N+1 queries.
 *
 * Contract beats Order beats StorageUnavailability — see {@see self::currentViews()}.
 */
final readonly class StorageOccupancyService
{
    public function __construct(
        private ContractRepository $contractRepository,
        private OrderRepository $orderRepository,
        private StorageUnavailabilityRepository $unavailabilityRepository,
    ) {
    }

    /**
     * @param Storage[] $storages
     *
     * @return array<string, StorageRentalView> keyed by Storage->id->toRfc4122()
     */
    public function currentViews(array $storages, \DateTimeImmutable $now): array
    {
        if ([] === $storages) {
            return [];
        }

        $contracts = $this->contractRepository->findActiveByStorages($storages, $now);
        $orders = $this->orderRepository->findActiveByStoragesInDateRange($storages, $now, $now);
        $unavailabilities = $this->unavailabilityRepository->findByStoragesInDateRange($storages, $now, $now);

        $contractByStorage = [];
        foreach ($contracts as $contract) {
            // Earliest startDate wins so the "rentedFrom" reflects when the customer first moved in.
            $key = $contract->storage->id->toRfc4122();
            if (!isset($contractByStorage[$key]) || $contract->startDate < $contractByStorage[$key]->startDate) {
                $contractByStorage[$key] = $contract;
            }
        }

        $orderByStorage = [];
        foreach ($orders as $order) {
            // COMPLETED orders mean a contract has already taken over the
            // storage; they must never appear as the "current order" — only
            // pre-contract states count for that role.
            if (OrderStatus::COMPLETED === $order->status) {
                continue;
            }
            $key = $order->storage->id->toRfc4122();
            if (!isset($orderByStorage[$key]) || $order->startDate < $orderByStorage[$key]->startDate) {
                $orderByStorage[$key] = $order;
            }
        }

        $blockByStorage = [];
        foreach ($unavailabilities as $block) {
            $key = $block->storage->id->toRfc4122();
            if (!isset($blockByStorage[$key]) || $block->startDate < $blockByStorage[$key]->startDate) {
                $blockByStorage[$key] = $block;
            }
        }

        // Pre-compute "next future booking" only for storages that have an
        // existing rentedUntil OR are currently free — the result is keyed by
        // storage id so a single bulk lookup suffices.
        $nextStartFromContracts = $this->contractRepository->findNextStartByStorages($storages, $now);
        $nextStartFromOrders = $this->orderRepository->findNextStartByStorages($storages, $now);

        $views = [];
        foreach ($storages as $storage) {
            $key = $storage->id->toRfc4122();
            $contract = $contractByStorage[$key] ?? null;
            $order = null === $contract ? ($orderByStorage[$key] ?? null) : null;
            $block = (null === $contract && null === $order) ? ($blockByStorage[$key] ?? null) : null;

            $rentedFrom = match (true) {
                null !== $contract => $contract->startDate,
                null !== $order => $order->startDate,
                default => null,
            };
            $rentedUntil = match (true) {
                null !== $contract => $contract->terminatesAt ?? $contract->endDate,
                null !== $order => $order->endDate,
                default => null,
            };

            $availableFrom = null !== $rentedUntil ? $rentedUntil->modify('+1 day') : null;

            $boundary = $rentedUntil ?? $now;
            $nextBookedFrom = $this->earliestAfter($boundary, [
                $nextStartFromContracts[$key] ?? null,
                $nextStartFromOrders[$key] ?? null,
            ]);

            $views[$key] = new StorageRentalView(
                storage: $storage,
                currentContract: $contract,
                currentOrder: $order,
                rentedFrom: $rentedFrom,
                rentedUntil: $rentedUntil,
                blockedBy: $block,
                availableFrom: $availableFrom,
                nextBookedFrom: $nextBookedFrom,
            );
        }

        return $views;
    }

    /**
     * @param Storage[] $storages
     *
     * @return array<string, RentalSpan[]> keyed by Storage->id->toRfc4122()
     */
    public function spansInRange(array $storages, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ([] === $storages) {
            return [];
        }

        $contracts = $this->contractRepository->findOverlappingByStorages($storages, $from, $to);
        $orders = $this->orderRepository->findActiveByStoragesInDateRange($storages, $from, $to);
        $unavailabilities = $this->unavailabilityRepository->findByStoragesInDateRange($storages, $from, $to);

        $contractStorageIds = [];
        foreach ($contracts as $contract) {
            $contractStorageIds[$contract->storage->id->toRfc4122()] = true;
        }

        $byStorage = [];
        foreach ($storages as $storage) {
            $byStorage[$storage->id->toRfc4122()] = [];
        }

        foreach ($contracts as $contract) {
            $key = $contract->storage->id->toRfc4122();
            $byStorage[$key][] = new RentalSpan(
                storage: $contract->storage,
                kind: RentalSpanKind::CONTRACT,
                startDate: $contract->startDate,
                endDate: $contract->terminatesAt ?? $contract->endDate,
                tenantName: $contract->user->fullName,
                source: $contract,
            );
        }

        foreach ($orders as $order) {
            $key = $order->storage->id->toRfc4122();
            // Skip orders whose storage already has a contract — the contract
            // window is the authoritative source so we don't double-render.
            if (isset($contractStorageIds[$key])) {
                continue;
            }
            $byStorage[$key][] = new RentalSpan(
                storage: $order->storage,
                kind: RentalSpanKind::ORDER,
                startDate: $order->startDate,
                endDate: $order->endDate,
                tenantName: $order->user->fullName,
                source: $order,
            );
        }

        foreach ($unavailabilities as $block) {
            $key = $block->storage->id->toRfc4122();
            $byStorage[$key][] = new RentalSpan(
                storage: $block->storage,
                kind: RentalSpanKind::BLOCK,
                startDate: $block->startDate,
                endDate: $block->endDate,
                tenantName: null,
                source: $block,
            );
        }

        foreach ($byStorage as &$spans) {
            usort($spans, static fn (RentalSpan $a, RentalSpan $b): int => $a->startDate <=> $b->startDate);
        }

        return $byStorage;
    }

    /**
     * @param array<\DateTimeImmutable|null> $candidates
     */
    private function earliestAfter(\DateTimeImmutable $boundary, array $candidates): ?\DateTimeImmutable
    {
        $best = null;
        foreach ($candidates as $candidate) {
            if (null === $candidate) {
                continue;
            }
            if ($candidate <= $boundary) {
                continue;
            }
            if (null === $best || $candidate < $best) {
                $best = $candidate;
            }
        }

        return $best;
    }
}
