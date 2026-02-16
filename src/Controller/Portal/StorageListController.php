<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Service\Security\PlaceVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/places/{placeId}/storages', name: 'portal_storages_list')]
#[IsGranted('ROLE_LANDLORD')]
final class StorageListController extends AbstractController
{
    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly PlaceRepository $placeRepository,
    ) {
    }

    public function __invoke(Request $request, string $placeId): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $place = $this->placeRepository->get(Uuid::fromString($placeId));
        $this->denyAccessUnlessGranted(PlaceVoter::VIEW, $place);

        $storageTypeId = $request->query->get('storage_type');

        $selectedStorageType = null;
        if (null !== $storageTypeId && '' !== $storageTypeId) {
            $selectedStorageType = $this->storageTypeRepository->find(Uuid::fromString($storageTypeId));
        }

        $owner = $this->isGranted('ROLE_ADMIN') ? null : $user;
        $storages = $this->storageRepository->findFiltered($owner, $place, $selectedStorageType);
        $storageTypes = $this->storageTypeRepository->findByPlace($place);

        // Check prerequisites for adding storages
        $hasMapImage = null !== $place->mapImagePath;
        $hasStorageTypes = [] !== $storageTypes;
        $canvasReady = $hasMapImage && $hasStorageTypes;

        return $this->render('portal/storage/list.html.twig', [
            'storages' => $storages,
            'storageTypes' => $storageTypes,
            'selectedStorageType' => $selectedStorageType,
            'place' => $place,
            'hasMapImage' => $hasMapImage,
            'hasStorageTypes' => $hasStorageTypes,
            'canvasReady' => $canvasReady,
        ]);
    }
}
