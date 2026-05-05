<?php

declare(strict_types=1);

namespace App\Event;

use App\Enum\AdvanceNoticeReason;
use Symfony\Component\Uid\Uuid;

/**
 * Fired when a customer needs the 7-business-day advance notice before a
 * recurring charge (Podmínky opakovaných plateb čl. V):
 *  - automatically when ≥6 months elapsed since last successful charge
 *    (daily cron — see SendRecurringPaymentAdvanceNoticeCommand);
 *  - manually when an admin changes recurring parameters (price, frequency)
 *    or otherwise needs to forewarn the customer.
 */
final readonly class RecurringPaymentAdvanceNoticeNeeded
{
    public function __construct(
        public Uuid $contractId,
        public AdvanceNoticeReason $reason,
        public \DateTimeImmutable $occurredOn,
        public ?int $newAmount = null,
        public ?string $adminNote = null,
    ) {
    }
}
