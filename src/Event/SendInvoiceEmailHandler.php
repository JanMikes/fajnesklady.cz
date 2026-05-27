<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\InvoiceRepository;
use App\Service\OrderStatusUrlGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

/**
 * Standalone invoice e-mail — the "Faktura X" message with a single PDF
 * attachment. Active for every recurring monthly charge, and as a fallback
 * for the first-payment invoice when SendRentalActivatedEmailHandler
 * could not bundle it (Fakturoid PDF download failed, or
 * IssueMissingInvoicesCommand later issued the invoice out-of-band).
 *
 * Skipped when Invoice.emailedAt is already set — i.e. the rental-activated
 * handler already attached this invoice to its bigger e-mail and marked
 * the delivery.
 */
#[AsMessageHandler]
final readonly class SendInvoiceEmailHandler
{
    public function __construct(
        private InvoiceRepository $invoiceRepository,
        private MailerInterface $mailer,
        private OrderStatusUrlGenerator $statusUrlGenerator,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(InvoiceCreated $event): void
    {
        $invoice = $this->invoiceRepository->get($event->invoiceId);

        if ($invoice->isEmailed()) {
            return;
        }

        $user = $invoice->user;
        $order = $invoice->order;
        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $hasPdfAttachment = $invoice->hasPdf() && null !== $invoice->pdfPath && file_exists($invoice->pdfPath);

        $statusUrl = $this->statusUrlGenerator->generate($order);

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject('Faktura '.$invoice->invoiceNumber.' - Fajnesklady.cz')
            ->htmlTemplate('email/invoice.html.twig')
            ->context([
                'name' => $user->fullName,
                'invoiceNumber' => $invoice->invoiceNumber,
                'amount' => number_format($invoice->getAmountInCzk(), 2, ',', ' ').' Kč',
                'issuedAt' => $invoice->issuedAt->format('d.m.Y'),
                'placeName' => $place->name,
                'storageType' => $storageType->name,
                'storageNumber' => $storage->number,
                'hasPdfAttachment' => $hasPdfAttachment,
                'statusUrl' => $statusUrl,
            ]);

        if ($hasPdfAttachment) {
            $email->attachFromPath(
                $invoice->pdfPath,
                sprintf('faktura_%s.pdf', $invoice->invoiceNumber),
            );
        }

        $invoice->markEmailed($this->clock->now());

        $email->getHeaders()->addTextHeader('X-Order-Id', $order->id->toRfc4122());

        $this->mailer->send($email);
    }
}
