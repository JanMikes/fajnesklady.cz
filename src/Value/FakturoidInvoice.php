<?php

declare(strict_types=1);

namespace App\Value;

final readonly class FakturoidInvoice
{
    public function __construct(
        public int $id,
        public string $number,
        public int $total,
    ) {
    }
}
