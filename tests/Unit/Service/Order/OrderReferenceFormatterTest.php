<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Order;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Service\Order\OrderReferenceFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class OrderReferenceFormatterTest extends TestCase
{
    public function testFormatReturnsYmdUuid8UpperCaseFromOrder(): void
    {
        $order = $this->createOrder(
            Uuid::fromString('019e4643-0000-7000-8000-000000000000'),
            new \DateTimeImmutable('2026-06-01 09:15:00'),
        );

        $reference = (new OrderReferenceFormatter())->format($order);

        $this->assertSame('2026-0601-019E4643', $reference);
    }

    private function createOrder(Uuid $id, \DateTimeImmutable $createdAt): Order
    {
        $user = new User(
            Uuid::v7(),
            'tenant@example.com',
            'password',
            'Jan',
            'Novák',
            $createdAt,
        );

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test',
            address: 'Testovací 123',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $createdAt,
        );

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Small Box',
            innerWidth: 100,
            innerHeight: 200,
            innerLength: 150,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            defaultPricePerMonthLongTerm: 35000,
            defaultPricePerYear: 35000 * 12,
            createdAt: $createdAt,
        );

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $createdAt,
        );

        return new Order(
            id: $id,
            user: $user,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $createdAt,
            endDate: $createdAt->modify('+12 months'),
            firstPaymentPrice: 35000,
            expiresAt: $createdAt->modify('+7 days'),
            createdAt: $createdAt,
        );
    }
}
