<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\StorageUnavailability;
use App\Repository\StorageRepository;
use App\Repository\StorageUnavailabilityRepository;
use App\Repository\UserRepository;
use App\Service\Identity\ProvideIdentity;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CreateStorageUnavailabilityHandler
{
    public function __construct(
        private StorageRepository $storageRepository,
        private StorageUnavailabilityRepository $unavailabilityRepository,
        private UserRepository $userRepository,
        private ClockInterface $clock,
        private ProvideIdentity $identityProvider,
    ) {
    }

    public function __invoke(CreateStorageUnavailabilityCommand $command): StorageUnavailability
    {
        $storage = $this->storageRepository->get($command->storageId);
        $createdBy = $this->userRepository->get($command->createdById);

        $unavailability = new StorageUnavailability(
            id: $this->identityProvider->next(),
            storage: $storage,
            startDate: $command->startDate,
            endDate: $command->endDate,
            reason: $command->reason,
            createdBy: $createdBy,
            createdAt: $this->clock->now(),
        );

        $this->unavailabilityRepository->save($unavailability);

        // Mark storage as unavailable if the period includes today
        $today = $this->clock->now();
        if ($unavailability->isActiveOn($today)) {
            $storage->markUnavailable($today);
        }

        return $unavailability;
    }
}
