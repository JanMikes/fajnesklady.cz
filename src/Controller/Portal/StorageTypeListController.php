<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Repository\PlaceRepository;
use App\Repository\StorageTypeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/places/{placeId}/storage-types', name: 'portal_storage_types_list')]
#[IsGranted('ROLE_ADMIN')]
final class StorageTypeListController extends AbstractController
{
    public function __construct(
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly PlaceRepository $placeRepository,
    ) {
    }

    public function __invoke(Request $request, string $placeId): Response
    {
        $place = $this->placeRepository->get(Uuid::fromString($placeId));

        $page = max(1, (int) $request->query->get('page', '1'));
        $limit = 20;

        $storageTypes = $this->storageTypeRepository->findByPlacePaginated($place, $page, $limit);
        $totalStorageTypes = $this->storageTypeRepository->countByPlace($place);

        $totalPages = (int) ceil($totalStorageTypes / $limit);

        return $this->render('portal/storage_type/list.html.twig', [
            'place' => $place,
            'storageTypes' => $storageTypes,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalStorageTypes' => $totalStorageTypes,
        ]);
    }
}
