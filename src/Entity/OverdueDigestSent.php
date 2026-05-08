<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'overdue_digest_sent')]
#[ORM\UniqueConstraint(name: 'uniq_overdue_digest_admin_date', columns: ['admin_id', 'date'])]
class OverdueDigestSent
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) User $admin,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private(set) \DateTimeImmutable $date,
        #[ORM\Column]
        private(set) \DateTimeImmutable $sentAt,
        #[ORM\Column]
        private(set) int $overdueCount,
        #[ORM\Column]
        private(set) int $totalAmount,
    ) {
    }
}
