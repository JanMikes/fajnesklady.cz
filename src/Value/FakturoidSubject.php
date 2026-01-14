<?php

declare(strict_types=1);

namespace App\Value;

final readonly class FakturoidSubject
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }
}
