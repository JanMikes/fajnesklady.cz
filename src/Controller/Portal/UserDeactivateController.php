<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\DeactivateUserCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/users/{id}/deactivate', name: 'portal_users_deactivate', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class UserDeactivateController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $reason = $request->request->getString('reason');

        $this->commandBus->dispatch(new DeactivateUserCommand(
            userId: Uuid::fromString($id),
            reason: '' !== $reason ? $reason : null,
        ));

        $this->addFlash('success', 'Uživatel byl deaktivován.');

        return $this->redirectToRoute('portal_users_view', ['id' => $id]);
    }
}
