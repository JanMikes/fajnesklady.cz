<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Entity\Storage;
use App\Enum\StorageStatus;
use App\Value\StorageRentalView;

/**
 * Derives the canonical {@see StorageStatus} for a storage from its LIVE booking
 * state (active contract / order / manual block), so the denormalized
 * `storage.status` column can be healed whenever it drifts.
 *
 * The mapping mirrors the entity's own lifecycle transitions, making the
 * reconciled column identical to what the discrete events would have produced
 * had none ever been missed:
 *
 *   active contract        -> OCCUPIED              (Storage::occupy on Order::complete)
 *   active blocking order  -> RESERVED              (Storage::reserve)
 *   active manual block    -> MANUALLY_UNAVAILABLE  (Storage::markUnavailable)
 *   nothing                -> AVAILABLE             (Storage::release)
 *
 * It deliberately reuses {@see StorageOccupancyService::currentViews()} — the
 * SAME single-day live computation the map / list / calendar render from — so a
 * reconciled column can never disagree with those surfaces.
 */
final readonly class StorageStatusReconciler
{
    public function __construct(
        private StorageOccupancyService $occupancyService,
    ) {
    }

    /**
     * Bulk-derive the status each storage SHOULD have at $now from live bookings.
     *
     * @param Storage[] $storages
     *
     * @return array<string, StorageStatus> keyed by Storage->id->toRfc4122()
     */
    public function deriveStatuses(array $storages, \DateTimeImmutable $now): array
    {
        $statuses = [];
        foreach ($this->occupancyService->currentViews($storages, $now) as $key => $view) {
            $statuses[$key] = self::statusForView($view);
        }

        return $statuses;
    }

    public function deriveStatus(Storage $storage, \DateTimeImmutable $now): StorageStatus
    {
        return $this->deriveStatuses([$storage], $now)[$storage->id->toRfc4122()];
    }

    /**
     * Pure mapping from a live rental snapshot to the cached status value.
     *
     * Precedence (contract > order > block) is already baked into the view by
     * {@see StorageOccupancyService::currentViews()}: it nulls out the order
     * when a contract is present and the block when either is present.
     */
    public static function statusForView(StorageRentalView $view): StorageStatus
    {
        return match (true) {
            null !== $view->currentContract => StorageStatus::OCCUPIED,
            null !== $view->currentOrder => StorageStatus::RESERVED,
            null !== $view->blockedBy => StorageStatus::MANUALLY_UNAVAILABLE,
            default => StorageStatus::AVAILABLE,
        };
    }
}
