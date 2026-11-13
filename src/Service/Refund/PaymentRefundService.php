<?php

declare(strict_types=1);

namespace App\Service\Refund;

use App\Entity\Contract;
use App\Entity\Payment;
use App\Entity\PaymentRefund;
use App\Entity\User;
use App\Enum\RefundMode;
use App\Exception\PaymentRefundFailed;
use App\Repository\PaymentRefundRepository;
use App\Repository\PaymentRepository;
use App\Service\AuditLogger;
use App\Service\GoPay\GoPayClient;
use App\Service\Identity\ProvideIdentity;
use App\Value\GoPayRefundHistoryEntry;
use Psr\Log\LoggerInterface;

/**
 * Card-payment refunds (spec 092): request at GoPay, book the confirmed
 * money in the ledger, and keep the order history telling the whole story.
 *
 * Ledger rule: a refund becomes a NEGATIVE Payment (refundOf → the original)
 * only once GoPay confirms the money went back — never on a mere request.
 *
 * GoPay gives refunds no id of their own, so reconciliation compares totals:
 * SUCCESS in GoPay's history minus what the app already booked. A positive
 * remainder confirms our pending refund first; anything beyond that was
 * refunded outside the app (GoPay console) and is booked as EXTERNAL.
 */
final readonly class PaymentRefundService
{
    public const string GOPAY_FINISHED = 'FINISHED';
    public const string GOPAY_ACCEPTED = 'ACCEPTED';

    public function __construct(
        private GoPayClient $goPayClient,
        private PaymentRepository $paymentRepository,
        private PaymentRefundRepository $refundRepository,
        private ProvideIdentity $identityProvider,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Ask GoPay to return the money. FINISHED books it immediately, ACCEPTED
     * leaves the refund pending for {@see reconcile()}; FAILED (or an API
     * error) throws {@see PaymentRefundFailed} — the caller's transaction then
     * rolls back, so no trace is left and the failure must be recorded
     * outside it.
     */
    public function refund(
        Contract $contract,
        RefundablePayment $refundable,
        RefundMode $mode,
        ?\DateTimeImmutable $refundFrom,
        bool $issueCreditNote,
        ?string $reason,
        ?User $admin,
        \DateTimeImmutable $now,
    ): PaymentRefund {
        $payment = $refundable->payment;
        $quote = RefundCalculator::quote($payment->amount, $mode, $refundable->periodStart, $refundable->periodEnd, $refundFrom);

        // Ask GoPay FIRST, persist only on success: a declined refund must
        // leave nothing in the UnitOfWork — the rollback does not detach it,
        // and the next dispatch (failure recording) would flush it as a
        // phantom pending refund.
        try {
            $result = $this->goPayClient->refundPayment($refundable->goPayPaymentId, $quote->amount);
        } catch (\Throwable $e) {
            throw PaymentRefundFailed::apiError($quote->amount, $e);
        }

        if (self::GOPAY_FINISHED !== $result && self::GOPAY_ACCEPTED !== $result) {
            throw PaymentRefundFailed::declined($quote->amount, $result);
        }

        $refund = new PaymentRefund(
            id: $this->identityProvider->next(),
            payment: $payment,
            order: $contract->order,
            contract: $contract,
            amount: $quote->amount,
            mode: $mode,
            refundFrom: RefundMode::PRORATED === $mode ? $refundFrom : null,
            periodStart: $refundable->periodStart,
            periodEnd: $refundable->periodEnd,
            issueCreditNote: $issueCreditNote && $refundable->canIssueCreditNote(),
            reason: $reason,
            requestedBy: $admin,
            requestedAt: $now,
        );
        $this->refundRepository->save($refund);

        $this->auditLogger->log(
            entityType: 'payment_refund',
            entityId: $refund->id->toRfc4122(),
            eventType: 'requested',
            payload: $this->describe($refund, [
                'refunded_days' => $quote->refundedDays,
                'period_days' => $quote->periodDays,
                'gopay_result' => $result,
            ]),
            orderId: $refund->order->id,
            userIdContext: $refund->order->user->id,
        );

        if (self::GOPAY_FINISHED === $result) {
            $this->confirm($refund, $now);
        }

        return $refund;
    }

    /**
     * Bring the app in line with GoPay's refund history for one payment.
     * Idempotent: safe to run from the webhook, the cron and a console
     * command repeatedly and concurrently (the payment row is locked).
     */
    public function reconcile(Payment $payment, \DateTimeImmutable $now): void
    {
        $goPayPaymentId = $payment->goPayPaymentId;
        if (null === $goPayPaymentId || $payment->isRefund()) {
            return;
        }

        $this->paymentRepository->lockForUpdate($payment);

        $history = $this->goPayClient->getRefundHistory($goPayPaymentId);

        $goPaySucceeded = 0;
        $goPayHasRequested = false;
        foreach ($history as $entry) {
            if ($entry->isSuccess()) {
                $goPaySucceeded += $entry->amount;
            } elseif ($entry->isRequested()) {
                $goPayHasRequested = true;
            }
        }

        $booked = 0;
        $pending = [];
        foreach ($this->refundRepository->findByPayment($payment) as $refund) {
            if ($refund->isSucceeded()) {
                $booked += $refund->amount;
            } elseif ($refund->isPending()) {
                $pending[] = $refund;
            }
        }

        foreach ($pending as $refund) {
            if ($goPaySucceeded - $booked >= $refund->amount) {
                $this->confirm($refund, $now);
                $booked += $refund->amount;
            } elseif (!$goPayHasRequested) {
                $this->fail($refund, $now, $this->failureFromHistory($history));
            }
        }

        $unbooked = $goPaySucceeded - $booked;
        if ($unbooked > 0) {
            $this->bookExternal($payment, min($unbooked, $payment->amount - $booked), $now);
        }
    }

    public function confirm(PaymentRefund $refund, \DateTimeImmutable $now): void
    {
        $refund->markSucceeded($now);

        $original = $refund->payment;
        $storno = new Payment(
            id: $this->identityProvider->next(),
            order: $original->order,
            contract: $original->contract,
            storage: $original->storage,
            amount: -$refund->amount,
            paidAt: $now,
            createdAt: $now,
        );
        $storno->markAsRefundOf($original);
        $this->paymentRepository->save($storno);

        $this->auditLogger->log(
            entityType: 'payment_refund',
            entityId: $refund->id->toRfc4122(),
            eventType: $refund->isExternal() ? 'refunded_externally' : 'succeeded',
            payload: $this->describe($refund),
            orderId: $refund->order->id,
            userIdContext: $refund->order->user->id,
        );
    }

    public function fail(PaymentRefund $refund, \DateTimeImmutable $now, string $reason): void
    {
        $refund->markFailed($now, $reason);

        $this->logger->error('GoPay refund failed', [
            'refund_id' => $refund->id->toRfc4122(),
            'gopay_payment_id' => $refund->payment->goPayPaymentId,
            'amount' => $refund->amount,
            'reason' => $reason,
        ]);

        $this->auditLogger->log(
            entityType: 'payment_refund',
            entityId: $refund->id->toRfc4122(),
            eventType: 'failed',
            payload: $this->describe($refund, ['failure_reason' => $reason]),
            orderId: $refund->order->id,
            userIdContext: $refund->order->user->id,
        );
    }

    /**
     * Audit payload shared by every refund event — everything an admin needs
     * to read the history without opening GoPay: how much, for what period,
     * which payment, why, and whether a credit note follows.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function describe(PaymentRefund $refund, array $extra = []): array
    {
        return [
            'amount' => $refund->amount,
            'payment_amount' => $refund->payment->amount,
            'mode' => $refund->mode->value,
            'refund_from' => $refund->refundFrom?->format('Y-m-d'),
            'period_start' => $refund->periodStart?->format('Y-m-d'),
            'period_end' => $refund->periodEnd?->format('Y-m-d'),
            'gopay_payment_id' => $refund->payment->goPayPaymentId,
            'payment_paid_at' => $refund->payment->paidAt->format('Y-m-d'),
            'reason' => $refund->reason,
            'issue_credit_note' => $refund->issueCreditNote,
            'contract_id' => $refund->contract?->id->toRfc4122(),
            ...$extra,
        ];
    }

    private function bookExternal(Payment $payment, int $amount, \DateTimeImmutable $now): void
    {
        if ($amount <= 0) {
            return;
        }

        $order = $payment->order ?? $payment->contract?->order;
        if (null === $order) {
            $this->logger->error('Refund found in GoPay for a payment without an order — cannot book it', [
                'payment_id' => $payment->id->toRfc4122(),
                'gopay_payment_id' => $payment->goPayPaymentId,
                'amount' => $amount,
            ]);

            return;
        }

        $refund = new PaymentRefund(
            id: $this->identityProvider->next(),
            payment: $payment,
            order: $order,
            contract: $payment->contract,
            amount: $amount,
            mode: RefundMode::EXTERNAL,
            refundFrom: null,
            periodStart: $payment->periodStart,
            periodEnd: $payment->periodEnd,
            issueCreditNote: false,
            reason: 'Vráceno mimo aplikaci (administrace GoPay) — zjištěno z historie refundací GoPay.',
            requestedBy: null,
            requestedAt: $now,
        );
        $this->refundRepository->save($refund);

        $this->logger->warning('Card refund issued outside the app booked from GoPay history', [
            'refund_id' => $refund->id->toRfc4122(),
            'gopay_payment_id' => $payment->goPayPaymentId,
            'amount' => $amount,
        ]);

        $this->confirm($refund, $now);
    }

    /**
     * @param list<GoPayRefundHistoryEntry> $history
     */
    private function failureFromHistory(array $history): string
    {
        foreach (array_reverse($history) as $entry) {
            if (GoPayRefundHistoryEntry::STATE_FAILED === $entry->state) {
                return 'GoPay vrácení zamítl (stav FAILED v historii refundací).';
            }
        }

        return 'GoPay vrácení nepotvrdil — v historii refundací chybí.';
    }
}
