<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\VerifyUserByAdminCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/users/{id}/verify', name: 'portal_users_verify', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class UserVerifyController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $this->commandBus->dispatch(new VerifyUserByAdminCommand(
            userId: Uuid::fromString($id),
        ));

        $this->addFlash('success', 'Uživatel byl ověřen.');

        return $this->redirectToRoute('portal_users_view', ['id' => $id]);
    }
}
