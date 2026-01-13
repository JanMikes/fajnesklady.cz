<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\CreateStorageUnavailabilityCommand;
use App\Entity\User;
use App\Form\StorageUnavailabilityFormData;
use App\Form\StorageUnavailabilityFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/unavailabilities/create', name: 'portal_unavailabilities_create')]
#[IsGranted('ROLE_LANDLORD')]
final class StorageUnavailabilityCreateController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $formData = new StorageUnavailabilityFormData();
        $form = $this->createForm(StorageUnavailabilityFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Determine end date based on indefinite checkbox
            $endDate = $formData->indefinite ? null : $formData->endDate;

            $command = new CreateStorageUnavailabilityCommand(
                storageId: Uuid::fromString($formData->storageId ?? ''),
                startDate: $formData->startDate ?? new \DateTimeImmutable(),
                endDate: $endDate,
                reason: $formData->reason,
                createdById: $user->id,
            );

            $this->commandBus->dispatch($command);

            $this->addFlash('success', 'Blokovani skladu bylo uspesne vytvoreno.');

            return $this->redirectToRoute('portal_unavailabilities_list');
        }

        return $this->render('portal/unavailability/create.html.twig', [
            'form' => $form,
        ]);
    }
}
