<?php

declare(strict_types=1);

namespace App\Service\Order;

use App\Entity\Order;

/**
 * Single source of truth for the canonical customer-facing order reference.
 *
 * Format `Y-md-UUID8` (upper-case), derived from the order's createdAt and id —
 * so it equals the ${CONTRACT_NUMBER} printed on the signed contract DOCX (the
 * legally meaningful artefact). Reused by every customer touchpoint (e-mails,
 * public status page) and the admin grids so one number is shown everywhere.
 */
final readonly class OrderReferenceFormatter
{
    public function format(Order $order): string
    {
        return sprintf(
            '%s-%s-%s',
            $order->createdAt->format('Y'),
            $order->createdAt->format('md'),
            strtoupper(substr($order->id->toRfc4122(), 0, 8)),
        );
    }
}
