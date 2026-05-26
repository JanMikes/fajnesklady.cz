<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\FineType;
use Symfony\Component\Uid\Uuid;

final readonly class IssueFineCommand
{
    public function __construct(
        public Uuid $contractId,
        public FineType $type,
        public int $amountInHaler,
        public string $description,
        public Uuid $issuedById,
    ) {
    }
}
