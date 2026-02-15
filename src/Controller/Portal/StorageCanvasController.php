<?php

declare(strict_types=1);

namespace App\Controller\Portal;

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

        $hasMapImage = null !== $place->mapImagePath;
        $allStorageTypes = $this->storageTypeRepository->findAllActive();
        $hasStorageTypes = [] !== $allStorageTypes;

        if (!$hasMapImage || !$hasStorageTypes) {
            return $this->render('portal/storage/canvas.html.twig', [
                'place' => $place,
                'canvasReady' => false,
                'hasMapImage' => $hasMapImage,
                'hasStorageTypes' => $hasStorageTypes,
            ]);
        }

        $storages = $this->storageRepository->findByPlace($place);
        $storageTypes = $this->storageTypeRepository->findByPlace($place);

        // For the type dropdown, use all active types (not just those already used at this place)
        $storageTypesData = array_map(fn ($t) => [
            'id' => $t->id->toRfc4122(),
            'name' => $t->name,
            'dimensions' => $t->getDimensionsInMeters(),
        ], $allStorageTypes);

        $storagesData = array_map(fn ($s) => [
            'id' => $s->id->toRfc4122(),
            'number' => $s->number,
            'storageTypeId' => $s->storageType->id->toRfc4122(),
            'coordinates' => $s->coordinates,
            'status' => $s->status->value,
        ], $storages);

        return $this->render('portal/storage/canvas.html.twig', [
            'place' => $place,
            'canvasReady' => true,
            'storagesJson' => json_encode($storagesData),
            'storageTypesJson' => json_encode($storageTypesData),
            'storageTypes' => $allStorageTypes,
        ]);
    }
}
