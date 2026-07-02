<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Contract;
use App\Entity\ManualPaymentRequest;
use App\Event\ManualBillingPaymentOverdue;
use App\Event\ManualBillingPaymentRequested;
use App\Repository\ContractRepository;
use App\Repository\ManualPaymentRequestRepository;
use App\Service\AuditLogger;
use App\Service\Billing\ManualBillingReminderSchedule;
use App\Service\Billing\RecurringAmountCalculator;
use App\Service\Identity\ProvideIdentity;
use App\Service\Payment\VariableSymbolGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Per-stage idempotent dispatcher for MANUAL_RECURRING reminders. Runs inside
 * the command-bus transaction; the `doctrine_transaction` middleware commits
 * the lock + sentStages update + audit row atomically. A repeated dispatch
 * for the same (contract, periodStart, stage) triple is a guaranteed no-op:
 *
 *   - schema unique constraint on (contract_id, period_start)
 *   - $request->sentStages[$stage] gate inside the locked row
 *   - SELECT ... FOR UPDATE serialises parallel cron processes
 *
 * Email side-effects fan out via the event bus AFTER this transaction
 * commits (DomainEventsMiddleware semantics), so an SMTP failure does not
 * roll back sentStages — we deliberately prefer "miss one reminder" over
 * "double-send".
 *
 * Spec 076: every manual cycle is paid by bank transfer (QR + variable
 * symbol) — cards are recurring-only, so the former per-cycle one-shot GoPay
 * link no longer exists.
 */
#[AsMessageHandler]
final readonly class DispatchManualBillingNotificationHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private ManualPaymentRequestRepository $manualPaymentRequestRepository,
        private RecurringAmountCalculator $amountCalculator,
        private VariableSymbolGenerator $variableSymbolGenerator,
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $eventBus,
        private AuditLogger $auditLogger,
        private ClockInterface $clock,
        private ProvideIdentity $identityProvider,
    ) {
    }

    public function __invoke(DispatchManualBillingNotificationCommand $command): void
    {
        $now = $this->clock->now();
        $contract = $this->contractRepository->get($command->contractId);

        $request = $this->manualPaymentRequestRepository->lockForUpdate($contract, $command->periodStart);

        if (null === $request) {
            $amount = $this->amountCalculator->calculate($contract, $now);
            $request = new ManualPaymentRequest(
                id: $this->identityProvider->next(),
                contract: $contract,
                periodStart: $command->periodStart->setTime(0, 0, 0),
                periodEnd: $this->computePeriodEnd($contract, $command->periodStart),
                amount: $amount,
                createdAt: $now,
            );
            $this->manualPaymentRequestRepository->save($request);
        }

        if ($request->hasStageSent($command->stage)) {
            return;
        }

        if ($request->isPaid()) {
            return;
        }

        // Belt-and-braces after the spec-076 data migration: every manual cycle
        // is paid by bank transfer, so the reminder e-mail must always be able
        // to render a variable symbol.
        if (null === $contract->order->variableSymbol) {
            $contract->order->assignVariableSymbol($this->variableSymbolGenerator->generate($contract->order->id));
        }

        $request->recordStageSent($command->stage, $now);

        $this->auditLogger->log(
            entityType: 'manual_payment_request',
            entityId: $request->id->toRfc4122(),
            eventType: 'bank_transfer_reminder_sent',
            payload: [
                'contract_id' => $contract->id->toRfc4122(),
                'period_start' => $request->periodStart->format('Y-m-d'),
                'stage' => $command->stage,
                'amount' => $request->amount,
                'variable_symbol' => $contract->order->variableSymbol,
            ],
            orderId: $contract->order->id,
            userIdContext: $contract->user->id,
        );

        $isOverdueStage = in_array($command->stage, [
            ManualBillingReminderSchedule::STAGE_OVERDUE_FIRST,
            ManualBillingReminderSchedule::STAGE_OVERDUE_FINAL,
        ], true);

        if ($isOverdueStage) {
            $contract->recordFailedBillingAttempt($now);
        }

        $event = $isOverdueStage
            ? new ManualBillingPaymentOverdue(
                contractId: $contract->id,
                manualPaymentRequestId: $request->id,
                stage: $command->stage,
                occurredOn: $now,
            )
            : new ManualBillingPaymentRequested(
                contractId: $contract->id,
                manualPaymentRequestId: $request->id,
                stage: $command->stage,
                occurredOn: $now,
            );

        $this->eventBus->dispatch($event);
    }

    /**
     * Period end mirrors the date arithmetic used by AUTO recurring billing
     * ({@see ChargeRecurringPaymentHandler}): one cadence step from the
     * period start (+1 month or +1 year depending on `paymentFrequency`),
     * clamped to the contract's effective end date when the fixed term runs
     * out earlier.
     */
    private function computePeriodEnd(Contract $contract, \DateTimeImmutable $periodStart): \DateTimeImmutable
    {
        $nextFullPeriodEnd = $periodStart->modify($contract->getBillingCadenceStep());
        $effectiveEndDate = $contract->getEffectiveEndDate();

        if ($nextFullPeriodEnd > $effectiveEndDate) {
            return $effectiveEndDate;
        }

        return $nextFullPeriodEnd;
    }
}
