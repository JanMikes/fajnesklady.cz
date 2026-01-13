<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Service\Security\PlaceVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/places/{placeId}/canvas', name: 'portal_storage_canvas')]
#[IsGranted('ROLE_LANDLORD')]
final class StorageCanvasController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly StorageTypeRepository $storageTypeRepository,
    ) {
    }

    public function __invoke(string $placeId): Response
    {
        $place = $this->placeRepository->get(Uuid::fromString($placeId));

        // Check ownership via voter
        $this->denyAccessUnlessGranted(PlaceVoter::EDIT, $place);

        $storages = $this->storageRepository->findByPlace($place);
        $storageTypes = $this->storageTypeRepository->findByPlace($place);

        // Prepare storage data for JavaScript
        $storagesData = array_map(fn($s) => [
            'id' => $s->id->toRfc4122(),
            'number' => $s->number,
            'storageTypeId' => $s->storageType->id->toRfc4122(),
            'coordinates' => $s->coordinates,
            'status' => $s->status->value,
        ], $storages);

        $storageTypesData = array_map(fn($t) => [
            'id' => $t->id->toRfc4122(),
            'name' => $t->name,
            'dimensions' => $t->getDimensionsInMeters(),
        ], $storageTypes);

        return $this->render('portal/storage/canvas.html.twig', [
            'place' => $place,
            'storagesJson' => json_encode($storagesData),
            'storageTypesJson' => json_encode($storageTypesData),
            'storageTypes' => $storageTypes,
        ]);
    }
}
