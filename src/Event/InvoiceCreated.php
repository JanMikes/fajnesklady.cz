<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class InvoiceCreated
{
    public function __construct(
        public Uuid $invoiceId,
        public Uuid $orderId,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
