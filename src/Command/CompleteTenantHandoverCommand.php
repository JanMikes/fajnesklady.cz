<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class CompleteTenantHandoverCommand
{
    public function __construct(
        public Uuid $handoverProtocolId,
        public string $comment,
    ) {
    }
}
