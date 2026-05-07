<?php

declare(strict_types=1);

namespace App\Value;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\StorageUnavailability;

/**
 * Snapshot of a single storage's live rental state.
 *
 * Conceptually `final readonly`, but PHP 8.4 forbids hooked properties on a
 * readonly class — so the constructor params carry `readonly` individually
 * while the computed booleans / tenantName ride property hooks.
 */
final class StorageRentalView
{
    public function __construct(
        public readonly Storage $storage,
        public readonly ?Contract $currentContract,
        public readonly ?Order $currentOrder,
        public readonly ?\DateTimeImmutable $rentedFrom,
        public readonly ?\DateTimeImmutable $rentedUntil,
        public readonly ?StorageUnavailability $blockedBy,
        public readonly ?\DateTimeImmutable $availableFrom,
        public readonly ?\DateTimeImmutable $nextBookedFrom,
    ) {
    }

    public bool $isOccupied {
        get => null !== $this->currentContract || null !== $this->currentOrder;
    }

    public bool $isBlocked {
        get => null !== $this->blockedBy;
    }

    public bool $isFree {
        get => !$this->isOccupied && !$this->isBlocked;
    }

    public bool $isUnlimited {
        get => $this->isOccupied && null === $this->rentedUntil;
    }

    public bool $isTerminating {
        get => null !== $this->currentContract && null !== $this->currentContract->terminatesAt;
    }

    public ?string $tenantName {
        get => $this->currentContract?->user->fullName ?? $this->currentOrder?->user->fullName;
    }
}
