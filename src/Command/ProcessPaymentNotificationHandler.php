<?php

declare(strict_types=1);

namespace App\Command;

use App\Event\RecurringPaymentCharged;
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

        // Not an order payment — check if already processed (idempotency)
        $existingPayment = $this->paymentRepository->findByGoPayPaymentId($command->goPayPaymentId);
        if (null !== $existingPayment) {
            return;
        }

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

        $amount = $status->amount ?? $contract->storage->getEffectivePricePerMonth();

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
