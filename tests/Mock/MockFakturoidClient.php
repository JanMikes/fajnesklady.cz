<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use App\Entity\Order;
use App\Entity\SelfBillingInvoice;
use App\Entity\User;
use App\Service\Fakturoid\FakturoidClient;
use App\Value\FakturoidInvoice;
use App\Value\FakturoidSubject;

final class MockFakturoidClient implements FakturoidClient
{
    private int $nextSubjectId = 1000;
    private int $nextInvoiceId = 2000;

    /** @var array<int, FakturoidSubject> */
    private array $createdSubjects = [];

    /** @var array<int, FakturoidInvoice> */
    private array $createdInvoices = [];

    private string $pdfContent = '%PDF-1.4 mock content';

    public function createSubject(User $user): FakturoidSubject
    {
        $subject = new FakturoidSubject(
            id: $this->nextSubjectId++,
            name: $user->companyName ?? $user->fullName,
        );

        $this->createdSubjects[$subject->id] = $subject;

        return $subject;
    }

    public function createInvoice(int $subjectId, Order $order): FakturoidInvoice
    {
        $invoiceId = $this->nextInvoiceId++;
        $invoice = new FakturoidInvoice(
            id: $invoiceId,
            number: 'FV-'.date('Y').'-'.str_pad((string) $invoiceId, 4, '0', STR_PAD_LEFT),
            total: $order->totalPrice,
        );

        $this->createdInvoices[$invoice->id] = $invoice;

        return $invoice;
    }

    public function downloadInvoicePdf(int $invoiceId): string
    {
        return $this->pdfContent;
    }

    public function createSelfBillingInvoice(int $subjectId, SelfBillingInvoice $selfBillingInvoice): FakturoidInvoice
    {
        $invoiceId = $this->nextInvoiceId++;
        $invoice = new FakturoidInvoice(
            id: $invoiceId,
            number: $selfBillingInvoice->invoiceNumber,
            total: $selfBillingInvoice->netAmount,
        );

        $this->createdInvoices[$invoice->id] = $invoice;

        return $invoice;
    }

    public function willReturnPdf(string $content): void
    {
        $this->pdfContent = $content;
    }

    /**
     * @return array<int, FakturoidSubject>
     */
    public function getCreatedSubjects(): array
    {
        return $this->createdSubjects;
    }

    /**
     * @return array<int, FakturoidInvoice>
     */
    public function getCreatedInvoices(): array
    {
        return $this->createdInvoices;
    }

    public function reset(): void
    {
        $this->nextSubjectId = 1000;
        $this->nextInvoiceId = 2000;
        $this->createdSubjects = [];
        $this->createdInvoices = [];
        $this->pdfContent = '%PDF-1.4 mock content';
    }
}
