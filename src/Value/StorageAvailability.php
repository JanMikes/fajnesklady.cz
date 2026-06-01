<?php

declare(strict_types=1);

namespace App\Value;

use App\Enum\StorageStatus;

/**
 * Per-storage availability for a concrete rental window, as computed by
 * {@see \App\Service\StorageAvailabilityChecker::availabilityForStorages()}.
 *
 * `derivedStatus` is computed from the booking state on the requested dates
 * (overlapping orders / contracts / manual blocks) — NOT read from the mutable
 * {@see \App\Entity\Storage::$status} column, which drifts and must never gate a
 * booking decision.
 */
final readonly class StorageAvailability
{
    public function __construct(
        public bool $isAvailable,
        public StorageStatus $derivedStatus,
    ) {
    }
}
