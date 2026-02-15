<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class ApprovePlaceAccessRequestCommand
{
    public function __construct(
        public Uuid $requestId,
        public Uuid $approvedById,
    ) {
    }
}
