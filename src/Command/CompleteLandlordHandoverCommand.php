<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class CompleteLandlordHandoverCommand
{
    public function __construct(
        public Uuid $handoverProtocolId,
        public string $comment,
        public ?string $newLockCode,
        /**
         * When set, the tenant side is resolved in the same transaction on
         * behalf of the tenant (landlord/admin took the unit over in person) —
         * the tenant no longer has to fill anything. Holds the acting user.
         */
        public ?Uuid $resolveTenantSideById = null,
    ) {
    }
}
