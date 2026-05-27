<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Contract;
use App\Entity\ManualPaymentRequest;
use App\Enum\PaymentMethod;
use App\Event\ManualBillingPaymentOverdue;
use App\Event\ManualBillingPaymentRequested;
use App\Repository\ContractRepository;
use App\Repository\ManualPaymentRequestRepository;
use App\Service\AuditLogger;
use App\Service\Billing\ManualBillingReminderSchedule;
use App\Service\Billing\RecurringAmountCalculator;
use App\Service\GoPay\GoPayClient;
use App\Service\GoPay\GoPayException;
use App\Service\Identity\ProvideIdentity;
use App\Service\OrderStatusUrlGenerator;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
 */
#[AsMessageHandler]
final readonly class DispatchManualBillingNotificationHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private ManualPaymentRequestRepository $manualPaymentRequestRepository,
        private GoPayClient $goPayClient,
        private RecurringAmountCalculator $amountCalculator,
        private OrderStatusUrlGenerator $statusUrlGenerator,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $eventBus,
        private AuditLogger $auditLogger,
        private ClockInterface $clock,
        private ProvideIdentity $identityProvider,
        private LoggerInterface $logger,
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

        $isBankTransfer = PaymentMethod::BANK_TRANSFER === $contract->order->paymentMethod;

        if ($isBankTransfer) {
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

            return;
        }

        if (null === $request->goPayPaymentId || $this->isGoPayPaymentTerminal($request->goPayPaymentId)) {
            try {
                $payment = $this->goPayClient->createOneTimeCharge(
                    amount: $request->amount,
                    orderNumber: sprintf('MNL-%s-%s', $contract->id->toRfc4122(), $request->periodStart->format('Ymd')),
                    orderDescription: sprintf(
                        'Pronájem skladu %s - %s (%s)',
                        $contract->storage->number,
                        $contract->storage->storageType->name,
                        $request->periodStart->format('m/Y'),
                    ),
                    payerEmail: $contract->user->email,
                    returnUrl: $this->statusUrlGenerator->generate($contract->order),
                    notificationUrl: $this->urlGenerator->generate(
                        'public_payment_notification',
                        [],
                        UrlGeneratorInterface::ABSOLUTE_URL,
                    ),
                );
                $request->attachGoPayPayment($payment->id, $payment->gwUrl);
            } catch (GoPayException $e) {
                $this->logger->error('Failed to create GoPay payment for manual billing reminder', [
                    'contract_id' => $contract->id->toRfc4122(),
                    'period_start' => $request->periodStart->format('Y-m-d'),
                    'stage' => $command->stage,
                    'exception' => $e,
                ]);

                throw $e;
            }
        }

        $request->recordStageSent($command->stage, $now);

        $this->auditLogger->logManualPaymentRequested($request, $command->stage);

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

        if (null !== $effectiveEndDate && $nextFullPeriodEnd > $effectiveEndDate) {
            return $effectiveEndDate;
        }

        return $nextFullPeriodEnd;
    }

    /**
     * GoPay reports a CANCELED or TIMEOUTED payment as terminal; for those we
     * must create a fresh payment for the next reminder. PAID would have
     * already routed via the webhook reconcileManualPayment branch and
     * flipped status to 'paid', short-circuiting this handler earlier.
     */
    private function isGoPayPaymentTerminal(string $paymentId): bool
    {
        try {
            $status = $this->goPayClient->getStatus($paymentId);
        } catch (GoPayException $e) {
            $this->logger->warning('Failed to check GoPay payment status; will create a new payment', [
                'gopay_payment_id' => $paymentId,
                'exception' => $e,
            ]);

            return true;
        }

        return in_array($status->state, ['CANCELED', 'TIMEOUTED'], true);
    }
}
