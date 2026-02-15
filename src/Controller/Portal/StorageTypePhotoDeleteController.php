<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\DeleteStorageTypePhotoCommand;
use App\Repository\StorageTypePhotoRepository;
use App\Service\Security\StorageTypeVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/places/{placeId}/storage-types/{storageTypeId}/photos/{photoId}/delete', name: 'portal_storage_type_photo_delete', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class StorageTypePhotoDeleteController extends AbstractController
{
    public function __construct(
        private readonly StorageTypePhotoRepository $photoRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $placeId, string $storageTypeId, string $photoId): Response
    {
        $photo = $this->photoRepository->find(Uuid::fromString($photoId));

        if (null === $photo) {
            throw $this->createNotFoundException('Photo not found');
        }

        // Check ownership via voter
        $this->denyAccessUnlessGranted(StorageTypeVoter::EDIT, $photo->storageType);

        $this->commandBus->dispatch(new DeleteStorageTypePhotoCommand(
            photoId: $photo->id,
        ));

        $this->addFlash('success', 'Fotografie byla úspěšně odstraněna.');

        return $this->redirectToRoute('portal_storage_types_edit', ['placeId' => $placeId, 'id' => $storageTypeId]);
    }
}
