<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\LandlordInvoiceSequence;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class LandlordInvoiceSequenceTest extends TestCase
{
    private function createLandlord(?string $selfBillingPrefix = 'P001'): User
    {
        $user = new User(
            id: Uuid::v7(),
            email: 'landlord@example.com',
            password: 'password',
            firstName: 'Test',
            lastName: 'Landlord',
            createdAt: new \DateTimeImmutable(),
        );

        if (null !== $selfBillingPrefix) {
            $user->setSelfBillingPrefix($selfBillingPrefix, new \DateTimeImmutable());
        }

        return $user;
    }

    public function testFormatInvoiceNumberFirstInvoice(): void
    {
        $landlord = $this->createLandlord('P001');
        $sequence = new LandlordInvoiceSequence(
            id: Uuid::v7(),
            landlord: $landlord,
            year: 2026,
        );

        // lastNumber starts at 0, so next is 1
        $invoiceNumber = $sequence->formatInvoiceNumber();

        $this->assertSame('P001-2026-0001', $invoiceNumber);
    }

    public function testFormatInvoiceNumberAfterIncrement(): void
    {
        $landlord = $this->createLandlord('P001');
        $sequence = new LandlordInvoiceSequence(
            id: Uuid::v7(),
            landlord: $landlord,
            year: 2026,
        );

        // Get first number
        $first = $sequence->formatInvoiceNumber();
        $this->assertSame('P001-2026-0001', $first);

        // Increment and get second number
        $sequence->incrementNumber();
        $second = $sequence->formatInvoiceNumber();
        $this->assertSame('P001-2026-0002', $second);
    }

    public function testFormatInvoiceNumberDifferentPrefix(): void
    {
        $landlord = $this->createLandlord('P050');
        $sequence = new LandlordInvoiceSequence(
            id: Uuid::v7(),
            landlord: $landlord,
            year: 2026,
        );

        $invoiceNumber = $sequence->formatInvoiceNumber();

        $this->assertSame('P050-2026-0001', $invoiceNumber);
    }

    public function testFormatInvoiceNumberDifferentYear(): void
    {
        $landlord = $this->createLandlord('P001');
        $sequence = new LandlordInvoiceSequence(
            id: Uuid::v7(),
            landlord: $landlord,
            year: 2027,
        );

        $invoiceNumber = $sequence->formatInvoiceNumber();

        $this->assertSame('P001-2027-0001', $invoiceNumber);
    }

    public function testFormatInvoiceNumberLargeSequenceNumber(): void
    {
        $landlord = $this->createLandlord('P001');
        $sequence = new LandlordInvoiceSequence(
            id: Uuid::v7(),
            landlord: $landlord,
            year: 2026,
        );

        // Simulate many invoices
        for ($i = 0; $i < 99; ++$i) {
            $sequence->incrementNumber();
        }

        // lastNumber is now 99, next is 100
        $invoiceNumber = $sequence->formatInvoiceNumber();

        $this->assertSame('P001-2026-0100', $invoiceNumber);
    }

    public function testFormatInvoiceNumberThrowsExceptionWhenNoPrefix(): void
    {
        $landlord = $this->createLandlord(null); // No prefix
        $sequence = new LandlordInvoiceSequence(
            id: Uuid::v7(),
            landlord: $landlord,
            year: 2026,
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must have a selfBillingPrefix');

        $sequence->formatInvoiceNumber();
    }

    public function testGetNextNumber(): void
    {
        $landlord = $this->createLandlord('P001');
        $sequence = new LandlordInvoiceSequence(
            id: Uuid::v7(),
            landlord: $landlord,
            year: 2026,
        );

        // Initial state: lastNumber = 0
        $this->assertSame(1, $sequence->getNextNumber());

        $sequence->incrementNumber();
        $this->assertSame(2, $sequence->getNextNumber());

        $sequence->incrementNumber();
        $this->assertSame(3, $sequence->getNextNumber());
    }

    public function testIncrementNumber(): void
    {
        $landlord = $this->createLandlord('P001');
        $sequence = new LandlordInvoiceSequence(
            id: Uuid::v7(),
            landlord: $landlord,
            year: 2026,
        );

        $this->assertSame(0, $sequence->lastNumber);

        $sequence->incrementNumber();
        $this->assertSame(1, $sequence->lastNumber);

        $sequence->incrementNumber();
        $this->assertSame(2, $sequence->lastNumber);
    }
}
