<?php

declare(strict_types=1);

namespace App\Service\Fakturoid;

use App\Entity\Contract;
use App\Entity\Fine;
use App\Entity\Order;
use App\Entity\SelfBillingInvoice;
use App\Entity\User;
use App\Enum\PaymentMethod;
use App\Value\FakturoidInvoice;
use App\Value\FakturoidSubject;

interface FakturoidClient
{
    public function createSubject(User $user): FakturoidSubject;

    public function updateSubject(int $subjectId, User $user): void;

    /**
     * Every customer invoice carries HOW it was paid ($paidVia) — Fakturoid
     * prints it on the PDF as "Způsob platby" — and, for a card payment, the
     * paying GoPay payment ID ($goPayPaymentId) as "Číslo objednávky", the
     * only field Fakturoid exports to ISDOC (OrderReference/ExternalOrderID).
     * It is how the accountant pairs GoPay payouts with invoices (spec 093).
     */
    public function createInvoice(int $subjectId, Order $order, PaymentMethod $paidVia, ?string $goPayPaymentId): FakturoidInvoice;

    /**
     * Invoice for the pre-existing onboarding debt (Order.onboardingDebtInHaler).
     * The amount is gross (vč. DPH), taxed like rent — see createInvoice.
     */
    public function createDebtInvoice(int $subjectId, Order $order, PaymentMethod $paidVia, ?string $goPayPaymentId): FakturoidInvoice;

    /**
     * Invoice for a paid smluvní pokuta. Unlike rent/debt, a contractual
     * penalty is not consideration for a taxable supply — vat_rate is 0 %.
     */
    public function createFineInvoice(int $subjectId, Fine $fine, PaymentMethod $paidVia, ?string $goPayPaymentId): FakturoidInvoice;

    public function createRecurringInvoice(int $subjectId, Contract $contract, int $amount, \DateTimeImmutable $billingDate, PaymentMethod $paidVia, ?string $goPayPaymentId, ?\DateTimeImmutable $periodStart = null, ?\DateTimeImmutable $periodEnd = null): FakturoidInvoice;

    /**
     * Credit note (opravný daňový doklad) for a refund: a Fakturoid
     * "correction" document linked to $correctedInvoiceId (Fakturoid
     * back-links the original). $amount is the positive refunded sum in
     * haléře (vč. DPH); the document itself carries it as a negative line.
     */
    public function createCreditNote(
        int $subjectId,
        int $correctedInvoiceId,
        int $amount,
        string $lineName,
        PaymentMethod $paidVia,
        ?string $goPayPaymentId,
        ?string $variableSymbol,
    ): FakturoidInvoice;

    public function downloadInvoicePdf(int $invoiceId): string;

    public function markInvoiceAsPaid(int $invoiceId, \DateTimeImmutable $paidAt): void;

    /**
     * Re-label an already issued invoice (backfill). Throws when Fakturoid
     * refuses — e.g. a locked document.
     */
    public function updateInvoicePaymentMethod(int $invoiceId, PaymentMethod $paidVia): void;

    /**
     * Stamp the paying GoPay payment ID on an already issued invoice
     * (backfill, spec 093). Throws when Fakturoid refuses — e.g. a locked
     * document.
     */
    public function updateInvoiceGoPayPaymentId(int $invoiceId, string $goPayPaymentId): void;

    /**
     * Create a self-billing invoice in Fakturoid.
     * Self-billing: platform issues invoice on behalf of the landlord (supplier).
     */
    public function createSelfBillingInvoice(int $subjectId, SelfBillingInvoice $invoice): FakturoidInvoice;
}
