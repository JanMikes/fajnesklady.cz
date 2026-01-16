<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\AddStorageTypePhotoCommand;
use App\Command\CreateStorageTypeCommand;
use App\Entity\StorageType;
use App\Form\StorageTypeFormData;
use App\Form\StorageTypeFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/storage-types/create', name: 'portal_storage_types_create')]
#[IsGranted('ROLE_ADMIN')]
final class StorageTypeCreateController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(StorageTypeFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var StorageTypeFormData $formData */
            $formData = $form->getData();

            // Convert CZK to halire (cents)
            $defaultPricePerWeek = (int) round(($formData->defaultPricePerWeek ?? 0.0) * 100);
            $defaultPricePerMonth = (int) round(($formData->defaultPricePerMonth ?? 0.0) * 100);

            $command = new CreateStorageTypeCommand(
                name: $formData->name,
                innerWidth: $formData->innerWidth ?? 0,
                innerHeight: $formData->innerHeight ?? 0,
                innerLength: $formData->innerLength ?? 0,
                outerWidth: $formData->outerWidth,
                outerHeight: $formData->outerHeight,
                outerLength: $formData->outerLength,
                defaultPricePerWeek: $defaultPricePerWeek,
                defaultPricePerMonth: $defaultPricePerMonth,
                description: $formData->description,
            );

            $envelope = $this->commandBus->dispatch($command);
            /** @var StorageType $storageType */
            $storageType = $envelope->last(HandledStamp::class)?->getResult();

            // Handle photo uploads
            foreach ($formData->photos as $uploadedFile) {
                $this->commandBus->dispatch(new AddStorageTypePhotoCommand(
                    storageTypeId: $storageType->id,
                    file: $uploadedFile,
                ));
            }

            $this->addFlash('success', 'Typ skladu byl úspěšně vytvořen.');

            return $this->redirectToRoute('portal_storage_types_list');
        }

        return $this->render('portal/storage_type/create.html.twig', [
            'form' => $form,
        ]);
    }
}
