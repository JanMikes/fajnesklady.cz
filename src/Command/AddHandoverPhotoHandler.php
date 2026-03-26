<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\HandoverPhoto;
use App\Repository\HandoverPhotoRepository;
use App\Repository\HandoverProtocolRepository;
use App\Service\HandoverPhotoUploader;
use App\Service\Identity\ProvideIdentity;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AddHandoverPhotoHandler
{
    public function __construct(
        private HandoverProtocolRepository $handoverProtocolRepository,
        private HandoverPhotoRepository $photoRepository,
        private HandoverPhotoUploader $photoUploader,
        private ProvideIdentity $identityProvider,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(AddHandoverPhotoCommand $command): HandoverPhoto
    {
        $protocol = $this->handoverProtocolRepository->get($command->handoverProtocolId);

        $path = $this->photoUploader->uploadPhoto($command->file, $protocol->id);
        $position = $this->photoRepository->getNextPosition($protocol->id, $command->uploadedBy);

        $photo = new HandoverPhoto(
            id: $this->identityProvider->next(),
            handoverProtocol: $protocol,
            path: $path,
            position: $position,
            uploadedBy: $command->uploadedBy,
            createdAt: $this->clock->now(),
        );

        $this->photoRepository->save($photo);
        $protocol->addPhoto($photo);

        return $photo;
    }
}
