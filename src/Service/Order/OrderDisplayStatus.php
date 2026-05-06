<?php

declare(strict_types=1);

namespace App\Service\Order;

final readonly class OrderDisplayStatus
{
    public function __construct(
        public OrderDisplayStatusCase $case,
        public string $label,
        public string $variant,
        public ?string $description = null,
    ) {
    }
}
