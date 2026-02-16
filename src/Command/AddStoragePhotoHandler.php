<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\StoragePhoto;
use App\Repository\StoragePhotoRepository;
use App\Repository\StorageRepository;
use App\Service\Identity\ProvideIdentity;
use App\Service\StoragePhotoUploader;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AddStoragePhotoHandler
{
    public function __construct(
        private StorageRepository $storageRepository,
        private StoragePhotoRepository $photoRepository,
        private StoragePhotoUploader $photoUploader,
        private ProvideIdentity $identityProvider,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(AddStoragePhotoCommand $command): StoragePhoto
    {
        $storage = $this->storageRepository->get($command->storageId);

        $path = $this->photoUploader->uploadPhoto($command->file, $storage->id);
        $position = $this->photoRepository->getNextPosition($storage->id);

        $photo = new StoragePhoto(
            id: $this->identityProvider->next(),
            storage: $storage,
            path: $path,
            position: $position,
            createdAt: $this->clock->now(),
        );

        $this->photoRepository->save($photo);
        $storage->addPhoto($photo);

        return $photo;
    }
}
