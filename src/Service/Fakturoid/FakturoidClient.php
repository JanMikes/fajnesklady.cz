<?php

declare(strict_types=1);

namespace App\Service\Fakturoid;

use App\Entity\Order;
use App\Entity\SelfBillingInvoice;
use App\Entity\User;
use App\Value\FakturoidInvoice;
use App\Value\FakturoidSubject;

interface FakturoidClient
{
    public function createSubject(User $user): FakturoidSubject;

    public function createInvoice(int $subjectId, Order $order): FakturoidInvoice;

    public function downloadInvoicePdf(int $invoiceId): string;

    /**
     * Create a self-billing invoice in Fakturoid.
     * Self-billing: platform issues invoice on behalf of the landlord (supplier).
     */
    public function createSelfBillingInvoice(int $subjectId, SelfBillingInvoice $invoice): FakturoidInvoice;
}
