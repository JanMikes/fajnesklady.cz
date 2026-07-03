<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Fine;
use App\Repository\FineRepository;
use App\Service\InvoicingService;
use App\Service\OrderStatusUrlGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final readonly class SendFinePaidEmailHandler
{
    public function __construct(
        private FineRepository $fineRepository,
        private InvoicingService $invoicingService,
        private OrderStatusUrlGenerator $statusUrlGenerator,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FinePaid $event): void
    {
        $fine = $this->fineRepository->findById($event->fineId);
        if (null === $fine) {
            return;
        }

        $user = $fine->user;

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject('Pokuta zaplacena — Fajnesklady.cz')
            ->htmlTemplate('email/fine_paid.html.twig');

        $invoiceNumber = $this->attachFineInvoice($email, $fine, $event->occurredOn);

        $email->context([
            'name' => $user->fullName,
            'fineType' => $fine->type->label(),
            'amountCzk' => number_format($fine->getAmountInCzk(), 0, ',', ' '),
            'paidAt' => $fine->paidAt,
            'invoiceNumber' => $invoiceNumber,
            'statusUrl' => $this->statusUrlGenerator->generate($fine->contract->order),
        ]);

        $email->getHeaders()->addTextHeader('X-Order-Id', $fine->contract->order->id->toRfc4122());

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send fine paid email', [
                'fine_id' => $fine->id->toRfc4122(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Issue the fine invoice and attach its PDF. Best-effort: returns the
     * invoice number when the PDF was bundled (and marks the invoice emailed
     * so the standalone SendInvoiceEmailHandler skips), null otherwise.
     */
    private function attachFineInvoice(TemplatedEmail $email, Fine $fine, \DateTimeImmutable $now): ?string
    {
        try {
            $invoice = $this->invoicingService->issueInvoiceForFine($fine, $now);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to issue fine invoice; receipt e-mail will be sent without it', [
                'fine_id' => $fine->id->toRfc4122(),
                'exception' => $e,
            ]);

            return null;
        }

        if (!$invoice->hasPdf() || null === $invoice->pdfPath || !file_exists($invoice->pdfPath)) {
            return null;
        }

        $invoiceBytes = @file_get_contents($invoice->pdfPath);
        if (false === $invoiceBytes) {
            return null;
        }

        $email->attach($invoiceBytes, sprintf('faktura_%s.pdf', $invoice->invoiceNumber), 'application/pdf');
        $invoice->markEmailed($now); // suppress the standalone SendInvoiceEmailHandler

        return $invoice->invoiceNumber;
    }
}
