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
                // Admin sees all storages, landlord sees only their own
                if ($this->isGranted('ROLE_ADMIN')) {
                    $storages = $this->storageRepository->findByStorageType($selectedStorageType);
                } else {
                    // Filter storages by owner for landlords
                    $allStorages = $this->storageRepository->findByStorageType($selectedStorageType);
                    $storages = array_filter($allStorages, fn ($s) => $s->isOwnedBy($user));
                }
            }
        } else {
            // When no filter is selected, show all owned storages for landlord, all for admin
            if ($this->isGranted('ROLE_ADMIN')) {
                $storages = $this->storageRepository->findAll();
            } else {
                $storages = $this->storageRepository->findByOwner($user);
            }
        }

        // All users see all storage types for the filter dropdown (storage types are global)
        $storageTypes = $this->storageTypeRepository->findAll();

        return $this->render('portal/storage/list.html.twig', [
            'storages' => $storages,
            'storageTypes' => $storageTypes,
            'selectedStorageType' => $selectedStorageType,
        ]);
    }
}
