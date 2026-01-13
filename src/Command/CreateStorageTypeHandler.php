<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\StorageType;
use App\Repository\PlaceRepository;
use App\Repository\StorageTypeRepository;
use App\Service\Identity\ProvideIdentity;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CreateStorageTypeHandler
{
    public function __construct(
        private StorageTypeRepository $storageTypeRepository,
        private PlaceRepository $placeRepository,
        private ClockInterface $clock,
        private ProvideIdentity $identityProvider,
    ) {
    }

    public function __invoke(CreateStorageTypeCommand $command): StorageType
    {
        $place = $this->placeRepository->get($command->placeId);

        $storageType = new StorageType(
            id: $this->identityProvider->next(),
            name: $command->name,
            innerWidth: $command->innerWidth,
            innerHeight: $command->innerHeight,
            innerLength: $command->innerLength,
            pricePerWeek: $command->pricePerWeek,
            pricePerMonth: $command->pricePerMonth,
            place: $place,
            createdAt: $this->clock->now(),
        );

        $storageType->updateDetails(
            name: $command->name,
            innerWidth: $command->innerWidth,
            innerHeight: $command->innerHeight,
            innerLength: $command->innerLength,
            outerWidth: $command->outerWidth,
            outerHeight: $command->outerHeight,
            outerLength: $command->outerLength,
            pricePerWeek: $command->pricePerWeek,
            pricePerMonth: $command->pricePerMonth,
            description: $command->description,
            now: $this->clock->now(),
        );

        $this->storageTypeRepository->save($storageType);

        return $storageType;
    }
}
