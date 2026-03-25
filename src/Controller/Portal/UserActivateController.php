<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\ActivateUserCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/users/{id}/activate', name: 'portal_users_activate', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class UserActivateController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $this->commandBus->dispatch(new ActivateUserCommand(
            userId: Uuid::fromString($id),
        ));

        $this->addFlash('success', 'Uživatel byl aktivován.');

        return $this->redirectToRoute('portal_users_view', ['id' => $id]);
    }
}
