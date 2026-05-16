<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * Centralises the "legal pack" attachments shared by both the order-placed
 * e-mail (step 1, pre-payment) and the rental-activated e-mail (step 2,
 * post-payment): signed contract, per-order VOP, poučení spotřebitele,
 * podmínky opakovaných plateb.
 *
 * Both touchpoints carry byte-identical files so the customer has the same
 * legal artefacts available in whichever inbox they look in first.
 *
 * The interface exists so handler unit tests can stub the bundle; the
 * production implementation lives in {@see OrderEmailAttachmentsService},
 * which is exercised end-to-end by OrderEmailAttachmentsTest.
 */
interface OrderEmailAttachments
{
    /**
     * @return array{hasContract: bool, hasVop: bool, hasConsumerNotice: bool, hasRecurringTerms: bool}
     */
    public function attachLegalDocuments(TemplatedEmail $email, Order $order): array;
}
