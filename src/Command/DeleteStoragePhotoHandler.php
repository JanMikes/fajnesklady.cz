<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\StoragePhotoNotFound;
use App\Repository\StoragePhotoRepository;
use App\Service\StoragePhotoUploader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DeleteStoragePhotoHandler
{
    public function __construct(
        private StoragePhotoRepository $photoRepository,
        private StoragePhotoUploader $photoUploader,
    ) {
    }

    public function __invoke(DeleteStoragePhotoCommand $command): void
    {
        $photo = $this->photoRepository->find($command->photoId);

        if (null === $photo) {
            throw StoragePhotoNotFound::withId($command->photoId);
        }

        $this->photoUploader->deletePhoto($photo->path);
        $this->photoRepository->delete($photo);
    }
}
