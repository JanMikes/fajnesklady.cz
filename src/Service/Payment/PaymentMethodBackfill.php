<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\BankTransaction;
use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\Payment;
use App\Enum\AllocationStepType;
use App\Enum\BankTransactionStatus;
use App\Enum\PaymentMethod;
use App\Repository\AuditLogRepository;
use App\Repository\BankTransactionAllocationRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\ContractRepository;
use App\Repository\FineRepository;
use App\Repository\OrderRepository;
use App\Repository\PaymentRepository;

/**
 * One-off repair behind app:backfill-payment-methods: works out HOW each
 * historical payment arrived (card / bank transfer / off-system) from the
 * evidence the system did keep — bank pairings, allocation records, audit
 * trail — and writes it where it is now stored explicitly.
 *
 * It never infers "card" from a GoPay session id alone: those ids are written
 * the moment a customer OPENS the gateway, and several orders were then paid
 * by transfer instead (order 019f5d05: debt carried a GoPay id, the money came
 * through FIO). Nor does it read a missing link as "off-system": a legacy row
 * simply predates the links. What cannot be proven is reported, not guessed.
 *
 * Every method mutates entities only (no flush) and returns human-readable
 * report lines; the console command owns persistence.
 */
final readonly class PaymentMethodBackfill
{
    // A Fakturoid invoice is issued in the same request as (or right after)
    // the payment it documents. Anything further apart is not the same event.
    private const int INVOICE_MATCH_WINDOW_SECONDS = 86_400;

    public function __construct(
        private PaymentRepository $paymentRepository,
        private BankTransactionRepository $bankTransactionRepository,
        private BankTransactionAllocationRepository $allocationRepository,
        private OrderRepository $orderRepository,
        private FineRepository $fineRepository,
        private ContractRepository $contractRepository,
        private AuditLogRepository $auditLogRepository,
    ) {
    }

    /**
     * Step 1 — first payments recorded before the GoPay / bank link was
     * stored. A first-payment transfer paired to the order (and not to its
     * contract — those pay billing cycles) is the proof of a bank payment.
     *
     * @return list<string>
     */
    public function linkFirstPayments(): array
    {
        $report = [];

        foreach ($this->paymentRepository->findUnlinkedFirstPayments() as $payment) {
            $order = $payment->order;
            \assert(null !== $order);

            $bankTransaction = $this->findFirstPaymentTransfer($payment, $order);
            if (null !== $bankTransaction) {
                $payment->linkBankTransaction($bankTransaction);
                $report[] = sprintf('payment %s (order %s): linked to bank transaction %s', $payment->id, $order->id, $bankTransaction->id);

                continue;
            }

            // By elimination, not from the session id: a first payment with
            // money on it arrives by FIO transfer (paired to the order), by an
            // admin record (which switches the order to EXTERNAL), or by the
            // GoPay webhook. With no transfer paired to the order at all and
            // the order still on GOPAY, only the webhook is left.
            if (PaymentMethod::GOPAY === $order->paymentMethod
                && null !== $order->goPayPaymentId
                && [] === $this->bankTransactionRepository->findByOrder($order)
                && !$this->paymentRepository->existsByGoPayPaymentId($order->goPayPaymentId)) {
                $payment->setGoPayPaymentId($order->goPayPaymentId);
                $report[] = sprintf('payment %s (order %s): linked to GoPay payment %s', $payment->id, $order->id, $order->goPayPaymentId);

                continue;
            }

            $report[] = sprintf('payment %s (order %s): NO EVIDENCE of card or transfer — left unknown, check manually', $payment->id, $order->id);
        }

        return $report;
    }

    /**
     * Step 2 — Order::$paidVia for paid orders, read off their first Payment
     * (whose links step 1 just completed).
     *
     * @return list<string>
     */
    public function backfillOrderPaidVia(): array
    {
        $report = [];

        foreach ($this->orderRepository->findPaidWithoutPaidVia() as $order) {
            $payment = $this->paymentRepository->findByOrder($order);
            if (null === $payment) {
                $report[] = sprintf('order %s: paid but no first Payment row — skipped', $order->id);

                continue;
            }

            $paidVia = $this->evidencedMethod($payment, $order);
            if (null === $paidVia) {
                $report[] = sprintf('order %s: first payment has no recorded source — left unknown, check manually', $order->id);

                continue;
            }

            $order->backfillPaidVia($paidVia);
            $report[] = sprintf('order %s: first payment → %s', $order->id, $paidVia->label());
        }

        return $report;
    }

    /**
     * Step 3 — Order::$debtPaidVia. The three settle paths each left a
     * distinct trace: the admin "settle" audit event, the GoPay id in the
     * webhook's confirmation, or a bank allocation step for the debt.
     *
     * @return list<string>
     */
    public function backfillDebtPaidVia(): array
    {
        $report = [];

        foreach ($this->orderRepository->findDebtPaidWithoutDebtPaidVia() as $order) {
            $paidVia = $this->resolveDebtPaidVia($order);
            if (null === $paidVia) {
                $report[] = sprintf('order %s: debt paid but no evidence of how — skipped', $order->id);

                continue;
            }

            $order->backfillDebtPaidVia($paidVia);
            $report[] = sprintf('order %s: debt → %s', $order->id, $paidVia->label());
        }

        return $report;
    }

    /**
     * Step 4 — Fine::$paidVia. A fine is settled only by the GoPay webhook or
     * a VS-matched transfer, and the transfer pairs to the fine's contract with
     * the "variable_symbol_fine" match method.
     *
     * @return list<string>
     */
    public function backfillFinePaidVia(): array
    {
        $report = [];

        foreach ($this->fineRepository->findPaidWithoutPaidVia() as $fine) {
            $paidByTransfer = false;
            foreach ($this->bankTransactionRepository->findByOrder($fine->contract->order) as $bankTransaction) {
                if ('variable_symbol_fine' === $bankTransaction->matchMethod && $bankTransaction->amount === $fine->amountInHaler) {
                    $paidByTransfer = true;

                    break;
                }
            }

            $paidVia = $paidByTransfer ? PaymentMethod::BANK_TRANSFER : PaymentMethod::GOPAY;
            $fine->backfillPaidVia($paidVia);
            $report[] = sprintf('fine %s: → %s', $fine->id, $paidVia->label());
        }

        return $report;
    }

    /**
     * How the money behind an issued invoice arrived — the payment (or debt /
     * fine settlement) of the same amount closest in time to the invoice.
     * Null when nothing matches within a day.
     */
    public function resolveInvoicePaymentMethod(Invoice $invoice): ?PaymentMethod
    {
        if (null !== $invoice->fine) {
            return $invoice->fine->paidVia;
        }

        $order = $invoice->order;

        /** @var list<array{at: \DateTimeImmutable, via: ?PaymentMethod}> $candidates */
        $candidates = [];

        if (null !== $order->debtPaidAt && $order->onboardingDebtInHaler === $invoice->amount) {
            $candidates[] = ['at' => $order->debtPaidAt, 'via' => $order->debtPaidVia];
        }

        $contract = $this->contractRepository->findByOrder($order);
        foreach ($this->paymentRepository->findAllForOrder($order, $contract) as $payment) {
            if ($payment->amount === $invoice->amount) {
                $candidates[] = ['at' => $payment->paidAt, 'via' => $this->evidencedMethod($payment, $order)];
            }
        }

        $best = null;
        $bestDistance = null;
        foreach ($candidates as $candidate) {
            $distance = abs($candidate['at']->getTimestamp() - $invoice->issuedAt->getTimestamp());
            if ($distance <= self::INVOICE_MATCH_WINDOW_SECONDS && (null === $bestDistance || $distance < $bestDistance)) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best['via'] ?? null;
    }

    /**
     * A Payment's method only when something proves it. Payment::$paidVia
     * reads "no link" as EXTERNAL, which is right for rows written today but
     * not for legacy rows that predate the links — those count as off-system
     * only when the order itself is EXTERNAL or no money moved at all.
     */
    private function evidencedMethod(Payment $payment, Order $order): ?PaymentMethod
    {
        if (PaymentMethod::EXTERNAL !== $payment->paidVia) {
            return $payment->paidVia;
        }

        return 0 === $payment->amount || PaymentMethod::EXTERNAL === $order->paymentMethod
            ? PaymentMethod::EXTERNAL
            : null;
    }

    /**
     * The transfer that paid the first payment: paired to the order (not its
     * contract — those pay cycles), same amount, not yet linked, and not the
     * transfer that settled an onboarding debt (also paired before the
     * contract exists). Anything but exactly one such transfer is ambiguous.
     */
    private function findFirstPaymentTransfer(Payment $payment, Order $order): ?BankTransaction
    {
        $candidates = array_values(array_filter(
            $this->bankTransactionRepository->findByOrder($order),
            fn (BankTransaction $bankTransaction): bool => null === $bankTransaction->pairedContract
                && $bankTransaction->amount === $payment->amount
                && in_array($bankTransaction->status, [BankTransactionStatus::MATCHED, BankTransactionStatus::AMOUNT_MISMATCH], true)
                && [] === $this->allocationRepository->findByTransactionAndType($bankTransaction, AllocationStepType::ONBOARDING_DEBT)
                && !$this->paymentRepository->isBankTransactionLinked($bankTransaction),
        ));

        return 1 === count($candidates) ? $candidates[0] : null;
    }

    private function resolveDebtPaidVia(Order $order): ?PaymentMethod
    {
        $auditLogs = $this->auditLogRepository->findForOrderTimeline($order->id);

        foreach ($auditLogs as $log) {
            if ('order' === $log->entityType && 'onboarding_debt_settled' === $log->eventType) {
                return PaymentMethod::EXTERNAL;
            }
        }

        foreach ($auditLogs as $log) {
            if ('order' === $log->entityType && 'debt_payment_confirmed' === $log->eventType
                && is_string($log->payload['gopay_payment_id'] ?? null)) {
                return PaymentMethod::GOPAY;
            }
        }

        if ($this->allocationRepository->sumForOrderByType($order, AllocationStepType::ONBOARDING_DEBT) > 0) {
            return PaymentMethod::BANK_TRANSFER;
        }

        // Pre-allocation-table rows: the allocation was still audit-logged.
        foreach ($auditLogs as $log) {
            if ('bank_transaction' !== $log->entityType || 'allocated' !== $log->eventType) {
                continue;
            }
            foreach (is_array($log->payload['steps'] ?? null) ? $log->payload['steps'] : [] as $step) {
                if (is_array($step) && AllocationStepType::ONBOARDING_DEBT->value === ($step['type'] ?? null)) {
                    return PaymentMethod::BANK_TRANSFER;
                }
            }
        }

        return null;
    }
}
