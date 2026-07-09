<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Payment;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Repository\ContractRepository;
use App\Repository\ManualPaymentRequestRepository;
use App\Repository\PaymentRepository;
use App\Service\AuditLogger;
use App\Service\Identity\ProvideIdentity;
use App\Service\InvoicingService;
use App\Service\OrderService;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class RecordExternalPaymentHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private ManualPaymentRequestRepository $manualPaymentRequestRepository,
        private PaymentRepository $paymentRepository,
        private ProvideIdentity $identityProvider,
        private InvoicingService $invoicingService,
        private OrderService $orderService,
        private MessageBusInterface $commandBus,
        private AuditLogger $auditLogger,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecordExternalPaymentCommand $command): void
    {
        $order = $command->order;
        $now = $this->clock->now();
        $contract = $this->contractRepository->findByOrder($order);

        if (null !== $contract) {
            $this->recordRunningCyclePayment($command, $contract, $now);

            return;
        }

        $this->recordFirstPayment($command, $order, $now);
    }

    private function recordRunningCyclePayment(
        RecordExternalPaymentCommand $command,
        Contract $contract,
        \DateTimeImmutable $now,
    ): void {
        $order = $contract->order;
        // Capture before recordExternalPayment() advances the anchor — the
        // pending request is keyed on the ORIGINAL cycle start.
        $originalNextBillingDate = $contract->nextBillingDate;

        if ($command->wholeCycle) {
            if (null === $originalNextBillingDate) {
                throw new \DomainException('Contract has no billing cycle to advance.');
            }
            // The current cycle is covered through the day before the next one.
            $paidThroughDate = $originalNextBillingDate
                ->modify($contract->getBillingCadenceStep())
                ->modify('-1 day');
        } else {
            $paidThroughDate = $command->paidThroughDate;
            if (null === $paidThroughDate) {
                throw new \InvalidArgumentException('paidThroughDate is required for a specific-date external payment.');
            }
        }

        $contract->recordExternalPayment($paidThroughDate, $now);

        if (null !== $originalNextBillingDate) {
            $request = $this->manualPaymentRequestRepository->findUnpaidByContractAndPeriod(
                $contract,
                $originalNextBillingDate,
            );
            $request?->markPaid($now);
        }

        $this->paymentRepository->save(new Payment(
            id: $this->identityProvider->next(),
            order: null,
            contract: $contract,
            storage: $contract->storage,
            amount: $command->amount,
            paidAt: $now,
            createdAt: $now,
        ));

        if ($command->issueInvoice) {
            try {
                $this->invoicingService->issueInvoiceForRecurringPayment($contract, $command->amount, $now);
            } catch (\Throwable $e) {
                // Best-effort: a Fakturoid outage must not roll back the
                // recorded payment. The admin can re-issue in Fakturoid.
                $this->logger->error('External-payment invoice failed', [
                    'contract_id' => $contract->id->toRfc4122(),
                    'exception' => $e,
                ]);
            }
        }

        $this->auditLogger->log(
            entityType: 'contract',
            entityId: $contract->id->toRfc4122(),
            eventType: 'external_payment_recorded',
            payload: [
                'paid_through' => $paidThroughDate->format('Y-m-d'),
                'amount' => $command->amount,
                'whole_cycle' => $command->wholeCycle,
                'invoice_issued' => $command->issueInvoice,
            ],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );
    }

    private function recordFirstPayment(
        RecordExternalPaymentCommand $command,
        Order $order,
        \DateTimeImmutable $now,
    ): void {
        // The money arrived off-system, so the rental now bills on the manual
        // (bank-transfer request) track — never a card token. Deriving the mode
        // (not just the method) avoids the spec-085 "external + auto_recurring"
        // orphan that no cron would ever bill.
        $frequency = $order->paymentFrequency ?? PaymentFrequency::MONTHLY;
        $rentalDays = null !== $order->endDate ? (int) $order->startDate->diff($order->endDate)->days : 0;
        $order->setPaymentMethod(PaymentMethod::EXTERNAL);
        $order->setBillingMode(BillingMode::derive(PaymentMethod::EXTERNAL, $frequency, $rentalDays));

        // A specific paid-through date is carried onto the contract as external
        // prepayment by OrderService::completeOrder().
        if (!$command->wholeCycle && null !== $command->paidThroughDate) {
            $order->setOnboardingBillingTerms($order->individualMonthlyAmount, $command->paidThroughDate);
        }

        $this->orderService->confirmPayment($order, $now, $command->amount);

        if ($order->hasAcceptedTerms()) {
            $this->commandBus->dispatch(new CompleteOrderCommand($order));
        }

        // Completion skips the invoice for EXTERNAL orders (no real money moved
        // through the system); issue it explicitly only when the admin asked.
        if ($command->issueInvoice) {
            $this->commandBus->dispatch(new IssueInvoiceForOrderCommand($order));
        }

        $this->auditLogger->log(
            entityType: 'order',
            entityId: $order->id->toRfc4122(),
            eventType: 'external_first_payment_recorded',
            payload: [
                'amount' => $command->amount,
                'paid_through' => $command->paidThroughDate?->format('Y-m-d'),
                'invoice_issued' => $command->issueInvoice,
            ],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );
    }
}
