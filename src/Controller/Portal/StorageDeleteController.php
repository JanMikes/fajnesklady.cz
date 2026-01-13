<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\DeleteStorageCommand;
use App\Repository\StorageRepository;
use App\Service\Security\StorageVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/storages/{id}/delete', name: 'portal_storages_delete', methods: ['POST'])]
#[IsGranted('ROLE_LANDLORD')]
final class StorageDeleteController extends AbstractController
{
    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $storage = $this->storageRepository->get(Uuid::fromString($id));

        // Check ownership via voter
        $this->denyAccessUnlessGranted(StorageVoter::DELETE, $storage);

        $storageTypeId = $storage->storageType->id->toRfc4122();

        $command = new DeleteStorageCommand(storageId: $storage->id);
        $this->commandBus->dispatch($command);

        $this->addFlash('success', 'Sklad byl úspěšně smazán.');

        return $this->redirectToRoute('portal_storages_list', [
            'storage_type' => $storageTypeId,
        ]);
    }
}
