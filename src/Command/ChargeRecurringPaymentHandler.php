<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Contract;
use App\Event\RecurringPaymentCharged;
use App\Service\Billing\RecurringAmountCalculator;
use App\Service\GoPay\GoPayClient;
use App\Service\GoPay\PaymentNotConfirmedException;
use App\Value\GoPayPayment;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class ChargeRecurringPaymentHandler
{
    /**
     * Polling window for GoPay's async card processing. 15 × 2 s = 30 s,
     * which absorbs the vast majority of webhook delays observed in production.
     * If we still get a non-PAID state after the window, we record the GoPay
     * payment ID as in-flight and let the webhook (or the next cron run) close
     * the loop — see Contract::$pendingRecurringPaymentId.
     */
    private const int MAX_STATUS_POLLS = 15;
    private const int POLL_INTERVAL_MICROSECONDS = 2_000_000;

    public function __construct(
        private GoPayClient $goPayClient,
        private ClockInterface $clock,
        private MessageBusInterface $eventBus,
        private LoggerInterface $logger,
        private RecurringAmountCalculator $amountCalculator,
        private int $pollIntervalMicroseconds = self::POLL_INTERVAL_MICROSECONDS,
    ) {
    }

    public function __invoke(ChargeRecurringPaymentCommand $command): void
    {
        $contract = $command->contract;
        $now = $this->clock->now();

        // Defensive double-charge guard: if the contract was successfully billed
        // within the last 5 minutes, assume a near-simultaneous race between the
        // recurring cron and a manual admin charge (separate processes, separate
        // transactions) and bail out. The window is intentionally short and
        // non-configurable — long enough to absorb cron + manual collisions,
        // short enough not to swallow a legitimate retry hours later.
        if (null !== $contract->lastBilledAt
            && $contract->lastBilledAt <= $now
            && $contract->lastBilledAt >= $now->modify('-5 minutes')) {
            $this->logger->warning('Skipped recurring charge: contract already billed within last 5 minutes', [
                'contractId' => $contract->id,
                'lastBilledAt' => $contract->lastBilledAt,
            ]);

            return;
        }

        if (!$contract->hasActiveRecurringPayment()) {
            throw new \DomainException('Contract does not have active recurring payment.');
        }

        // Reconcile any in-flight charge from a previous run before issuing a
        // new one. This is the primary defense against double-charges when
        // GoPay's webhook is slow or never arrives. We always return after
        // reconciling — either the previous charge is now PAID (finalised),
        // still pending (try again next run), or it terminally failed (the
        // method throws so the cron records a failure and the retry cron
        // picks the contract up under the 3-day / 7-day ladder).
        if ($contract->hasPendingRecurringCharge()) {
            $this->reconcilePendingCharge($contract, $now);

            return;
        }

        /** @var string $parentPaymentId */
        $parentPaymentId = $contract->goPayParentPaymentId;

        // Calculate amount (full month or prorated for last billing cycle)
        $amount = $this->amountCalculator->calculate($contract, $now);

        if ($amount <= 0) {
            $this->logger->info('Skipping billing — zero or negative amount', [
                'contract_id' => $contract->id->toRfc4122(),
            ]);

            return;
        }

        // Charge via GoPay
        $payment = $this->goPayClient->createRecurrence(
            $parentPaymentId,
            $amount,
            $this->buildOrderNumber($contract->id, $now),
            $this->buildDescription($contract, $now),
        );

        // GoPay may return CREATED while the card charge is still processing.
        // Poll for confirmation before giving up.
        if ('PAID' !== $payment->state) {
            $payment = $this->pollForConfirmation($payment);
        }

        if ('PAID' !== $payment->state) {
            // Polling timed out. Record the GoPay payment ID as in-flight so the
            // next cron run reconciles it instead of issuing another charge, and
            // return successfully — the webhook is expected to close the loop.
            $contract->recordInFlightCharge($payment->id);

            $this->logger->warning('Recurring payment still pending after polling — webhook will reconcile', [
                'gopay_payment_id' => $payment->id,
                'state' => $payment->state,
                'contract_id' => $contract->id->toRfc4122(),
            ]);

            return;
        }

        $this->finalizeCharge($contract, $payment->id, $amount, $now);
    }

    /**
     * Look up an in-flight GoPay payment and act on its status:
     *  - PAID     → reconcile billing dates inline (webhook missed/slow)
     *  - pending  → skip this run; webhook or next cron will close it
     *  - terminal → throw {@see PaymentNotConfirmedException} so the cron
     *               records a normal billing failure
     */
    private function reconcilePendingCharge(Contract $contract, \DateTimeImmutable $now): void
    {
        /** @var string $pendingId */
        $pendingId = $contract->pendingRecurringPaymentId;
        $status = $this->goPayClient->getStatus($pendingId);

        if ($status->isPaid()) {
            $amount = $status->amount ?? $contract->getEffectiveMonthlyAmount();
            $this->finalizeCharge($contract, $status->id, $amount, $now);

            $this->logger->info('Recurring payment reconciled inline from in-flight state', [
                'contract_id' => $contract->id->toRfc4122(),
                'gopay_payment_id' => $status->id,
            ]);

            return;
        }

        if ($status->isPending()) {
            $this->logger->info('Skipping recurring charge: previous attempt still in flight', [
                'contract_id' => $contract->id->toRfc4122(),
                'gopay_payment_id' => $pendingId,
                'state' => $status->state,
            ]);

            return;
        }

        // Terminal failure (CANCELED / TIMEOUTED / REFUNDED). Clear in-flight
        // tracking and surface as a normal billing failure — the cron will
        // record the failed attempt and the retry cron will pick up the
        // contract under the standard 3-day / 7-day retry ladder.
        $contract->clearPendingRecurringCharge();

        throw PaymentNotConfirmedException::withState($pendingId, $status->state);
    }

    private function finalizeCharge(Contract $contract, string $paymentId, int $amount, \DateTimeImmutable $now): void
    {
        // Use nextBillingDate as billing period start for deterministic proration
        /** @var \DateTimeImmutable $billingPeriodStart */
        $billingPeriodStart = $contract->nextBillingDate ?? $now;
        $effectiveEndDate = $contract->getEffectiveEndDate();
        $nextBillingDate = $billingPeriodStart->modify($contract->getBillingCadenceStep());
        $paidThroughDate = $nextBillingDate;

        // If this was the last billing cycle, stop future charges.
        // UNLIMITED contracts auto-extend (endDate advances on each charge),
        // so they never reach a "last cycle".
        if (null !== $effectiveEndDate && $nextBillingDate >= $effectiveEndDate && !$contract->isUnlimited()) {
            $nextBillingDate = null;
            $paidThroughDate = $effectiveEndDate;
        }

        $contract->recordBillingCharge($now, $nextBillingDate, $paidThroughDate);

        $this->eventBus->dispatch(new RecurringPaymentCharged(
            contractId: $contract->id,
            paymentId: $paymentId,
            amount: $amount,
            occurredOn: $now,
        ));
    }

    /**
     * Poll GoPay for payment confirmation. Returns updated payment state.
     */
    private function pollForConfirmation(GoPayPayment $payment): GoPayPayment
    {
        for ($i = 0; $i < self::MAX_STATUS_POLLS; ++$i) {
            if ($this->pollIntervalMicroseconds > 0) {
                usleep($this->pollIntervalMicroseconds);
            }

            $status = $this->goPayClient->getStatus($payment->id);

            if (!$status->isPending()) {
                return new GoPayPayment($status->id, $payment->gwUrl, $status->state);
            }
        }

        return $payment;
    }

    private function buildOrderNumber(Uuid $contractId, \DateTimeImmutable $now): string
    {
        return sprintf('REC-%s-%s', $contractId->toRfc4122(), $now->format('Ymd'));
    }

    private function buildDescription(Contract $contract, \DateTimeImmutable $now): string
    {
        return sprintf(
            'Pravidelná platba - %s (%s)',
            $contract->storage->storageType->name,
            $now->format('m/Y'),
        );
    }
}
