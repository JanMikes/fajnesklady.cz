<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\StorageTypePhoto;
use App\Repository\StorageTypePhotoRepository;
use App\Repository\StorageTypeRepository;
use App\Service\Identity\ProvideIdentity;
use App\Service\StorageTypePhotoUploader;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AddStorageTypePhotoHandler
{
    public function __construct(
        private StorageTypeRepository $storageTypeRepository,
        private StorageTypePhotoRepository $photoRepository,
        private StorageTypePhotoUploader $photoUploader,
        private ProvideIdentity $identityProvider,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(AddStorageTypePhotoCommand $command): StorageTypePhoto
    {
        $storageType = $this->storageTypeRepository->get($command->storageTypeId);

        $path = $this->photoUploader->uploadPhoto($command->file, $storageType->id);
        $position = $this->photoRepository->getNextPosition($storageType->id);

        $photo = new StorageTypePhoto(
            id: $this->identityProvider->next(),
            storageType: $storageType,
            path: $path,
            position: $position,
            createdAt: $this->clock->now(),
        );

        $this->photoRepository->save($photo);
        $storageType->addPhoto($photo);

        return $photo;
    }
}
