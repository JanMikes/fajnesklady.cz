<?php

declare(strict_types=1);

namespace App\Controller\Portal\Admin;

use App\Command\ApprovePlaceAccessRequestCommand;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/place-access-requests/{id}/approve', name: 'portal_admin_place_access_request_approve', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class PlaceAccessRequestApproveController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $id): Response
    {
        /** @var User $admin */
        $admin = $this->getUser();

        $this->commandBus->dispatch(new ApprovePlaceAccessRequestCommand(
            requestId: Uuid::fromString($id),
            approvedById: $admin->id,
        ));

        $this->addFlash('success', 'Žádost o přístup byla schválena.');

        return $this->redirectToRoute('portal_admin_place_access_requests');
    }
}
