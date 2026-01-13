<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class UpdateBillingInfoCommand
{
    public function __construct(
        public Uuid $userId,
        public ?string $companyName,
        public ?string $companyId,
        public ?string $companyVatId,
        public ?string $billingStreet,
        public ?string $billingCity,
        public ?string $billingPostalCode,
    ) {
    }
}
