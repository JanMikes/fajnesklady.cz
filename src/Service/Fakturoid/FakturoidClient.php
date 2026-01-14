<?php

declare(strict_types=1);

namespace App\Service\Fakturoid;

use App\Entity\Order;
use App\Entity\User;
use App\Value\FakturoidInvoice;
use App\Value\FakturoidSubject;

interface FakturoidClient
{
    public function createSubject(User $user): FakturoidSubject;

    public function createInvoice(int $subjectId, Order $order): FakturoidInvoice;

    public function downloadInvoicePdf(int $invoiceId): string;
}
