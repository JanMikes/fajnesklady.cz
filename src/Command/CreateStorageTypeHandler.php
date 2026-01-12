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
            width: $command->width,
            height: $command->height,
            length: $command->length,
            pricePerWeek: $command->pricePerWeek,
            pricePerMonth: $command->pricePerMonth,
            place: $place,
            createdAt: $this->clock->now(),
        );

        if (null !== $command->description) {
            $storageType->updateDetails(
                name: $command->name,
                width: $command->width,
                height: $command->height,
                length: $command->length,
                pricePerWeek: $command->pricePerWeek,
                pricePerMonth: $command->pricePerMonth,
                description: $command->description,
                now: $this->clock->now(),
            );
        }

        $this->storageTypeRepository->save($storageType);

        return $storageType;
    }
}
