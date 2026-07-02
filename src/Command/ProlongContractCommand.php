<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\PaymentMethod;
use Symfony\Component\Uid\Uuid;

/**
 * Prolong an existing contract in place (spec 077). This command is the
 * strategy seam: switching to a "create follow-up contract" model later means
 * swapping the handler — the controllers and CTAs stay untouched.
 *
 * @param ?PaymentMethod $switchTo BANK_TRANSFER switches a card contract to the
 *                                 manual bank track immediately; GOPAY is NOT
 *                                 handled here (the card setup is a separate,
 *                                 asynchronous payment flow); null keeps the
 *                                 current method
 */
final readonly class ProlongContractCommand
{
    public function __construct(
        public Uuid $contractId,
        public \DateTimeImmutable $newEndDate,
        public ?PaymentMethod $switchTo,
        public ?Uuid $actorId,
    ) {
    }
}
