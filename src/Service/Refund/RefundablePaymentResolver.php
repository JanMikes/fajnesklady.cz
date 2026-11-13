<?php

declare(strict_types=1);

namespace App\Service\Refund;

use App\Entity\Contract;
use App\Entity\Invoice;
use App\Entity\Payment;
use App\Enum\PaymentMethod;
use App\Repository\InvoiceRepository;
use App\Repository\PaymentRefundRepository;
use App\Repository\PaymentRepository;

/**
 * Finds the card payment that may be refunded on termination (spec 092):
 * only the LATEST real payment of the order/contract — it covers the current
 * period; older periods are consumed — and only when it went through GoPay
 * and has not been refunded yet.
 */
final readonly class RefundablePaymentResolver
{
    // Invoices are issued right after the money arrives, or by the 5-minute
    // backstop cron; a day either way still never reaches the next cycle.
    private const string INVOICE_MATCH_WINDOW = '1 day';

    public function __construct(
        private PaymentRepository $paymentRepository,
        private PaymentRefundRepository $refundRepository,
        private InvoiceRepository $invoiceRepository,
    ) {
    }

    public function resolve(Contract $contract): ?RefundablePayment
    {
        $order = $contract->order;
        $payment = $this->paymentRepository->findLatestForOrder($order, $contract);

        if (null === $payment || PaymentMethod::GOPAY !== $payment->paidVia || null === $payment->goPayPaymentId) {
            return null;
        }

        foreach ($this->refundRepository->findByPayment($payment) as $refund) {
            if (!$refund->isPending() && !$refund->isSucceeded()) {
                continue; // a failed attempt does not block another one
            }

            return null; // already refunded (fully or partially) or in flight
        }

        [$periodStart, $periodEnd] = $this->period($contract, $payment);
        [$invoice, $reason] = $this->findOriginalInvoice($payment);

        return new RefundablePayment(
            payment: $payment,
            goPayPaymentId: $payment->goPayPaymentId,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            originalInvoice: $invoice,
            creditNoteUnavailableReason: $reason,
        );
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function period(Contract $contract, Payment $payment): array
    {
        if (null !== $payment->periodStart && null !== $payment->periodEnd) {
            return [$payment->periodStart, $payment->periodEnd];
        }

        // The order's first payment carries no period; while no later charge
        // exists it paid from the contract start to the current paid-through.
        if (null !== $payment->order && !$this->paymentRepository->hasLaterPayment($contract->order, $contract, $payment->paidAt)) {
            return [$contract->startDate, $contract->paidThroughDate ?? $contract->endDate];
        }

        return [null, null];
    }

    /**
     * The invoice that documented $payment — what a credit note corrects.
     * Returns [invoice, null] on exactly one match, else [null, reason].
     *
     * @return array{0: ?Invoice, 1: ?string}
     */
    public function findOriginalInvoice(Payment $payment): array
    {
        $order = $payment->order ?? $payment->contract?->order;
        if (null === $order) {
            return [null, 'Platba není navázaná na objednávku.'];
        }

        $candidates = $this->invoiceRepository->findRentInvoicesForAmountIssuedBetween(
            $order,
            $payment->amount,
            $payment->paidAt->modify('-'.self::INVOICE_MATCH_WINDOW),
            $payment->paidAt->modify('+'.self::INVOICE_MATCH_WINDOW),
        );

        return match (count($candidates)) {
            1 => [$candidates[0], null],
            0 => [null, 'K platbě nebyla nalezena faktura — dobropis vystavte ručně ve Fakturoidu.'],
            default => [null, 'K platbě odpovídá více faktur — dobropis vystavte ručně ve Fakturoidu.'],
        };
    }
}
