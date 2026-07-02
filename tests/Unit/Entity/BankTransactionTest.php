<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\BankTransaction;
use App\Entity\Order;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class BankTransactionTest extends TestCase
{
    private BankTransaction $bankTransaction;

    protected function setUp(): void
    {
        $this->bankTransaction = new BankTransaction(
            id: Uuid::v7(),
            fioTransactionId: '12345',
            amount: 50000,
            currency: 'CZK',
            variableSymbol: '1234567890',
            senderAccountNumber: '123456/0800',
            senderName: 'Jan Novák',
            transactionDate: new \DateTimeImmutable('2025-06-15'),
            comment: null,
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );
    }

    public function testInitialStatusIsUnmatched(): void
    {
        self::assertTrue($this->bankTransaction->isUnmatched());
        self::assertFalse($this->bankTransaction->isMatched());
        self::assertFalse($this->bankTransaction->isIgnored());
        self::assertFalse($this->bankTransaction->isAmountMismatch());
    }

    public function testPairToOrder(): void
    {
        $order = $this->createStub(Order::class);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $this->bankTransaction->pairToOrder($order, 'variable_symbol', null, $now);

        self::assertTrue($this->bankTransaction->isMatched());
        self::assertSame($order, $this->bankTransaction->pairedOrder);
        self::assertSame('variable_symbol', $this->bankTransaction->matchMethod);
        self::assertSame($now, $this->bankTransaction->pairedAt);
    }

    public function testMarkIgnored(): void
    {
        $admin = $this->createStub(User::class);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $this->bankTransaction->markIgnored($admin, 'Not relevant', $now);

        self::assertTrue($this->bankTransaction->isIgnored());
        self::assertSame('Not relevant', $this->bankTransaction->ignoreReason);
        self::assertSame($admin, $this->bankTransaction->pairedBy);
    }

    public function testMarkIgnoredWithNullReason(): void
    {
        $admin = $this->createStub(User::class);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $this->bankTransaction->markIgnored($admin, null, $now);

        self::assertTrue($this->bankTransaction->isIgnored());
        self::assertNull($this->bankTransaction->ignoreReason);
        self::assertSame($admin, $this->bankTransaction->pairedBy);
        self::assertSame($now, $this->bankTransaction->pairedAt);
    }

    public function testUnignore(): void
    {
        $admin = $this->createStub(User::class);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $this->bankTransaction->markIgnored($admin, 'Not relevant', $now);
        self::assertTrue($this->bankTransaction->isIgnored());

        $this->bankTransaction->unignore();
        self::assertTrue($this->bankTransaction->isUnmatched());
        self::assertNull($this->bankTransaction->ignoreReason);
    }

    public function testMarkAmountMismatch(): void
    {
        $order = $this->createStub(Order::class);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $this->bankTransaction->markAmountMismatch($order, 'variable_symbol', 60000, $now);

        self::assertTrue($this->bankTransaction->isAmountMismatch());
        self::assertSame($order, $this->bankTransaction->pairedOrder);
        self::assertSame(60000, $this->bankTransaction->expectedAmountInHaler);
    }

    public function testPromoteToMatched(): void
    {
        $order = $this->createStub(Order::class);
        $mismatchAt = new \DateTimeImmutable('2025-06-15 12:00:00');
        $promoteAt = new \DateTimeImmutable('2025-06-15 13:00:00');

        $this->bankTransaction->markAmountMismatch($order, 'variable_symbol', 60000, $mismatchAt);
        self::assertTrue($this->bankTransaction->isAmountMismatch());

        $this->bankTransaction->promoteToMatched($promoteAt);

        self::assertTrue($this->bankTransaction->isMatched());
        self::assertSame($promoteAt, $this->bankTransaction->pairedAt);
        self::assertSame($order, $this->bankTransaction->pairedOrder);
    }

    public function testPromoteToMatchedThrowsWhenNotMismatch(): void
    {
        $this->expectException(\DomainException::class);

        $this->bankTransaction->promoteToMatched(new \DateTimeImmutable('2025-06-15 12:00:00'));
    }
}
