<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\DeleteStorageTypeCommand;
use App\Repository\PlaceRepository;
use App\Repository\StorageTypeRepository;
use App\Service\Security\StorageTypeVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/places/{placeId}/storage-types/{id}/delete', name: 'portal_storage_types_delete', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class StorageTypeDeleteController extends AbstractController
{
    public function __construct(
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly PlaceRepository $placeRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $placeId, string $id): Response
    {
        $place = $this->placeRepository->get(Uuid::fromString($placeId));
        $storageType = $this->storageTypeRepository->get(Uuid::fromString($id));

        // Verify storage type belongs to this place
        if ($storageType->place->id->toRfc4122() !== $place->id->toRfc4122()) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(StorageTypeVoter::DELETE, $storageType);

        $this->commandBus->dispatch(new DeleteStorageTypeCommand(storageTypeId: $storageType->id));

        $this->addFlash('success', 'Typ skladu byl úspěšně smazán.');

        return $this->redirectToRoute('portal_storage_types_list', ['placeId' => $placeId]);
    }
}
