<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\InvoiceRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final readonly class SendInvoiceEmailHandler
{
    public function __construct(
        private InvoiceRepository $invoiceRepository,
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(InvoiceCreated $event): void
    {
        $invoice = $this->invoiceRepository->get($event->invoiceId);
        $user = $invoice->user;
        $order = $invoice->order;
        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajné Sklady'))
            ->to(new Address($user->email, $user->fullName))
            ->subject('Faktura '.$invoice->invoiceNumber.' - Fajné Sklady')
            ->htmlTemplate('email/invoice.html.twig')
            ->context([
                'name' => $user->fullName,
                'invoiceNumber' => $invoice->invoiceNumber,
                'amount' => number_format($invoice->getAmountInCzk(), 2, ',', ' ').' Kč',
                'issuedAt' => $invoice->issuedAt->format('d.m.Y'),
                'placeName' => $place->name,
                'storageType' => $storageType->name,
                'storageNumber' => $storage->number,
            ]);

        if ($invoice->hasPdf() && null !== $invoice->pdfPath && file_exists($invoice->pdfPath)) {
            $email->attachFromPath(
                $invoice->pdfPath,
                sprintf('faktura_%s.pdf', $invoice->invoiceNumber),
            );
        }

        $this->mailer->send($email);
    }
}
