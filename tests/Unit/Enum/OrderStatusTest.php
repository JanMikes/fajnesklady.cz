<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\OrderStatus;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    public function testCompletedIsTerminal(): void
    {
        $this->assertTrue(OrderStatus::COMPLETED->isTerminal());
    }

    public function testCancelledIsTerminal(): void
    {
        $this->assertTrue(OrderStatus::CANCELLED->isTerminal());
    }

    public function testExpiredIsTerminal(): void
    {
        $this->assertTrue(OrderStatus::EXPIRED->isTerminal());
    }

    public function testCreatedIsNotTerminal(): void
    {
        $this->assertFalse(OrderStatus::CREATED->isTerminal());
    }

    public function testReservedIsNotTerminal(): void
    {
        $this->assertFalse(OrderStatus::RESERVED->isTerminal());
    }

    public function testAwaitingPaymentIsNotTerminal(): void
    {
        $this->assertFalse(OrderStatus::AWAITING_PAYMENT->isTerminal());
    }

    public function testPaidIsNotTerminal(): void
    {
        $this->assertFalse(OrderStatus::PAID->isTerminal());
    }

    public function testValuesReturnsAllStatuses(): void
    {
        $values = OrderStatus::values();

        $this->assertCount(7, $values);
        $this->assertContains('created', $values);
        $this->assertContains('reserved', $values);
        $this->assertContains('awaiting_payment', $values);
        $this->assertContains('paid', $values);
        $this->assertContains('completed', $values);
        $this->assertContains('cancelled', $values);
        $this->assertContains('expired', $values);
    }
}
