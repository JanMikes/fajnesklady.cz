<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Contract;

/**
 * Create the ON_DEMAND setup charge for the prolongation bank→card switch
 * (spec 077). The webhook completes the switch once the charge is PAID.
 */
final readonly class InitiateCardSetupCommand
{
    public function __construct(
        public Contract $contract,
        public string $returnUrl,
        public string $notificationUrl,
    ) {
    }
}
