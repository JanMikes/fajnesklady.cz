<?php

declare(strict_types=1);

namespace App\Value;

use App\Service\Customer\CustomerDisplayName;
use Symfony\Component\Uid\Uuid;

final readonly class UserListRow
{
    /**
     * Company name when the customer ordered under a company, otherwise the
     * personal name — same precedence as {@see \App\Entity\User::$displayName}.
     */
    public string $displayName;

    public bool $isCompany;

    /**
     * @param array<string> $roles
     */
    public function __construct(
        public Uuid $id,
        public string $fullName,
        public string $email,
        public ?string $phone,
        public array $roles,
        public bool $isVerified,
        public bool $isDeactivated,
        public \DateTimeImmutable $createdAt,
        public int $activeCount,
        public int $totalCount,
        public int $mrrInHaler,
        public int $yrrInHaler,
        public bool $isOverdue,
        public bool $isOnboarded,
        public ?string $companyName = null,
        public ?string $companyId = null,
    ) {
        $this->isCompany = CustomerDisplayName::isCompany($companyName);
        $this->displayName = CustomerDisplayName::resolve($fullName, $companyName);
    }
}
