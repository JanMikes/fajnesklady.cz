<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\UpdateStorageCommand;
use App\Form\StorageFormData;
use App\Form\StorageFormType;
use App\Repository\StorageRepository;
use App\Service\Security\StorageVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/storages/{id}/edit', name: 'portal_storages_edit')]
#[IsGranted('ROLE_LANDLORD')]
final class StorageEditController extends AbstractController
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
        $this->denyAccessUnlessGranted(StorageVoter::EDIT, $storage);

        $formData = StorageFormData::fromStorage($storage);
        $form = $this->createForm(StorageFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $command = new UpdateStorageCommand(
                storageId: $storage->id,
                number: $formData->number,
                coordinates: $formData->getCoordinates(),
            );

            $this->commandBus->dispatch($command);

            $this->addFlash('success', 'Sklad byl úspěšně aktualizován.');

            return $this->redirectToRoute('portal_storages_list', [
                'storage_type' => $storage->storageType->id->toRfc4122(),
            ]);
        }

        return $this->render('portal/storage/edit.html.twig', [
            'form' => $form,
            'storage' => $storage,
        ]);
    }
}
