<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\HandoverPhotoRepository;
use App\Service\HandoverPhotoUploader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RemoveHandoverPhotoHandler
{
    public function __construct(
        private HandoverPhotoRepository $photoRepository,
        private HandoverPhotoUploader $photoUploader,
    ) {
    }

    public function __invoke(RemoveHandoverPhotoCommand $command): void
    {
        $photo = $this->photoRepository->get($command->photoId);

        $this->photoUploader->deletePhoto($photo->path);
        $photo->handoverProtocol->removePhoto($photo);
        $this->photoRepository->delete($photo);
    }
}
