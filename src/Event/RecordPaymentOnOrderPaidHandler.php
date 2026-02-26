<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Payment;
use App\Repository\OrderRepository;
use App\Repository\PaymentRepository;
use App\Service\Identity\ProvideIdentity;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RecordPaymentOnOrderPaidHandler
{
    public function __construct(
        private OrderRepository $orderRepository,
        private PaymentRepository $paymentRepository,
        private ProvideIdentity $identityProvider,
    ) {
    }

    public function __invoke(OrderPaid $event): void
    {
        $order = $this->orderRepository->get($event->orderId);

        if (null !== $this->paymentRepository->findByOrder($order)) {
            return;
        }

        $payment = new Payment(
            id: $this->identityProvider->next(),
            order: $order,
            contract: null,
            storage: $order->storage,
            amount: $order->totalPrice,
            paidAt: $event->occurredOn,
            createdAt: $event->occurredOn,
        );

        $this->paymentRepository->save($payment);
    }
}
