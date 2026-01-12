<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\StorageType;
use App\Exception\UserNotFoundException;
use App\Identity\ProvideIdentity;
use App\Repository\StorageTypeRepository;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CreateStorageTypeHandler
{
    public function __construct(
        private StorageTypeRepository $storageTypeRepository,
        private UserRepository $userRepository,
        private ClockInterface $clock,
        private ProvideIdentity $identityProvider,
    ) {
    }

    public function __invoke(CreateStorageTypeCommand $command): StorageType
    {
        $owner = $this->userRepository->findById($command->ownerId);
        if (null === $owner) {
            throw UserNotFoundException::withId($command->ownerId);
        }

        $storageType = new StorageType(
            id: $this->identityProvider->next(),
            name: $command->name,
            width: $command->width,
            height: $command->height,
            length: $command->length,
            pricePerWeek: $command->pricePerWeek,
            pricePerMonth: $command->pricePerMonth,
            owner: $owner,
            createdAt: $this->clock->now(),
        );

        $this->storageTypeRepository->save($storageType);

        return $storageType;
    }
}
