<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/storages', name: 'portal_storages_list')]
#[IsGranted('ROLE_LANDLORD')]
final class StorageListController extends AbstractController
{
    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly StorageTypeRepository $storageTypeRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $storageTypeId = $request->query->get('storage_type');

        $storages = [];
        $selectedStorageType = null;

        if (null !== $storageTypeId && '' !== $storageTypeId) {
            $selectedStorageType = $this->storageTypeRepository->find(Uuid::fromString($storageTypeId));

            if (null !== $selectedStorageType) {
                // Verify ownership
                if (!$this->isGranted('ROLE_ADMIN') && !$selectedStorageType->isOwnedBy($user)) {
                    throw $this->createAccessDeniedException();
                }

                $storages = $this->storageRepository->findByStorageType($selectedStorageType);
            }
        }

        // Get storage types for the filter dropdown
        if ($this->isGranted('ROLE_ADMIN')) {
            $storageTypes = $this->storageTypeRepository->findAll();
        } else {
            $storageTypes = $this->storageTypeRepository->findByOwner($user);
        }

        return $this->render('portal/storage/list.html.twig', [
            'storages' => $storages,
            'storageTypes' => $storageTypes,
            'selectedStorageType' => $selectedStorageType,
        ]);
    }
}
