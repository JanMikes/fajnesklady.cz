<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

/**
 * An admin attributing an incoming transfer to an order by hand (spec 091),
 * for the cases auto-matching cannot resolve: a mistyped variable symbol, no
 * symbol at all, or a third party paying on the customer's behalf.
 */
final readonly class PairBankTransactionCommand
{
    public function __construct(
        public Uuid $transactionId,
        public Uuid $orderId,
        public Uuid $adminId,
        /** Register the payer's account so future transfers from it auto-match. */
        public bool $rememberSenderAccount,
        public ?string $note,
    ) {
    }
}
