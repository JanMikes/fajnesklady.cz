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

readonly class InvoicingService
{
    public function __construct(
        private FakturoidClient $fakturoidClient,
        private ProvideIdentity $identityProvider,
        private InvoiceRepository $invoiceRepository,
        private UserRepository $userRepository,
        private string $invoicesDirectory,
    ) {
    }

    public function issueInvoiceForOrder(Order $order, \DateTimeImmutable $now): Invoice
    {
        $user = $order->user;

        $subjectId = $this->ensureFakturoidSubject($user, $now);

        $fakturoidInvoice = $this->fakturoidClient->createInvoice($subjectId, $order);

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

        $pdfContent = $this->fakturoidClient->downloadInvoicePdf($fakturoidInvoice->id);
        $pdfPath = $this->storePdf($invoice, $pdfContent);
        $invoice->attachPdf($pdfPath);

        $this->invoiceRepository->save($invoice);

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
