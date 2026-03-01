<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\StorageUnavailability;
use App\Exception\StorageHasActiveRental;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
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
        private ContractRepository $contractRepository,
        private OrderRepository $orderRepository,
        private ClockInterface $clock,
        private ProvideIdentity $identityProvider,
    ) {
    }

    public function __invoke(CreateStorageUnavailabilityCommand $command): StorageUnavailability
    {
        $storage = $this->storageRepository->get($command->storageId);
        $createdBy = $this->userRepository->get($command->createdById);

        // Ensure no active contracts overlap with the blocking period
        $overlappingContracts = $this->contractRepository->findOverlappingByStorage(
            $storage,
            $command->startDate,
            $command->endDate,
        );
        if (count($overlappingContracts) > 0) {
            throw StorageHasActiveRental::cannotBlock($storage);
        }

        // Ensure no active orders overlap with the blocking period
        $overlappingOrders = $this->orderRepository->findOverlappingByStorage(
            $storage,
            $command->startDate,
            $command->endDate,
        );
        if (count($overlappingOrders) > 0) {
            throw StorageHasActiveRental::cannotBlock($storage);
        }

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
