<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\CreateStorageTypeCommand;
use App\Form\StorageTypeFormData;
use App\Form\StorageTypeFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/storage-types/create', name: 'portal_storage_types_create')]
#[IsGranted('ROLE_LANDLORD')]
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

            // placeId must be provided - either from admin form or from the user's default place
            if (null === $formData->placeId) {
                throw new \InvalidArgumentException('Place ID must be provided');
            }
            $placeId = Uuid::fromString($formData->placeId);

            // Convert CZK to halire (cents)
            $pricePerWeek = (int) round(($formData->pricePerWeek ?? 0.0) * 100);
            $pricePerMonth = (int) round(($formData->pricePerMonth ?? 0.0) * 100);

            $command = new CreateStorageTypeCommand(
                name: $formData->name,
                width: $formData->width ?? 0,
                height: $formData->height ?? 0,
                length: $formData->length ?? 0,
                pricePerWeek: $pricePerWeek,
                pricePerMonth: $pricePerMonth,
                description: $formData->description,
                placeId: $placeId,
            );

            $this->commandBus->dispatch($command);

            $this->addFlash('success', 'Typ skladu byl úspěšně vytvořen.');

            return $this->redirectToRoute('portal_storage_types_list');
        }

        return $this->render('portal/storage_type/create.html.twig', [
            'form' => $form,
        ]);
    }
}
