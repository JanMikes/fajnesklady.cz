<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Per-(order, stage) idempotency row for `app:send-onboarding-payment-reminders`.
 * Mirrors {@see ManualPaymentRequest} but trimmed: a single onboarding has at most
 * two reminder stages (D+2, D+5), each fired once.
 */
#[ORM\Entity]
#[ORM\Table(name: 'onboarding_reminder_sent')]
#[ORM\UniqueConstraint(name: 'uniq_onboarding_reminder_order_stage', columns: ['order_id', 'stage'])]
class OnboardingReminderSent
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: Order::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) Order $order,
        #[ORM\Column(length: 20)]
        private(set) string $stage,
        #[ORM\Column]
        private(set) \DateTimeImmutable $sentAt,
    ) {
    }
}
