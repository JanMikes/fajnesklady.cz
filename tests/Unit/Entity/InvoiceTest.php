<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class InvoiceTest extends TestCase
{
    public function testEmailedAtIsNullByDefault(): void
    {
        $invoice = $this->createInvoice();

        $this->assertNull($invoice->emailedAt);
        $this->assertFalse($invoice->isEmailed());
    }

    public function testMarkEmailedSetsTimestamp(): void
    {
        $invoice = $this->createInvoice();
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $invoice->markEmailed($now);

        $this->assertSame($now, $invoice->emailedAt);
        $this->assertTrue($invoice->isEmailed());
    }

    public function testMarkEmailedIsIdempotent(): void
    {
        // Once an invoice has been delivered, a second markEmailed call must
        // not move the timestamp — the original send is the authoritative
        // delivery moment for auditing / debugging purposes.
        $invoice = $this->createInvoice();
        $firstSend = new \DateTimeImmutable('2025-06-15 12:00:00');
        $laterCall = new \DateTimeImmutable('2025-06-16 09:30:00');

        $invoice->markEmailed($firstSend);
        $invoice->markEmailed($laterCall);

        $this->assertSame($firstSend, $invoice->emailedAt);
    }

    private function createInvoice(): Invoice
    {
        $place = new Place(
            id: Uuid::v7(),
            name: 'Test',
            address: 'Addr',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable(),
        );

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Small',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 1000,
            defaultPricePerMonth: 3500,
            defaultPricePerMonthLongTerm: 3500,
            defaultPricePerYear: 3500 * 12,
            createdAt: new \DateTimeImmutable(),
        );

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0, 'normalized' => true],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );

        $user = new User(Uuid::v7(), 'tenant@example.com', 'pw', 'Jan', 'Novak', new \DateTimeImmutable());

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('2025-06-15'),
            endDate: new \DateTimeImmutable('2025-07-15'),
            firstPaymentPrice: 35000,
            expiresAt: new \DateTimeImmutable('2025-06-22'),
            createdAt: new \DateTimeImmutable('2025-06-15'),
        );

        return new Invoice(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            fakturoidInvoiceId: 12345,
            invoiceNumber: '2025-0001',
            amount: 35000,
            issuedAt: new \DateTimeImmutable('2025-06-15'),
            createdAt: new \DateTimeImmutable('2025-06-15'),
        );
    }
}
