<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\ProcessPaymentNotificationCommand;
use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/objednavka/{id}/platba/navrat', name: 'public_payment_return')]
final class PaymentReturnController extends AbstractController
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

        // Process payment status from GoPay
        if (null !== $order->goPayPaymentId) {
            try {
                $this->commandBus->dispatch(new ProcessPaymentNotificationCommand(
                    goPayPaymentId: $order->goPayPaymentId,
                ));
            } catch (\Exception) {
                // Ignore errors - status will be checked below
            }

            // Refresh order from database
            $order = $this->orderRepository->get($order->id);
        }

        // Redirect based on status
        if (OrderStatus::COMPLETED === $order->status) {
            $this->addFlash('success', 'Platba byla přijata a objednávka dokončena.');

            return $this->redirectToRoute('public_order_complete', ['id' => $id]);
        }

        if (OrderStatus::PAID === $order->status) {
            // Payment confirmed but not auto-completed (shouldn't happen in normal flow)
            $this->addFlash('success', 'Platba byla úspěšně přijata.');

            return $this->redirectToRoute('public_order_complete', ['id' => $id]);
        }

        if ($order->status->isTerminal()) {
            $this->addFlash('error', 'Platba byla zrušena.');

            return $this->redirectToRoute('app_home');
        }

        // Still pending - redirect back to payment page
        $this->addFlash('info', 'Platba nebyla dokončena. Zkuste to znovu.');

        return $this->redirectToRoute('public_order_payment', ['id' => $id]);
    }
}
