<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Index(columns: ['entity_type', 'entity_id'], name: 'audit_entity_idx')]
class AuditLog
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\Column(length: 100)]
        private(set) string $entityType,
        #[ORM\Column(length: 36)]
        private(set) string $entityId,
        #[ORM\Column(length: 50)]
        private(set) string $eventType,
        #[ORM\Column(type: Types::JSON)]
        private(set) array $payload,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true)]
        private(set) ?User $user,
        #[ORM\Column(length: 45, nullable: true)]
        private(set) ?string $ipAddress,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
    }
}
