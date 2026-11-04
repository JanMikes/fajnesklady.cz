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
     * prints it on the PDF as "Způsob platby".
     */
    public function createInvoice(int $subjectId, Order $order, PaymentMethod $paidVia): FakturoidInvoice;

    /**
     * Invoice for the pre-existing onboarding debt (Order.onboardingDebtInHaler).
     * The amount is gross (vč. DPH), taxed like rent — see createInvoice.
     */
    public function createDebtInvoice(int $subjectId, Order $order, PaymentMethod $paidVia): FakturoidInvoice;

    /**
     * Invoice for a paid smluvní pokuta. Unlike rent/debt, a contractual
     * penalty is not consideration for a taxable supply — vat_rate is 0 %.
     */
    public function createFineInvoice(int $subjectId, Fine $fine, PaymentMethod $paidVia): FakturoidInvoice;

    public function createRecurringInvoice(int $subjectId, Contract $contract, int $amount, \DateTimeImmutable $billingDate, PaymentMethod $paidVia, ?\DateTimeImmutable $periodStart = null, ?\DateTimeImmutable $periodEnd = null): FakturoidInvoice;

    public function downloadInvoicePdf(int $invoiceId): string;

    public function markInvoiceAsPaid(int $invoiceId, \DateTimeImmutable $paidAt): void;

    /**
     * Re-label an already issued invoice (backfill). Throws when Fakturoid
     * refuses — e.g. a locked document.
     */
    public function updateInvoicePaymentMethod(int $invoiceId, PaymentMethod $paidVia): void;

    /**
     * Create a self-billing invoice in Fakturoid.
     * Self-billing: platform issues invoice on behalf of the landlord (supplier).
     */
    public function createSelfBillingInvoice(int $subjectId, SelfBillingInvoice $invoice): FakturoidInvoice;
}
