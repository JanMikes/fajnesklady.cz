<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\StorageUnavailability;
use App\Repository\StorageUnavailabilityRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DeleteStorageUnavailabilityHandler
{
    public function __construct(
        private StorageUnavailabilityRepository $unavailabilityRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(DeleteStorageUnavailabilityCommand $command): void
    {
        $unavailability = $this->unavailabilityRepository->find($command->unavailabilityId);

        if (null === $unavailability) {
            return;
        }

        $storage = $unavailability->storage;
        $this->unavailabilityRepository->delete($unavailability);

        // Recompute the unit's status from whatever blocks REMAIN active today —
        // unconditionally, NOT only when the deleted block is still active. A
        // bounded block that already lapsed still left the unit stuck
        // `manually_unavailable` (the status was set when the block was created
        // and nothing resets it on expiry), so deleting such a block must release
        // the unit. We keep it blocked only when ANOTHER block covers today; the
        // just-removed row isn't flushed yet, so exclude it by id.
        $today = $this->clock->now();
        $otherActiveBlocks = array_filter(
            $this->unavailabilityRepository->findActiveByStorageOnDate($storage, $today),
            static fn (StorageUnavailability $block): bool => !$block->id->equals($unavailability->id),
        );

        if ([] === $otherActiveBlocks) {
            $storage->release($today);
        }
    }
}
