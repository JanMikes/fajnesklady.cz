<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\DeleteStorageTypeCommand;
use App\Repository\StorageTypeRepository;
use App\Service\Security\StorageTypeVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/storage-types/{id}/delete', name: 'portal_storage_types_delete', methods: ['POST'])]
#[IsGranted('ROLE_LANDLORD')]
final class StorageTypeDeleteController extends AbstractController
{
    public function __construct(
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $storageType = $this->storageTypeRepository->get(Uuid::fromString($id));

        $this->denyAccessUnlessGranted(StorageTypeVoter::DELETE, $storageType);

        // CSRF protection
        if (!$this->isCsrfTokenValid('delete_storage_type_'.$id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Neplatný CSRF token.');

            return $this->redirectToRoute('portal_storage_types_list');
        }

        $this->commandBus->dispatch(new DeleteStorageTypeCommand(storageTypeId: $storageType->id));

        $this->addFlash('success', 'Typ skladu byl úspěšně smazán.');

        return $this->redirectToRoute('portal_storage_types_list');
    }
}
