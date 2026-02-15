<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\OrderRepository;
use App\Service\GoPay\GoPayClient;
use App\Service\OrderService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class ProcessPaymentNotificationHandler
{
    public function __construct(
        private GoPayClient $goPayClient,
        private OrderRepository $orderRepository,
        private OrderService $orderService,
        private MessageBusInterface $commandBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ProcessPaymentNotificationCommand $command): void
    {
        $status = $this->goPayClient->getStatus($command->goPayPaymentId);
        $now = $this->clock->now();

        // Find order by GoPay payment ID
        $order = $this->orderRepository->findByGoPayPaymentId($command->goPayPaymentId);

        if (null === $order) {
            // Could be a recurring charge notification - handled elsewhere
            return;
        }

        if ($status->isPaid() && $order->canBePaid()) {
            // Store parent payment ID for recurring if applicable (same as payment ID for initial payment)
            if ($order->isUnlimited()) {
                $order->setGoPayParentPaymentId($status->id);
            }

            $this->orderService->confirmPayment($order, $now);

            // Auto-complete the order (terms were already accepted before payment)
            if ($order->hasAcceptedTerms()) {
                $this->commandBus->dispatch(new CompleteOrderCommand($order));
            }
        } elseif ($status->isCanceled() && $order->canBeCancelled()) {
            $this->orderService->cancelOrder($order, $now);
        }
    }
}
