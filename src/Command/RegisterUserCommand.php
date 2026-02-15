<?php

declare(strict_types=1);

namespace App\Command;

final readonly class RegisterUserCommand
{
    public function __construct(
        public string $email,
        public string $password,
        public string $firstName,
        public string $lastName,
        public ?string $companyName = null,
        public ?string $companyId = null,
        public ?string $companyVatId = null,
        public ?string $billingStreet = null,
        public ?string $billingCity = null,
        public ?string $billingPostalCode = null,
    ) {
    }
}
