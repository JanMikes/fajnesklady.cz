<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\DeleteStoragePhotoCommand;
use App\Repository\StoragePhotoRepository;
use App\Service\Security\StorageVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/storages/{storageId}/photos/{photoId}/delete', name: 'portal_storage_photo_delete', methods: ['POST'])]
#[IsGranted('ROLE_LANDLORD')]
final class StoragePhotoDeleteController extends AbstractController
{
    public function __construct(
        private readonly StoragePhotoRepository $photoRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $storageId, string $photoId): Response
    {
        $photo = $this->photoRepository->find(Uuid::fromString($photoId));

        if (null === $photo) {
            throw $this->createNotFoundException('Photo not found');
        }

        $this->denyAccessUnlessGranted(StorageVoter::MANAGE_PHOTOS, $photo->storage);

        $this->commandBus->dispatch(new DeleteStoragePhotoCommand(
            photoId: $photo->id,
        ));

        $this->addFlash('success', 'Fotografie byla úspěšně odstraněna.');

        return $this->redirectToRoute('portal_storages_edit', ['id' => $storageId]);
    }
}
