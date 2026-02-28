<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;
use App\Enum\SigningMethod;

final readonly class SignOrderCommand
{
    public function __construct(
        public Order $order,
        public string $signatureDataUrl,
        public SigningMethod $signingMethod,
        public ?string $typedName = null,
        public ?string $styleId = null,
    ) {
    }
}
