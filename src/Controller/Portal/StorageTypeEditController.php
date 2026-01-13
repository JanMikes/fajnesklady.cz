<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\AddStorageTypePhotoCommand;
use App\Command\UpdateStorageTypeCommand;
use App\Form\StorageTypeFormData;
use App\Form\StorageTypeFormType;
use App\Repository\StorageTypeRepository;
use App\Service\Security\StorageTypeVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/storage-types/{id}/edit', name: 'portal_storage_types_edit')]
#[IsGranted('ROLE_LANDLORD')]
final class StorageTypeEditController extends AbstractController
{
    public function __construct(
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $storageType = $this->storageTypeRepository->get(Uuid::fromString($id));

        // Check ownership via voter
        $this->denyAccessUnlessGranted(StorageTypeVoter::EDIT, $storageType);

        $formData = StorageTypeFormData::fromStorageType($storageType);
        $form = $this->createForm(StorageTypeFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Convert CZK to halire (cents)
            $pricePerWeek = (int) round(($formData->pricePerWeek ?? 0.0) * 100);
            $pricePerMonth = (int) round(($formData->pricePerMonth ?? 0.0) * 100);

            $command = new UpdateStorageTypeCommand(
                storageTypeId: $storageType->id,
                name: $formData->name,
                innerWidth: $formData->innerWidth ?? 0,
                innerHeight: $formData->innerHeight ?? 0,
                innerLength: $formData->innerLength ?? 0,
                outerWidth: $formData->outerWidth,
                outerHeight: $formData->outerHeight,
                outerLength: $formData->outerLength,
                pricePerWeek: $pricePerWeek,
                pricePerMonth: $pricePerMonth,
                description: $formData->description,
            );

            $this->commandBus->dispatch($command);

            // Handle photo uploads
            foreach ($formData->photos as $uploadedFile) {
                $this->commandBus->dispatch(new AddStorageTypePhotoCommand(
                    storageTypeId: $storageType->id,
                    file: $uploadedFile,
                ));
            }

            $this->addFlash('success', 'Typ skladu byl úspěšně aktualizován.');

            return $this->redirectToRoute('portal_storage_types_list');
        }

        return $this->render('portal/storage_type/edit.html.twig', [
            'form' => $form,
            'storageType' => $storageType,
        ]);
    }
}
