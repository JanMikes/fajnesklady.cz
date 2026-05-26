<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Command\CancelFineCommand;
use App\Repository\FineRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/pokuty/{id}/zrusit', name: 'admin_fine_cancel', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminFineCancelController extends AbstractController
{
    public function __construct(
        private readonly FineRepository $fineRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $fine = $this->fineRepository->findById(Uuid::fromString($id));
        if (null === $fine) {
            throw new NotFoundHttpException('Pokuta nenalezena.');
        }

        /** @var \App\Entity\User $admin */
        $admin = $this->getUser();

        $this->commandBus->dispatch(new CancelFineCommand(
            fineId: $fine->id,
            cancelledById: $admin->id,
        ));

        $this->addFlash('success', 'Pokuta zrušena');

        return $this->redirectToRoute('admin_order_detail', ['id' => $fine->contract->order->id]);
    }
}
