<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\DeleteStorageUnavailabilityCommand;
use App\Entity\User;
use App\Repository\StorageUnavailabilityRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/unavailabilities/{id}/delete', name: 'portal_unavailabilities_delete', methods: ['POST'])]
#[IsGranted('ROLE_LANDLORD')]
final class StorageUnavailabilityDeleteController extends AbstractController
{
    public function __construct(
        private readonly StorageUnavailabilityRepository $unavailabilityRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $unavailability = $this->unavailabilityRepository->get(Uuid::fromString($id));

        // Check ownership - only the owner or admin can delete
        $storage = $unavailability->storage;
        if (!$this->isGranted('ROLE_ADMIN') && !$storage->isOwnedBy($user)) {
            throw $this->createAccessDeniedException();
        }

        $command = new DeleteStorageUnavailabilityCommand(unavailabilityId: $unavailability->id);
        $this->commandBus->dispatch($command);

        $this->addFlash('success', 'Blokování skladu bylo úspěšně smazáno.');

        return $this->redirectToRoute('portal_unavailabilities_list');
    }
}
