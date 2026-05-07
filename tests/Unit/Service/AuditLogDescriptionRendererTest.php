<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\AuditLog;
use App\Service\AuditLogDescriptionRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AuditLogDescriptionRendererTest extends TestCase
{
    private AuditLogDescriptionRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new AuditLogDescriptionRenderer();
    }

    public function testKnownEventReturnsCzechSentence(): void
    {
        $log = new AuditLog(
            id: Uuid::v7(),
            entityType: 'order',
            entityId: '00000000-0000-0000-0000-000000000001',
            eventType: 'paid',
            payload: [],
            user: null,
            ipAddress: null,
            createdAt: new \DateTimeImmutable('2026-01-15 12:00:00'),
        );

        self::assertSame('Objednávka zaplacena', $this->renderer->describe($log));
    }

    public function testStorageReleasedIncludesReason(): void
    {
        $log = new AuditLog(
            id: Uuid::v7(),
            entityType: 'storage',
            entityId: '00000000-0000-0000-0000-000000000002',
            eventType: 'released',
            payload: ['reason' => 'expirace smlouvy'],
            user: null,
            ipAddress: null,
            createdAt: new \DateTimeImmutable('2026-01-15 12:00:00'),
        );

        self::assertSame('Sklad uvolněn: expirace smlouvy', $this->renderer->describe($log));
    }

    public function testContractExpiringSoonIncludesDays(): void
    {
        $log = new AuditLog(
            id: Uuid::v7(),
            entityType: 'contract',
            entityId: '00000000-0000-0000-0000-000000000003',
            eventType: 'expiring_soon',
            payload: ['days_remaining' => 7],
            user: null,
            ipAddress: null,
            createdAt: new \DateTimeImmutable('2026-01-15 12:00:00'),
        );

        self::assertSame('Smlouva brzy vyprší (zbývá 7 dní)', $this->renderer->describe($log));
    }

    public function testUnknownEventFallsBackToEntityDotEvent(): void
    {
        $log = new AuditLog(
            id: Uuid::v7(),
            entityType: 'mystery',
            entityId: '00000000-0000-0000-0000-000000000004',
            eventType: 'never_seen',
            payload: [],
            user: null,
            ipAddress: null,
            createdAt: new \DateTimeImmutable('2026-01-15 12:00:00'),
        );

        self::assertSame('mystery — never_seen', $this->renderer->describe($log));
    }

    public function testNeverEmitsRawJson(): void
    {
        $log = new AuditLog(
            id: Uuid::v7(),
            entityType: 'order',
            entityId: '00000000-0000-0000-0000-000000000005',
            eventType: 'created',
            payload: ['user_id' => 'abc', 'storage_id' => 'def'],
            user: null,
            ipAddress: null,
            createdAt: new \DateTimeImmutable('2026-01-15 12:00:00'),
        );

        $description = $this->renderer->describe($log);

        self::assertStringStartsNotWith('{', $description);
        self::assertStringNotContainsString('"user_id"', $description);
    }
}
