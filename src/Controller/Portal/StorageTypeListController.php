<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Repository\StorageTypeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/storage-types', name: 'portal_storage_types_list')]
#[IsGranted('ROLE_LANDLORD')]
final class StorageTypeListController extends AbstractController
{
    public function __construct(
        private readonly StorageTypeRepository $storageTypeRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $limit = 20;

        // All users see all storage types (storage types are now global)
        $storageTypes = $this->storageTypeRepository->findAllPaginated($page, $limit);
        $totalStorageTypes = $this->storageTypeRepository->countTotal();

        $totalPages = (int) ceil($totalStorageTypes / $limit);

        return $this->render('portal/storage_type/list.html.twig', [
            'storageTypes' => $storageTypes,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalStorageTypes' => $totalStorageTypes,
        ]);
    }
}
