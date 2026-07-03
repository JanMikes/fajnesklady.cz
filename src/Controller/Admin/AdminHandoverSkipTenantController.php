<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Command\SkipTenantHandoverCommand;
use App\Entity\User;
use App\Repository\HandoverProtocolRepository;
use App\Service\Messenger\HandlerFailureUnwrap;
use App\Service\Security\HandoverProtocolVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route(
    '/portal/admin/predavaci-protokol/{id}/preskocit-najemce',
    name: 'admin_handover_skip_tenant',
    requirements: ['id' => '[0-9a-f-]{36}'],
    methods: ['POST'],
)]
#[IsGranted('ROLE_ADMIN')]
final class AdminHandoverSkipTenantController extends AbstractController
{
    public function __construct(
        private readonly HandoverProtocolRepository $handoverProtocolRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $id, #[CurrentUser] User $admin): Response
    {
        $protocol = $this->handoverProtocolRepository->get(Uuid::fromString($id));
        $this->denyAccessUnlessGranted(HandoverProtocolVoter::VIEW, $protocol);

        try {
            $this->commandBus->dispatch(new SkipTenantHandoverCommand(
                handoverProtocolId: $protocol->id,
                skippedById: $admin->id,
            ));
        } catch (\Throwable $rawException) {
            $exception = HandlerFailureUnwrap::unwrap($rawException);
            if ($exception instanceof \DomainException) {
                $this->addFlash('error', $exception->getMessage());

                return $this->redirectToRoute('admin_handover_view', ['id' => $id]);
            }

            throw $rawException;
        }

        $message = 'Strana nájemce byla přeskočena.';
        if ($protocol->isFullyCompleted()) {
            $message .= ' Protokol je tím dokončen.';
        }
        $this->addFlash('success', $message);

        return $this->redirectToRoute('admin_handover_view', ['id' => $id]);
    }
}
