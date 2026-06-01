<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Service\InvoicingService;
use App\Service\OrderStatusUrlGenerator;
use App\Service\Place\PlaceAddressFormatter;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

/**
 * "Dluh uhrazen" receipt — fires on OnboardingDebtPaid (GoPay webhook or FIO
 * bank-transfer cron). Confirms the paid debt to the customer (which storage,
 * how much, when), points to the next step via /stav, and bundles a Fakturoid
 * invoice for the debt. Issuing the invoice is best-effort: a Fakturoid outage
 * must never block the receipt.
 */
#[AsMessageHandler]
final readonly class SendOnboardingDebtPaidEmailHandler
{
    public function __construct(
        private OrderRepository $orderRepository,
        private InvoicingService $invoicingService,
        private OrderStatusUrlGenerator $statusUrlGenerator,
        private PlaceAddressFormatter $addressFormatter,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(OnboardingDebtPaid $event): void
    {
        $order = $this->orderRepository->get($event->orderId);
        $user = $order->user;
        $storage = $order->storage;
        $place = $storage->getPlace();

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject(sprintf('Dluh uhrazen — %s Kč', number_format($event->amountInHaler / 100, 0, ',', ' ')))
            ->htmlTemplate('email/debt_paid.html.twig');

        $invoiceNumber = $this->attachDebtInvoice($email, $order, $event->occurredOn);

        $email->context([
            'name' => $user->fullName,
            'amountCzk' => number_format($event->amountInHaler / 100, 0, ',', ' '),
            'paidAt' => $order->debtPaidAt,
            'storageNumber' => $storage->number,
            'storageTypeName' => $storage->storageType->name,
            'placeName' => $place->name,
            'placeAddress' => $this->addressFormatter->format($place),
            // Standard billing → order still RESERVED (owes first rent); free/prepaid → already COMPLETED.
            'awaitingFirstPayment' => $order->canBePaid(),
            'statusUrl' => $this->statusUrlGenerator->generate($order),
            'invoiceNumber' => $invoiceNumber,
        ]);

        $email->getHeaders()->addTextHeader('X-Order-Id', $order->id->toRfc4122());

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send debt paid email', [
                'order_id' => $order->id->toRfc4122(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Issue the debt invoice and attach its PDF. Best-effort: returns the
     * invoice number when the PDF was bundled (and marks the invoice emailed
     * so the standalone SendInvoiceEmailHandler skips), null otherwise.
     */
    private function attachDebtInvoice(TemplatedEmail $email, Order $order, \DateTimeImmutable $now): ?string
    {
        try {
            $invoice = $this->invoicingService->issueInvoiceForDebt($order, $now);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to issue debt invoice; receipt e-mail will be sent without it', [
                'order_id' => $order->id->toRfc4122(),
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
