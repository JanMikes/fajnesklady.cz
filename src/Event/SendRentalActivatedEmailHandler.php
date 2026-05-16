<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Invoice;
use App\Entity\Order;
use App\Repository\ContractRepository;
use App\Repository\InvoiceRepository;
use App\Service\InvoicingService;
use App\Service\OrderEmailAttachments;
use App\Service\OrderStatusUrlGenerator;
use App\Service\RecurringPaymentCancelUrlGenerator;
use App\Service\StorageMapImageGenerator;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

/**
 * "Rental activated" e-mail (formerly "Smlouva připravena"). Fires on
 * OrderCompleted — i.e. after the first payment is confirmed and the contract
 * entity has been created. This is the all-in-one post-payment message: same
 * legal pack as the order-placed e-mail (so the customer has everything in
 * one inbox) plus the operational artefacts that only become relevant once
 * the rental is active (map, operating rules, instructions) plus — when
 * available — the invoice for the first payment.
 *
 * The invoice is issued synchronously here so it can be bundled into this
 * e-mail. If Fakturoid issuance succeeds AND the PDF is downloadable, the
 * invoice ships attached and Invoice.emailedAt is marked, suppressing the
 * standalone SendInvoiceEmailHandler. If issuance or PDF download fails,
 * this e-mail goes out without the invoice and the standalone handler (or
 * the IssueMissingInvoicesCommand cron) takes over as a fallback.
 */
#[AsMessageHandler]
final readonly class SendRentalActivatedEmailHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private InvoiceRepository $invoiceRepository,
        private InvoicingService $invoicingService,
        private OrderEmailAttachments $attachments,
        private MailerInterface $mailer,
        private OrderStatusUrlGenerator $statusUrlGenerator,
        private RecurringPaymentCancelUrlGenerator $cancelUrlGenerator,
        private StorageMapImageGenerator $mapImageGenerator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private string $uploadsDirectory,
    ) {
    }

    public function __invoke(OrderCompleted $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);
        $order = $contract->order;
        $user = $contract->user;
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();
        $now = $this->clock->now();

        $statusUrl = $this->statusUrlGenerator->generate($order);
        $isRecurring = $contract->hasActiveRecurringPayment();
        $mapImageData = $this->mapImageGenerator->generate($storage);

        $operatingRulesPath = null;
        if ($place->hasOperatingRules() && null !== $place->operatingRulesPath) {
            $candidate = $this->uploadsDirectory.'/'.$place->operatingRulesPath;
            if (file_exists($candidate)) {
                $operatingRulesPath = $candidate;
            }
        }

        $instructionsPath = null;
        if ($place->hasInstructions() && null !== $place->instructionsPath) {
            $candidate = $this->uploadsDirectory.'/'.$place->instructionsPath;
            if (file_exists($candidate)) {
                $instructionsPath = $candidate;
            }
        }

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject('Pronájem zahájen - '.$place->name)
            ->htmlTemplate('email/rental_activated.html.twig');

        // Same legal pack as the order-placed e-mail.
        $this->attachments->attachLegalDocuments($email, $order);

        // Bundle the first-payment invoice if we can. issueOrFindInvoice is
        // best-effort — failures fall back to the standalone invoice e-mail.
        $invoice = $this->issueOrFindInvoice($order, $now);
        $hasInvoiceAttachment = false;
        $invoiceNumber = null;
        if (null !== $invoice && $invoice->hasPdf() && null !== $invoice->pdfPath && file_exists($invoice->pdfPath)) {
            $invoiceBytes = @file_get_contents($invoice->pdfPath);
            if (false !== $invoiceBytes) {
                $email->attach(
                    $invoiceBytes,
                    sprintf('faktura_%s.pdf', $invoice->invoiceNumber),
                    'application/pdf',
                );
                $invoice->markEmailed($now);
                $hasInvoiceAttachment = true;
                $invoiceNumber = $invoice->invoiceNumber;
            }
        }

        // Map (per-storage, generated on demand).
        if (null !== $mapImageData) {
            $email->attach($mapImageData, 'mapa-skladu.png', 'image/png');
        }

        // Operating rules + instructions (per-place uploads).
        if (null !== $operatingRulesPath) {
            $extension = pathinfo($operatingRulesPath, PATHINFO_EXTENSION);
            $email->attachFromPath($operatingRulesPath, 'provozni_rad.'.$extension);
        }
        if (null !== $instructionsPath) {
            $extension = pathinfo($instructionsPath, PATHINFO_EXTENSION);
            $email->attachFromPath($instructionsPath, 'navod.'.$extension);
        }

        $email->context([
            'name' => $user->fullName,
            'contractNumber' => $this->formatContractNumber($contract),
            'placeName' => $place->name,
            'placeAddress' => sprintf('%s, %s %s', $place->address, $place->postalCode, $place->city),
            'storageType' => $storageType->name,
            'storageNumber' => $storage->number,
            'startDate' => $contract->startDate->format('d.m.Y'),
            'endDate' => $contract->endDate?->format('d.m.Y') ?? 'Na dobu neurčitou',
            'statusUrl' => $statusUrl,
            'isRecurring' => $isRecurring,
            'monthlyAmount' => $isRecurring ? number_format($contract->order->getFirstPaymentPriceInCzk(), 2, ',', ' ').' Kč' : null,
            'nextBillingDate' => $isRecurring && null !== $contract->nextBillingDate ? $contract->nextBillingDate->format('d.m.Y') : null,
            'cancelUrl' => $isRecurring ? $this->cancelUrlGenerator->generate($contract) : null,
            'hasMapAttachment' => null !== $mapImageData,
            'hasOperatingRulesAttachment' => null !== $operatingRulesPath,
            'hasInstructionsAttachment' => null !== $instructionsPath,
            'hasInvoiceAttachment' => $hasInvoiceAttachment,
            'invoiceNumber' => $invoiceNumber,
        ]);

        $this->mailer->send($email);
    }

    private function issueOrFindInvoice(Order $order, \DateTimeImmutable $now): ?Invoice
    {
        $invoice = $this->invoiceRepository->findByOrder($order);
        if (null !== $invoice) {
            return $invoice;
        }

        // Free contracts produce no invoice. The recurring cron has the same
        // early-return on $amount <= 0; spec 025 keeps invoicing in sync.
        if (0 === $order->firstPaymentPrice) {
            return null;
        }

        try {
            return $this->invoicingService->issueInvoiceForOrder($order, $now);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to issue invoice while sending rental-activated e-mail; standalone fallback will retry', [
                'order_id' => $order->id->toRfc4122(),
                'exception' => $e,
            ]);

            return null;
        }
    }

    private function formatContractNumber(\App\Entity\Contract $contract): string
    {
        $date = $contract->createdAt;
        $uuidShort = substr($contract->id->toRfc4122(), 0, 8);

        return sprintf(
            '%s-%s-%s',
            $date->format('Y'),
            $date->format('md'),
            strtoupper($uuidShort),
        );
    }
}
