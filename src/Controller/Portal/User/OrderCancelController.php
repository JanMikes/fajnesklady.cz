<?php

declare(strict_types=1);

namespace App\Controller\Portal\User;

use App\Command\CancelOrderCommand;
use App\Repository\OrderRepository;
use App\Service\Security\OrderVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/objednavky/{id}/zrusit', name: 'portal_user_order_cancel', methods: ['POST'])]
#[IsGranted('ROLE_USER')]
final class OrderCancelController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $id): Response
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $order = $this->orderRepository->find(Uuid::fromString($id));

        if (null === $order) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $this->denyAccessUnlessGranted(OrderVoter::CANCEL, $order);

        if (!$order->canBeCancelled()) {
            $this->addFlash('error', $order->cancellationBlockedReason() ?? 'Objednávku nelze zrušit.');

            return $this->redirectToRoute('portal_user_order_detail', ['id' => $id]);
        }

        $this->commandBus->dispatch(new CancelOrderCommand($order));

        $this->addFlash('success', 'Objednávka byla úspěšně zrušena.');

        return $this->redirectToRoute('portal_user_order_detail', ['id' => $id]);
    }
}
