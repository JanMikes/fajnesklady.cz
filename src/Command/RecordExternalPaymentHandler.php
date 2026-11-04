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
use App\Service\OrderService;
use Psr\Clock\ClockInterface;
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
        private OrderService $orderService,
        private MessageBusInterface $commandBus,
        private AuditLogger $auditLogger,
        private ClockInterface $clock,
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
            $paidThroughDate = $contract->advanceByCadence($originalNextBillingDate)
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

        // No invoice: money recorded off-system has no payment evidence in
        // the platform, and we never issue a tax document without one.

        $this->auditLogger->log(
            entityType: 'contract',
            entityId: $contract->id->toRfc4122(),
            eventType: 'external_payment_recorded',
            payload: [
                'paid_through' => $paidThroughDate->format('Y-m-d'),
                'amount' => $command->amount,
                'whole_cycle' => $command->wholeCycle,
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

        // Completion skips the invoice for EXTERNAL orders: no payment
        // evidence, no tax document.

        $this->auditLogger->log(
            entityType: 'order',
            entityId: $order->id->toRfc4122(),
            eventType: 'external_first_payment_recorded',
            payload: [
                'amount' => $command->amount,
                'paid_through' => $command->paidThroughDate?->format('Y-m-d'),
            ],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );
    }
}
