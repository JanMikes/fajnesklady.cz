<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Payment;
use App\Repository\BankTransactionRepository;
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
        private BankTransactionRepository $bankTransactionRepository,
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
            amount: $event->amountOverride ?? $order->firstPaymentPrice,
            paidAt: $event->occurredOn,
            createdAt: $event->occurredOn,
        );

        // Record where the money came from — without it the payment overview
        // can only guess, and it used to label every card-paid first payment
        // "Bankovní převod".
        if (null !== $event->goPayPaymentId) {
            $payment->setGoPayPaymentId($event->goPayPaymentId);
        }

        if (null !== $event->bankTransactionId) {
            $bankTransaction = $this->bankTransactionRepository->find($event->bankTransactionId);
            if (null !== $bankTransaction) {
                $payment->linkBankTransaction($bankTransaction);
            }
        }

        $this->paymentRepository->save($payment);
    }
}
