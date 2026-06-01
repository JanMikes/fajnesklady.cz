<?php

declare(strict_types=1);

namespace App\Value;

use Symfony\Component\Uid\Uuid;

final readonly class UserListRow
{
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
    ) {
    }
}
