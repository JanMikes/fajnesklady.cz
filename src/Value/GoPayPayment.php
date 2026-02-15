<?php

declare(strict_types=1);

namespace App\Value;

final readonly class GoPayPayment
{
    public function __construct(
        public string $id,
        public string $gwUrl,
        public string $state,
    ) {
    }
}
