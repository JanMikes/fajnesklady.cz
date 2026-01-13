<?php

declare(strict_types=1);

namespace App\Command;

final readonly class RegisterLandlordCommand
{
    public function __construct(
        public string $email,
        public string $password,
        public string $firstName,
        public string $lastName,
        public ?string $phone,
        public string $companyId,
        public string $companyName,
        public ?string $companyVatId,
        public string $billingStreet,
        public string $billingCity,
        public string $billingPostalCode,
    ) {
    }
}
