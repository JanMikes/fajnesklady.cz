<?php

declare(strict_types=1);

namespace App\Command;

use App\Event\RecurringPaymentCharged;
use App\Event\RecurringPaymentEstablished;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Repository\PaymentRepository;
use App\Service\GoPay\GoPayClient;
use App\Service\OrderService;
use App\Service\PriceCalculator;
use App\Value\GoPayPaymentStatus;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class ProcessPaymentNotificationHandler
{
    public function __construct(
        private GoPayClient $goPayClient,
        private OrderRepository $orderRepository,
        private PaymentRepository $paymentRepository,
        private ContractRepository $contractRepository,
        private OrderService $orderService,
        private PriceCalculator $priceCalculator,
        private MessageBusInterface $commandBus,
        private MessageBusInterface $eventBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessPaymentNotificationCommand $command): void
    {
        // Idempotency guard: GoPay can re-deliver the same webhook (timeouts,
        // retries, parallel deliveries). Once a Payment row exists for this
        // GoPay payment ID we have already finalised processing — bail out
        // before mutating contract billing dates a second time. The recurring
        // path is the load-bearing case (Payment.goPayPaymentId is populated
        // by RecordPaymentOnRecurringChargeHandler); order-path duplicates
        // remain protected by Order::canBePaid() further down.
        if ($this->paymentRepository->existsByGoPayPaymentId($command->goPayPaymentId)) {
            $this->logger->info('Skipping duplicate GoPay notification', [
                'gopay_payment_id' => $command->goPayPaymentId,
            ]);

            return;
        }

        $status = $this->goPayClient->getStatus($command->goPayPaymentId);
        $now = $this->clock->now();

        // Find order by GoPay payment ID
        $order = $this->orderRepository->findByGoPayPaymentId($command->goPayPaymentId);

        if (null !== $order) {
            if ($status->isPaid() && $order->canBePaid()) {
                // Store parent payment ID for recurring (all orders >= 1 month)
                $needsRecurring = $this->priceCalculator->needsRecurringBilling($order->startDate, $order->endDate);
                if ($needsRecurring) {
                    $order->setGoPayParentPaymentId($status->id);

                    // Confirmation e-mail required by Podmínky opakovaných plateb čl. IV
                    // (within 2 working days of consent / first successful charge).
                    $this->eventBus->dispatch(new RecurringPaymentEstablished(
                        orderId: $order->id,
                        goPayParentPaymentId: $status->id,
                        amount: $order->firstPaymentPrice,
                        occurredOn: $now,
                    ));
                }

                $this->orderService->confirmPayment($order, $now);

                // Auto-complete the order (terms were already accepted before payment)
                if ($order->hasAcceptedTerms()) {
                    $this->commandBus->dispatch(new CompleteOrderCommand($order));
                }
            } elseif ($status->isCanceled() && !$order->status->isTerminal()) {
                $order->cancel($now);
            }

            return;
        }

        // Not an order payment. The duplicate check at the top of __invoke
        // already short-circuited if we have a Payment for this GoPay ID;
        // anything that reaches here is a first-time notification.
        // Could be a recurring charge notification — reconcile via parent payment ID
        if (null !== $status->parentId && '' !== $status->parentId && $status->isPaid()) {
            $this->reconcileRecurringPayment($status->parentId, $status, $now);

            return;
        }

        $this->logger->info('GoPay notification for unknown payment ID', [
            'gopay_payment_id' => $command->goPayPaymentId,
            'state' => $status->state,
            'parent_id' => $status->parentId,
        ]);
    }

    /**
     * Handle a GoPay webhook for a recurring payment that wasn't confirmed synchronously.
     * This is the safety net for when polling in ChargeRecurringPaymentHandler times out.
     */
    private function reconcileRecurringPayment(string $parentPaymentId, GoPayPaymentStatus $status, \DateTimeImmutable $now): void
    {
        $contract = $this->contractRepository->findByGoPayParentPaymentId($parentPaymentId);

        if (null === $contract) {
            $this->logger->info('GoPay recurring notification for unknown parent payment', [
                'gopay_payment_id' => $status->id,
                'parent_id' => $parentPaymentId,
            ]);

            return;
        }

        // Calculate billing dates (same logic as ChargeRecurringPaymentHandler)
        $billingPeriodStart = $contract->nextBillingDate ?? $now;
        $effectiveEndDate = $contract->getEffectiveEndDate();
        $nextBillingDate = $billingPeriodStart->modify('+1 month');
        $paidThroughDate = $nextBillingDate;

        if (null !== $effectiveEndDate && $nextBillingDate >= $effectiveEndDate) {
            $nextBillingDate = null;
            $paidThroughDate = $effectiveEndDate;
        }

        $contract->recordBillingCharge($now, $nextBillingDate, $paidThroughDate);

        $amount = $status->amount ?? $contract->getEffectiveMonthlyAmount();

        $this->eventBus->dispatch(new RecurringPaymentCharged(
            contractId: $contract->id,
            paymentId: $status->id,
            amount: $amount,
            occurredOn: $now,
        ));

        $this->logger->info('Recurring payment reconciled via webhook', [
            'contract_id' => $contract->id->toRfc4122(),
            'gopay_payment_id' => $status->id,
            'amount' => $amount,
        ]);
    }
}
