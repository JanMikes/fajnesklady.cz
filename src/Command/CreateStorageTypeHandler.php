<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\StorageType;
use App\Repository\StorageTypeRepository;
use App\Service\Identity\ProvideIdentity;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CreateStorageTypeHandler
{
    public function __construct(
        private StorageTypeRepository $storageTypeRepository,
        private ClockInterface $clock,
        private ProvideIdentity $identityProvider,
    ) {
    }

    public function __invoke(CreateStorageTypeCommand $command): StorageType
    {
        $now = $this->clock->now();

        $storageType = new StorageType(
            id: $this->identityProvider->next(),
            name: $command->name,
            innerWidth: $command->innerWidth,
            innerHeight: $command->innerHeight,
            innerLength: $command->innerLength,
            defaultPricePerWeek: $command->defaultPricePerWeek,
            defaultPricePerMonth: $command->defaultPricePerMonth,
            createdAt: $now,
            uniformStorages: $command->uniformStorages,
            outerWidth: $command->outerWidth,
            outerHeight: $command->outerHeight,
            outerLength: $command->outerLength,
        );

        if (null !== $command->description) {
            $storageType->updateDetails(
                name: $command->name,
                innerWidth: $command->innerWidth,
                innerHeight: $command->innerHeight,
                innerLength: $command->innerLength,
                outerWidth: $command->outerWidth,
                outerHeight: $command->outerHeight,
                outerLength: $command->outerLength,
                defaultPricePerWeek: $command->defaultPricePerWeek,
                defaultPricePerMonth: $command->defaultPricePerMonth,
                description: $command->description,
                uniformStorages: $command->uniformStorages,
                now: $now,
            );
        }

        $this->storageTypeRepository->save($storageType);

        return $storageType;
    }
}
