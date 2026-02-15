<?php

declare(strict_types=1);

namespace App\Controller\Portal\Admin;

use App\Command\DenyPlaceAccessRequestCommand;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/place-access-requests/{id}/deny', name: 'portal_admin_place_access_request_deny', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class PlaceAccessRequestDenyController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $id): Response
    {
        /** @var User $admin */
        $admin = $this->getUser();

        $this->commandBus->dispatch(new DenyPlaceAccessRequestCommand(
            requestId: Uuid::fromString($id),
            deniedById: $admin->id,
        ));

        $this->addFlash('success', 'Žádost o přístup byla zamítnuta.');

        return $this->redirectToRoute('portal_admin_place_access_requests');
    }
}
