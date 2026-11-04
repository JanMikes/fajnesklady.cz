<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use App\Entity\Contract;
use App\Entity\Fine;
use App\Entity\Order;
use App\Entity\SelfBillingInvoice;
use App\Entity\User;
use App\Enum\PaymentMethod;
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

    /** @var array<int, PaymentMethod> invoice id => method it was issued / re-labelled with */
    private array $invoicePaymentMethods = [];

    /** @var list<int> invoice ids whose update must fail like a locked document */
    private array $lockedInvoiceIds = [];

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

    public function updateSubject(int $subjectId, User $user): void
    {
        // No-op in mock
    }

    public function createInvoice(int $subjectId, Order $order, PaymentMethod $paidVia): FakturoidInvoice
    {
        $invoiceId = $this->nextInvoiceId++;
        $invoice = new FakturoidInvoice(
            id: $invoiceId,
            number: 'FV-'.date('Y').'-'.str_pad((string) $invoiceId, 4, '0', STR_PAD_LEFT),
            total: $order->firstPaymentPrice,
        );

        $this->createdInvoices[$invoice->id] = $invoice;
        $this->invoicePaymentMethods[$invoice->id] = $paidVia;

        return $invoice;
    }

    public function createDebtInvoice(int $subjectId, Order $order, PaymentMethod $paidVia): FakturoidInvoice
    {
        $invoiceId = $this->nextInvoiceId++;
        $invoice = new FakturoidInvoice(
            id: $invoiceId,
            number: 'FV-'.date('Y').'-'.str_pad((string) $invoiceId, 4, '0', STR_PAD_LEFT),
            total: $order->onboardingDebtInHaler ?? 0,
        );

        $this->createdInvoices[$invoice->id] = $invoice;
        $this->invoicePaymentMethods[$invoice->id] = $paidVia;

        return $invoice;
    }

    public function createFineInvoice(int $subjectId, Fine $fine, PaymentMethod $paidVia): FakturoidInvoice
    {
        $invoiceId = $this->nextInvoiceId++;
        $invoice = new FakturoidInvoice(
            id: $invoiceId,
            number: 'FV-'.date('Y').'-'.str_pad((string) $invoiceId, 4, '0', STR_PAD_LEFT),
            total: $fine->amountInHaler,
        );

        $this->createdInvoices[$invoice->id] = $invoice;
        $this->invoicePaymentMethods[$invoice->id] = $paidVia;

        return $invoice;
    }

    public function createRecurringInvoice(int $subjectId, Contract $contract, int $amount, \DateTimeImmutable $billingDate, PaymentMethod $paidVia, ?\DateTimeImmutable $periodStart = null, ?\DateTimeImmutable $periodEnd = null): FakturoidInvoice
    {
        $invoiceId = $this->nextInvoiceId++;
        $invoice = new FakturoidInvoice(
            id: $invoiceId,
            number: 'FV-'.date('Y').'-'.str_pad((string) $invoiceId, 4, '0', STR_PAD_LEFT),
            total: $amount,
        );

        $this->createdInvoices[$invoice->id] = $invoice;
        $this->invoicePaymentMethods[$invoice->id] = $paidVia;

        return $invoice;
    }

    public function markInvoiceAsPaid(int $invoiceId, \DateTimeImmutable $paidAt): void
    {
        // No-op in mock
    }

    public function updateInvoicePaymentMethod(int $invoiceId, PaymentMethod $paidVia): void
    {
        if (in_array($invoiceId, $this->lockedInvoiceIds, true)) {
            throw new \RuntimeException(sprintf('Invoice %d is locked (document_locked).', $invoiceId));
        }

        $this->invoicePaymentMethods[$invoiceId] = $paidVia;
    }

    public function willRefuseUpdateOf(int $invoiceId): void
    {
        $this->lockedInvoiceIds[] = $invoiceId;
    }

    public function paymentMethodOf(int $invoiceId): ?PaymentMethod
    {
        return $this->invoicePaymentMethods[$invoiceId] ?? null;
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
        $this->invoicePaymentMethods = [];
        $this->lockedInvoiceIds = [];
        $this->pdfContent = '%PDF-1.4 mock content';
    }
}
