<?php

declare(strict_types=1);

namespace App\Command;

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

        // Release the storage if it was marked unavailable by this record
        $today = $this->clock->now();
        if ($unavailability->isActiveOn($today)) {
            // Check if there are other active unavailabilities
            $otherUnavailabilities = $this->unavailabilityRepository->findActiveByStorageOnDate($storage, $today);
            if (0 === count($otherUnavailabilities)) {
                $storage->release($today);
            }
        }
    }
}
