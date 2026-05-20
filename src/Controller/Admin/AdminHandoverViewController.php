<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\HandoverProtocolRepository;
use App\Service\Security\HandoverProtocolVoter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/predavaci-protokol/{id}', name: 'admin_handover_view', requirements: ['id' => '[0-9a-f-]{36}'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminHandoverViewController extends AbstractController
{
    public function __construct(
        private readonly HandoverProtocolRepository $handoverProtocolRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $protocol = $this->handoverProtocolRepository->get(Uuid::fromString($id));
        // ROLE_ADMIN short-circuits at HandoverProtocolVoter.php:40 — kept here
        // for symmetry with the tenant / landlord handover controllers.
        $this->denyAccessUnlessGranted(HandoverProtocolVoter::VIEW, $protocol);

        $contract = $protocol->contract;

        return $this->render('admin/handover/view.html.twig', [
            'protocol' => $protocol,
            'contract' => $contract,
            'storage' => $contract->storage,
            'place' => $contract->storage->place,
            'now' => $this->clock->now(),
        ]);
    }
}
