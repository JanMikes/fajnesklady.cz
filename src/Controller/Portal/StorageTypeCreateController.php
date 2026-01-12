<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\CreateStorageTypeCommand;
use App\Entity\User;
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
        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(StorageTypeFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var StorageTypeFormData $formData */
            $formData = $form->getData();

            // If not admin, owner is always current user
            $ownerId = $this->isGranted('ROLE_ADMIN') && null !== $formData->ownerId
                ? Uuid::fromString($formData->ownerId)
                : $user->id;

            // Convert CZK to halire (cents)
            $pricePerWeek = (int) round(($formData->pricePerWeek ?? 0.0) * 100);
            $pricePerMonth = (int) round(($formData->pricePerMonth ?? 0.0) * 100);

            $command = new CreateStorageTypeCommand(
                name: $formData->name,
                width: (string) $formData->width,
                height: (string) $formData->height,
                length: (string) $formData->length,
                pricePerWeek: $pricePerWeek,
                pricePerMonth: $pricePerMonth,
                ownerId: $ownerId,
            );

            $this->commandBus->dispatch($command);

            $this->addFlash('success', 'Typ skladu byl uspesne vytvoren.');

            return $this->redirectToRoute('portal_storage_types_list');
        }

        return $this->render('portal/storage_type/create.html.twig', [
            'form' => $form,
        ]);
    }
}
