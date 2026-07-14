<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Contract;
use App\Entity\ManualPaymentRequest;
use App\Entity\Order;
use App\Enum\BillingMode;
use App\Enum\PaymentMethod;
use App\Event\PaymentAmountMismatch;
use App\Event\RecurringPaymentCharged;
use App\Event\RecurringPaymentEstablished;
use App\Repository\ContractRepository;
use App\Repository\FineRepository;
use App\Repository\ManualPaymentRequestRepository;
use App\Repository\OrderRepository;
use App\Repository\PaymentRepository;
use App\Service\AuditLogger;
use App\Service\Billing\RecurringAmountCalculator;
use App\Service\GoPay\GoPayClient;
use App\Service\Onboarding\DebtPaymentService;
use App\Service\OrderService;
use App\Value\GoPayPaymentStatus;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class ProcessPaymentNotificationHandler
{
    public function __construct(
        private GoPayClient $goPayClient,
        private OrderRepository $orderRepository,
        private PaymentRepository $paymentRepository,
        private ContractRepository $contractRepository,
        private ManualPaymentRequestRepository $manualPaymentRequestRepository,
        private FineRepository $fineRepository,
        private OrderService $orderService,
        private DebtPaymentService $debtPaymentService,
        private RecurringAmountCalculator $amountCalculator,
        private AuditLogger $auditLogger,
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

        // SELECT … FOR UPDATE serialises concurrent webhook deliveries on the
        // order row. The Payment.goPayPaymentId existence check above only
        // covers the recurring path (first-payment Payments are inserted
        // without a GoPay ID), so first-payment duplicates would otherwise
        // both pass canBePaid(), both flip to PAID, and both dispatch
        // CompleteOrderCommand — the loser tripping the contract.order_id
        // unique constraint. With the lock, the second delivery blocks until
        // the first commits and then sees PAID, falling out of canBePaid().
        $order = $this->orderRepository->findByGoPayPaymentIdForUpdate($command->goPayPaymentId);

        if (null !== $order) {
            if ($status->isPaid() && $order->canBePaid()) {
                $this->detectOrderAmountMismatch($order, $status, $now);

                // AUTO_RECURRING captures the parent payment ID (the token used
                // for subsequent silent charges) and dispatches the čl. IV
                // confirmation e-mail. ONE_TIME and MANUAL_RECURRING skip this
                // — for MANUAL there is no token and no silent debit to
                // disclose; each cycle is approved explicitly via e-mail link.
                if (BillingMode::AUTO_RECURRING === $order->billingMode) {
                    $order->setGoPayParentPaymentId($status->id);

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
            } elseif ($status->isCanceled()) {
                // A dead payment SESSION must never kill the ORDER. GoPay
                // sessions die after ~1 hour (TIMEOUTED) or when the customer
                // clicks away on the gateway (CANCELED); the order has its own
                // operator-configured lifetime (expiresAt, the expiry cron) and
                // PaymentInitiateController creates a fresh GoPay payment on
                // every pay attempt. Cancelling here killed a real customer's
                // order 1h after they abandoned the gateway (2026-07-14,
                // order 019f5cdd-d83c-7e75-85ef-cb83938429f8).
                if ('REFUNDED' === $status->state) {
                    // Refunds originate only from manual GoPay-console actions.
                    // Never mutate order state automatically — surface loudly
                    // and let the admin reconcile.
                    $this->logger->error('GoPay reports order first payment REFUNDED — manual reconciliation needed', [
                        'order_id' => $order->id->toRfc4122(),
                        'order_status' => $order->status->value,
                        'gopay_payment_id' => $command->goPayPaymentId,
                    ]);
                    $this->auditLogger->logOrderPaymentRefunded($order, $command->goPayPaymentId);
                } elseif ($order->canBePaid()) {
                    $order->clearGoPayPaymentId();
                    $this->auditLogger->logOrderPaymentSessionExpired($order, $status->state, $command->goPayPaymentId);
                    $this->logger->info('GoPay payment session died; order stays payable', [
                        'order_id' => $order->id->toRfc4122(),
                        'gopay_payment_id' => $command->goPayPaymentId,
                        'gopay_state' => $status->state,
                    ]);
                }
            }

            return;
        }

        // Debt payment: the GoPay payment ID was stored on Order.debtGoPayPaymentId
        $debtOrder = $this->orderRepository->findByDebtGoPayPaymentIdForUpdate($command->goPayPaymentId);
        if (null !== $debtOrder) {
            if ($status->isPaid() && $debtOrder->hasUnpaidDebt()) {
                $this->debtPaymentService->confirmDebtPaid($debtOrder, $now, $command->goPayPaymentId);
            }

            return;
        }

        // Not an order payment. The duplicate check at the top of __invoke
        // already short-circuited if we have a Payment for this GoPay ID;
        // anything that reaches here is a first-time notification.

        // MANUAL_RECURRING: webhook arrived for a per-cycle one-time payment
        // we previously generated for a Contract. Reconcile via the
        // ManualPaymentRequest row so the contract's billing dates advance
        // and the invoice + Payment row issue exactly as for AUTO.
        $manualRequest = $this->manualPaymentRequestRepository->findByGoPayPaymentId($command->goPayPaymentId);
        if (null !== $manualRequest && $status->isPaid()) {
            $this->reconcileManualPayment($manualRequest, $status, $now);

            return;
        }

        // Fine payment: GoPay payment ID was stored on Fine.goPayPaymentId
        $fine = $this->fineRepository->findByGoPayPaymentId($command->goPayPaymentId);
        if (null !== $fine) {
            if ($status->isPaid() && $fine->isPayable()) {
                $fine->markPaid($now);

                $this->auditLogger->log(
                    entityType: 'fine',
                    entityId: $fine->id->toRfc4122(),
                    eventType: 'paid',
                    payload: [
                        'payment_method' => 'gopay',
                        'gopay_payment_id' => $command->goPayPaymentId,
                    ],
                    orderId: $fine->contract->order->id,
                    userIdContext: $fine->user->id,
                );
            }

            return;
        }

        // Prolongation bank→card switch (spec 077): the setup charge carries
        // the ON_DEMAND recurrence — once PAID, the contract flips to
        // AUTO_RECURRING with this payment as its parent token and the charge
        // covers the next cycle.
        $cardSetupContract = $this->contractRepository->findByPendingCardSetupPaymentIdForUpdate($command->goPayPaymentId);
        if (null !== $cardSetupContract) {
            if ($status->isPaid() && $cardSetupContract->isTerminated()) {
                // The contract died between initiation and payment — do NOT
                // resurrect it as AUTO_RECURRING. The customer's money needs a
                // manual refund; alert loudly instead of swallowing it.
                $cardSetupContract->clearCardSetup();
                $this->logger->error('Card setup charge PAID for a terminated contract — manual refund needed', [
                    'contract_id' => $cardSetupContract->id->toRfc4122(),
                    'gopay_payment_id' => $status->id,
                    'amount' => $status->amount,
                ]);
            } elseif ($status->isPaid()) {
                $this->completeCardSetup($cardSetupContract, $status, $now);
            } elseif ($status->isCanceled()) {
                // Abandoned/failed setup — the contract simply stays on the
                // manual bank track; nothing was charged.
                $cardSetupContract->clearCardSetup();
            }

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
        $nextBillingDate = $billingPeriodStart->modify($contract->getBillingCadenceStep());
        $paidThroughDate = $nextBillingDate;

        // Last cycle → stop future charges; contracts hard-stop at end (spec 076).
        if ($nextBillingDate >= $effectiveEndDate) {
            $nextBillingDate = null;
            $paidThroughDate = $effectiveEndDate;
        }

        // GoPay is the source of truth for what was actually charged. If it
        // disagrees with what we computed, RECORD WHAT GOPAY SAYS but emit
        // a mismatch event so admin can investigate (the last cycle of a
        // fixed-term contract is legitimately prorated; everything else
        // likely needs reconciliation with the customer).
        $expectedAmount = $this->amountCalculator->calculate($contract, $now);
        $receivedAmount = $status->amount ?? $expectedAmount;

        if (null !== $status->amount && $status->amount !== $expectedAmount) {
            $this->logger->warning('GoPay recurring amount differs from expected — admin alert dispatched', [
                'contract_id' => $contract->id->toRfc4122(),
                'gopay_payment_id' => $status->id,
                'expected_amount' => $expectedAmount,
                'received_amount' => $status->amount,
            ]);

            $this->eventBus->dispatch(PaymentAmountMismatch::forContract(
                contractId: $contract->id,
                goPayPaymentId: $status->id,
                expectedAmount: $expectedAmount,
                receivedAmount: $status->amount,
                occurredOn: $now,
            ));
        }

        $contract->recordBillingCharge($now, $nextBillingDate, $paidThroughDate);

        // Persist the Payment row via RecurringPaymentCharged. The unique
        // partial index on payment.go_pay_payment_id is the hard backstop
        // against parallel-webhook races: if two simultaneous deliveries
        // both pass the existsByGoPayPaymentId check at the top of __invoke,
        // the second flush attempt will violate the constraint. That is the
        // expected outcome — log a warning and return cleanly so the messenger
        // does not surface a duplicate as an error.
        try {
            $this->eventBus->dispatch(new RecurringPaymentCharged(
                contractId: $contract->id,
                paymentId: $status->id,
                amount: $receivedAmount,
                occurredOn: $now,
            ));
        } catch (HandlerFailedException $e) {
            if ($this->isUniqueViolation($e)) {
                $this->logger->warning('Duplicate webhook lost the race for recurring Payment insert', [
                    'gopay_payment_id' => $status->id,
                    'contract_id' => $contract->id->toRfc4122(),
                ]);

                return;
            }

            throw $e;
        }

        $this->logger->info('Recurring payment reconciled via webhook', [
            'contract_id' => $contract->id->toRfc4122(),
            'gopay_payment_id' => $status->id,
            'amount' => $receivedAmount,
        ]);
    }

    /**
     * Reconcile a paid one-time GoPay payment that we generated for a
     * MANUAL_RECURRING cycle. Mirrors {@see self::reconcileRecurringPayment()}
     * for the AUTO branch: same billing-date advance, same RecurringPaymentCharged
     * fan-out (invoice + Payment row), same amount-mismatch detection.
     */
    private function reconcileManualPayment(ManualPaymentRequest $manualRequest, GoPayPaymentStatus $status, \DateTimeImmutable $now): void
    {
        $contract = $manualRequest->contract;

        $billingPeriodStart = $contract->nextBillingDate ?? $now;
        $effectiveEndDate = $contract->getEffectiveEndDate();
        $nextBillingDate = $billingPeriodStart->modify($contract->getBillingCadenceStep());
        $paidThroughDate = $nextBillingDate;

        // Last cycle → stop future charges; contracts hard-stop at end (spec 076).
        if ($nextBillingDate >= $effectiveEndDate) {
            $nextBillingDate = null;
            $paidThroughDate = $effectiveEndDate;
        }

        $expectedAmount = $this->amountCalculator->calculate($contract, $now);
        $receivedAmount = $status->amount ?? $expectedAmount;

        if (null !== $status->amount && $status->amount !== $expectedAmount) {
            $this->logger->warning('GoPay manual-billing amount differs from expected — admin alert dispatched', [
                'contract_id' => $contract->id->toRfc4122(),
                'gopay_payment_id' => $status->id,
                'expected_amount' => $expectedAmount,
                'received_amount' => $status->amount,
            ]);

            $this->eventBus->dispatch(PaymentAmountMismatch::forContract(
                contractId: $contract->id,
                goPayPaymentId: $status->id,
                expectedAmount: $expectedAmount,
                receivedAmount: $status->amount,
                occurredOn: $now,
            ));
        }

        $contract->recordBillingCharge($now, $nextBillingDate, $paidThroughDate);
        $manualRequest->markPaid($now);
        $this->auditLogger->logManualPaymentReceived($manualRequest);

        try {
            $this->eventBus->dispatch(new RecurringPaymentCharged(
                contractId: $contract->id,
                paymentId: $status->id,
                amount: $receivedAmount,
                occurredOn: $now,
            ));
        } catch (HandlerFailedException $e) {
            if ($this->isUniqueViolation($e)) {
                $this->logger->warning('Duplicate webhook lost the race for manual-billing Payment insert', [
                    'gopay_payment_id' => $status->id,
                    'contract_id' => $contract->id->toRfc4122(),
                ]);

                return;
            }

            throw $e;
        }

        $this->logger->info('Manual-billing payment reconciled via webhook', [
            'contract_id' => $contract->id->toRfc4122(),
            'gopay_payment_id' => $status->id,
            'amount' => $receivedAmount,
        ]);
    }

    /**
     * Complete the prolongation bank→card switch (spec 077): the PAID setup
     * charge becomes the contract's ON_DEMAND parent token AND pays the next
     * cycle. Mirrors {@see self::reconcileManualPayment()}'s billing math and
     * fan-out (invoice + Payment row), plus the čl. IV establishment e-mail.
     */
    private function completeCardSetup(Contract $contract, GoPayPaymentStatus $status, \DateTimeImmutable $now): void
    {
        $billingPeriodStart = $contract->nextBillingDate ?? $now;
        $effectiveEndDate = $contract->getEffectiveEndDate();
        $nextBillingDate = $billingPeriodStart->modify($contract->getBillingCadenceStep());
        $paidThroughDate = $nextBillingDate;

        if ($nextBillingDate >= $effectiveEndDate) {
            $nextBillingDate = null;
            $paidThroughDate = $effectiveEndDate;
        }

        $contract->completeCardSetup($status->id, $nextBillingDate, $paidThroughDate);
        $contract->recordBillingCharge($now, $nextBillingDate, $paidThroughDate);
        $contract->order->setPaymentMethod(PaymentMethod::GOPAY);

        // The setup charge settles the cycle any outstanding manual request
        // covers — mark it paid so the reminder cron stops chasing a bank
        // transfer for a period the card just paid (double-charge guard).
        $outstandingRequest = $this->manualPaymentRequestRepository->findPendingForCurrentCycle($contract, $now);
        if (null !== $outstandingRequest) {
            $outstandingRequest->markPaid($now);
        }

        $this->auditLogger->log(
            entityType: 'contract',
            entityId: $contract->id->toRfc4122(),
            eventType: 'recurring_established_on_prolong',
            payload: [
                'gopay_parent_payment_id' => $status->id,
                'amount' => $status->amount,
                'next_billing_date' => $nextBillingDate?->format('Y-m-d'),
                'paid_through_date' => $paidThroughDate->format('Y-m-d'),
            ],
            orderId: $contract->order->id,
            userIdContext: $contract->user->id,
        );

        // Podmínky čl. IV: confirmation e-mail within 2 working days of the
        // recurring-payment consent taking effect.
        $this->eventBus->dispatch(new RecurringPaymentEstablished(
            orderId: $contract->order->id,
            goPayParentPaymentId: $status->id,
            amount: $contract->getEffectiveRecurringAmount(),
            occurredOn: $now,
        ));

        try {
            $this->eventBus->dispatch(new RecurringPaymentCharged(
                contractId: $contract->id,
                paymentId: $status->id,
                amount: $status->amount ?? $contract->getEffectiveRecurringAmount(),
                occurredOn: $now,
            ));
        } catch (HandlerFailedException $e) {
            if ($this->isUniqueViolation($e)) {
                $this->logger->warning('Duplicate webhook lost the race for card-setup Payment insert', [
                    'gopay_payment_id' => $status->id,
                    'contract_id' => $contract->id->toRfc4122(),
                ]);

                return;
            }

            throw $e;
        }

        $this->logger->info('Prolongation card setup completed via webhook', [
            'contract_id' => $contract->id->toRfc4122(),
            'gopay_parent_payment_id' => $status->id,
        ]);
    }

    private function detectOrderAmountMismatch(Order $order, GoPayPaymentStatus $status, \DateTimeImmutable $now): void
    {
        if (null === $status->amount) {
            return;
        }

        if ($status->amount === $order->firstPaymentPrice) {
            return;
        }

        $this->logger->warning('GoPay order amount differs from expected — admin alert dispatched', [
            'order_id' => $order->id->toRfc4122(),
            'gopay_payment_id' => $status->id,
            'expected_amount' => $order->firstPaymentPrice,
            'received_amount' => $status->amount,
        ]);

        $this->eventBus->dispatch(PaymentAmountMismatch::forOrder(
            orderId: $order->id,
            goPayPaymentId: $status->id,
            expectedAmount: $order->firstPaymentPrice,
            receivedAmount: $status->amount,
            occurredOn: $now,
        ));
    }

    private function isUniqueViolation(\Throwable $e): bool
    {
        for ($cur = $e; null !== $cur; $cur = $cur->getPrevious()) {
            if ($cur instanceof UniqueConstraintViolationException) {
                return true;
            }
        }

        return false;
    }
}
