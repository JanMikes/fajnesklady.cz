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
    ) {
    }
}
