<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\StorageTypePhotoNotFound;
use App\Repository\StorageTypePhotoRepository;
use App\Service\StorageTypePhotoUploader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DeleteStorageTypePhotoHandler
{
    public function __construct(
        private StorageTypePhotoRepository $photoRepository,
        private StorageTypePhotoUploader $photoUploader,
    ) {
    }

    public function __invoke(DeleteStorageTypePhotoCommand $command): void
    {
        $photo = $this->photoRepository->find($command->photoId);

        if (null === $photo) {
            throw StorageTypePhotoNotFound::withId($command->photoId);
        }

        $this->photoUploader->deletePhoto($photo->path);
        $this->photoRepository->delete($photo);
    }
}
