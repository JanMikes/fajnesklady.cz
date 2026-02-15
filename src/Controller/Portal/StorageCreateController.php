<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\CreateStorageCommand;
use App\Form\StorageFormData;
use App\Form\StorageFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/storages/create', name: 'portal_storages_create')]
#[IsGranted('ROLE_ADMIN')]
final class StorageCreateController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(StorageFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var StorageFormData $formData */
            $formData = $form->getData();

            if (null === $formData->storageTypeId) {
                throw new \InvalidArgumentException('Storage type ID must be provided');
            }

            if (null === $formData->placeId) {
                throw new \InvalidArgumentException('Place ID must be provided');
            }

            $command = new CreateStorageCommand(
                number: $formData->number,
                coordinates: $formData->getCoordinates(),
                storageTypeId: Uuid::fromString($formData->storageTypeId),
                placeId: Uuid::fromString($formData->placeId),
            );

            $this->commandBus->dispatch($command);

            $this->addFlash('success', 'Sklad byl úspěšně vytvořen.');

            return $this->redirectToRoute('portal_storages_list', [
                'storage_type' => $formData->storageTypeId,
            ]);
        }

        return $this->render('portal/storage/create.html.twig', [
            'form' => $form,
        ]);
    }
}
