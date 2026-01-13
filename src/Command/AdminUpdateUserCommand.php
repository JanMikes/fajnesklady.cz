<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\UserRole;
use Symfony\Component\Uid\Uuid;

final readonly class AdminUpdateUserCommand
{
    public function __construct(
        public Uuid $userId,
        public string $firstName,
        public string $lastName,
        public ?string $phone,
        public ?string $companyName,
        public ?string $companyId,
        public ?string $companyVatId,
        public ?string $billingStreet,
        public ?string $billingCity,
        public ?string $billingPostalCode,
        public UserRole $role,
    ) {
    }
}
