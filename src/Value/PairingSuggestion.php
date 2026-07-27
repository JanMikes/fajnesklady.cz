<?php

declare(strict_types=1);

namespace App\Value;

use App\Entity\Order;

/**
 * A guessed home for an unmatched bank transfer (admin bank payments list).
 * Display-only: the admin still walks through the manual pairing confirmation
 * with the full allocation plan — a suggestion never pairs anything by itself.
 */
final readonly class PairingSuggestion
{
    public function __construct(
        public Order $order,
        public string $reason,
    ) {
    }
}
