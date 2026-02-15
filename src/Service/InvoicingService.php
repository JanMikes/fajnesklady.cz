<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\User;
use App\Repository\InvoiceRepository;
use App\Repository\UserRepository;
use App\Service\Fakturoid\FakturoidClient;
use App\Service\Identity\ProvideIdentity;
use Psr\Log\LoggerInterface;

readonly class InvoicingService
{
    public function __construct(
        private FakturoidClient $fakturoidClient,
        private ProvideIdentity $identityProvider,
        private InvoiceRepository $invoiceRepository,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
        private string $invoicesDirectory,
    ) {
    }

    public function issueInvoiceForOrder(Order $order, \DateTimeImmutable $now): Invoice
    {
        $user = $order->user;

        $subjectId = $this->ensureFakturoidSubject($user, $now);

        $fakturoidInvoice = $this->fakturoidClient->createInvoice($subjectId, $order);

        // Invoice is created after payment, so mark it as paid immediately
        if (null !== $order->paidAt) {
            $this->fakturoidClient->markInvoiceAsPaid($fakturoidInvoice->id, $order->paidAt);
        }

        $invoice = new Invoice(
            id: $this->identityProvider->next(),
            order: $order,
            user: $user,
            fakturoidInvoiceId: $fakturoidInvoice->id,
            invoiceNumber: $fakturoidInvoice->number,
            amount: $fakturoidInvoice->total,
            issuedAt: $now,
            createdAt: $now,
        );

        // Save invoice first so InvoiceCreated event fires (email sent even without PDF)
        $this->invoiceRepository->save($invoice);

        // PDF download is best-effort — invoice email is sent regardless
        try {
            $pdfContent = $this->fakturoidClient->downloadInvoicePdf($fakturoidInvoice->id);
            $pdfPath = $this->storePdf($invoice, $pdfContent);
            $invoice->attachPdf($pdfPath);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to download invoice PDF, email will be sent without attachment', [
                'invoice_id' => $fakturoidInvoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $invoice;
    }

    private function ensureFakturoidSubject(User $user, \DateTimeImmutable $now): int
    {
        if ($user->hasFakturoidSubject()) {
            /** @var int $fakturoidSubjectId */
            $fakturoidSubjectId = $user->fakturoidSubjectId;

            return $fakturoidSubjectId;
        }

        $subject = $this->fakturoidClient->createSubject($user);
        $user->setFakturoidSubjectId($subject->id, $now);
        $this->userRepository->save($user);

        return $subject->id;
    }

    private function storePdf(Invoice $invoice, string $content): string
    {
        $filename = sprintf(
            'invoice_%s_%s.pdf',
            $invoice->invoiceNumber,
            $invoice->createdAt->format('Ymd'),
        );

        $path = $this->invoicesDirectory.'/'.$filename;

        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $content);

        return $path;
    }
}
