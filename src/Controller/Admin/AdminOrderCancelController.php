<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Command\CancelOrderCommand;
use App\Repository\OrderRepository;
use App\Service\Security\OrderVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/orders/{id}/cancel', name: 'admin_order_cancel', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminOrderCancelController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $order = $this->orderRepository->get(Uuid::fromString($id));

        $this->denyAccessUnlessGranted(OrderVoter::CANCEL, $order);

        if (!$order->canBeCancelled()) {
            $this->addFlash('error', $order->cancellationBlockedReason() ?? 'Objednávku nelze zrušit.');

            return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
        }

        $this->commandBus->dispatch(new CancelOrderCommand($order));

        $this->addFlash('success', 'Objednávka byla úspěšně zrušena.');

        return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
    }
}
