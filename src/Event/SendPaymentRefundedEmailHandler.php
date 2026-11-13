<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Invoice;
use App\Entity\PaymentRefund;
use App\Enum\RefundMode;
use App\Repository\PaymentRefundRepository;
use App\Service\AuditLogger;
use App\Service\InvoicingService;
use App\Service\OrderStatusUrlGenerator;
use App\Service\Refund\RefundablePaymentResolver;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

/**
 * A confirmed card refund (spec 092): issue the Fakturoid credit note when
 * the admin asked for one, then tell the customer — with the credit note
 * attached. Fakturoid is best-effort: the money is already back, so the
 * customer hears about it even when the credit note fails.
 *
 * Refunds issued outside the app (EXTERNAL, GoPay console) send nothing —
 * whoever issued them handles the customer and the accounting.
 */
#[AsMessageHandler]
final readonly class SendPaymentRefundedEmailHandler
{
    public function __construct(
        private PaymentRefundRepository $refundRepository,
        private RefundablePaymentResolver $resolver,
        private InvoicingService $invoicingService,
        private OrderStatusUrlGenerator $statusUrlGenerator,
        private AuditLogger $auditLogger,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PaymentRefunded $event): void
    {
        $refund = $this->refundRepository->find($event->refundId);
        if (null === $refund || $refund->isExternal()) {
            return;
        }

        $order = $refund->order;
        $user = $order->user;
        $storage = $refund->payment->storage;

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject('Vrácení platby — Fajnesklady.cz')
            ->htmlTemplate('email/payment_refunded.html.twig');

        $creditNote = $refund->issueCreditNote ? $this->issueCreditNote($refund, $event->occurredOn) : null;
        $attached = null !== $creditNote && $this->attach($email, $creditNote, $event->occurredOn);

        $refundedFrom = $refund->refundFrom;
        if (null !== $refundedFrom && null !== $refund->periodStart && $refundedFrom < $refund->periodStart) {
            $refundedFrom = $refund->periodStart;
        }

        $email->context([
            'name' => $user->fullName,
            'amountCzk' => number_format($refund->getAmountInCzk(), 0, ',', ' '),
            'paymentAmountCzk' => number_format($refund->payment->getAmountInCzk(), 0, ',', ' '),
            'paymentPaidAt' => $refund->payment->paidAt,
            'isProrated' => RefundMode::PRORATED === $refund->mode,
            'refundedFrom' => $refundedFrom,
            'refundedTo' => $refund->periodEnd?->modify('-1 day'),
            'storageNumber' => $storage->number,
            'placeName' => $storage->getPlace()->name,
            'creditNoteNumber' => $attached ? $creditNote->invoiceNumber : null,
            'originalInvoiceNumber' => $creditNote?->correctionOf?->invoiceNumber,
            'statusUrl' => $this->statusUrlGenerator->generate($order),
        ]);

        $email->getHeaders()->addTextHeader('X-Order-Id', $order->id->toRfc4122());

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send payment refunded email', [
                'refund_id' => $refund->id->toRfc4122(),
                'exception' => $e,
            ]);
        }
    }

    private function issueCreditNote(PaymentRefund $refund, \DateTimeImmutable $now): ?Invoice
    {
        [$original, $unavailableReason] = $this->resolver->findOriginalInvoice($refund->payment);

        if (null === $original) {
            $this->audit($refund, 'credit_note_skipped', ['credit_note_error' => $unavailableReason]);

            return null;
        }

        try {
            $creditNote = $this->invoicingService->issueCreditNote($refund, $original, $now);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to issue credit note for a refund; customer e-mail goes out without it', [
                'refund_id' => $refund->id->toRfc4122(),
                'exception' => $e,
            ]);
            $this->audit($refund, 'credit_note_failed', [
                'original_invoice_number' => $original->invoiceNumber,
                'credit_note_error' => $e->getMessage(),
            ]);

            return null;
        }

        $this->audit($refund, 'credit_note_issued', [
            'credit_note_number' => $creditNote->invoiceNumber,
            'original_invoice_number' => $original->invoiceNumber,
        ]);

        return $creditNote;
    }

    private function attach(TemplatedEmail $email, Invoice $creditNote, \DateTimeImmutable $now): bool
    {
        if (!$creditNote->hasPdf() || null === $creditNote->pdfPath || !file_exists($creditNote->pdfPath)) {
            return false;
        }

        $bytes = @file_get_contents($creditNote->pdfPath);
        if (false === $bytes) {
            return false;
        }

        $email->attach($bytes, sprintf('opravny_doklad_%s.pdf', $creditNote->invoiceNumber), 'application/pdf');
        $creditNote->markEmailed($now); // suppress the standalone "Faktura" e-mail

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function audit(PaymentRefund $refund, string $eventType, array $payload): void
    {
        $this->auditLogger->log(
            entityType: 'payment_refund',
            entityId: $refund->id->toRfc4122(),
            eventType: $eventType,
            payload: ['amount' => $refund->amount, ...$payload],
            orderId: $refund->order->id,
            userIdContext: $refund->order->user->id,
        );
    }
}
