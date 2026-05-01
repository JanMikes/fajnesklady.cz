<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EmailLogStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'email_log')]
#[ORM\Index(columns: ['attempted_at'], name: 'email_log_attempted_at_idx')]
#[ORM\Index(columns: ['status'], name: 'email_log_status_idx')]
class EmailLog
{
    /**
     * @param list<array{email: string, name: ?string}>                    $toAddresses
     * @param ?list<array{email: string, name: ?string}>                   $ccAddresses
     * @param ?list<array{email: string, name: ?string}>                   $bccAddresses
     * @param ?list<array{email: string, name: ?string}>                   $replyToAddresses
     * @param ?list<array{name: string, sizeBytes: int, mimeType: string}> $attachments
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\Column]
        private(set) \DateTimeImmutable $attemptedAt,
        #[ORM\Column(length: 10, enumType: EmailLogStatus::class)]
        private(set) EmailLogStatus $status,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private(set) ?string $errorMessage,
        #[ORM\Column(length: 255)]
        private(set) string $fromEmail,
        #[ORM\Column(length: 255, nullable: true)]
        private(set) ?string $fromName,
        #[ORM\Column(type: Types::JSONB)]
        private(set) array $toAddresses,
        #[ORM\Column(type: Types::JSONB, nullable: true)]
        private(set) ?array $ccAddresses,
        #[ORM\Column(type: Types::JSONB, nullable: true)]
        private(set) ?array $bccAddresses,
        #[ORM\Column(type: Types::JSONB, nullable: true)]
        private(set) ?array $replyToAddresses,
        #[ORM\Column(type: Types::TEXT)]
        private(set) string $subject,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private(set) ?string $htmlBody,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private(set) ?string $textBody,
        #[ORM\Column(length: 255, nullable: true)]
        private(set) ?string $templateName,
        #[ORM\Column(type: Types::JSONB, nullable: true)]
        private(set) ?array $attachments,
        #[ORM\Column(length: 255, nullable: true)]
        private(set) ?string $messageId,
    ) {
    }
}
