<?php

declare(strict_types=1);

namespace App\Value;

final readonly class AresResult
{
    public function __construct(
        public string $companyName,
        public string $companyId,
        public ?string $companyVatId,
        public string $street,
        public string $city,
        public string $postalCode,
    ) {
    }
}
