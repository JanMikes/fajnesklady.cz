<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class OrderDebtTest extends TestCase
{
    public function testHasUnpaidDebtReturnsFalseWhenNoDebt(): void
    {
        $order = $this->createOrder();

        self::assertFalse($order->hasUnpaidDebt());
    }

    public function testHasUnpaidDebtReturnsTrueWhenDebtSet(): void
    {
        $order = $this->createOrder();
        $order->setOnboardingDebt(50000);

        self::assertTrue($order->hasUnpaidDebt());
    }

    public function testHasUnpaidDebtReturnsFalseWhenDebtPaid(): void
    {
        $order = $this->createOrder();
        $order->setOnboardingDebt(50000);
        $order->markDebtPaid(new \DateTimeImmutable('2025-06-15 12:00:00'));

        self::assertFalse($order->hasUnpaidDebt());
    }

    public function testHasDebtReturnsTrueWhenDebtSetRegardlessOfPayment(): void
    {
        $order = $this->createOrder();
        $order->setOnboardingDebt(50000);
        $order->markDebtPaid(new \DateTimeImmutable('2025-06-15 12:00:00'));

        self::assertTrue($order->hasDebt());
    }

    public function testHasDebtReturnsFalseWhenNoDebt(): void
    {
        $order = $this->createOrder();

        self::assertFalse($order->hasDebt());
    }

    public function testGetDebtAmountInCzkReturnsNullWhenNoDebt(): void
    {
        $order = $this->createOrder();

        self::assertNull($order->getDebtAmountInCzk());
    }

    public function testGetDebtAmountInCzkConvertsFromHaler(): void
    {
        $order = $this->createOrder();
        $order->setOnboardingDebt(50000);

        self::assertSame(500.0, $order->getDebtAmountInCzk());
    }

    public function testSetDebtGoPayPaymentId(): void
    {
        $order = $this->createOrder();
        $order->setDebtGoPayPaymentId('gopay-123');

        self::assertSame('gopay-123', $order->debtGoPayPaymentId);
    }

    public function testMarkDebtPaidSetsTimestamp(): void
    {
        $order = $this->createOrder();
        $order->setOnboardingDebt(50000);

        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $order->markDebtPaid($now);

        self::assertSame($now, $order->debtPaidAt);
    }

    public function testHasUnpaidDebtReturnsFalseForZeroDebt(): void
    {
        $order = $this->createOrder();
        $order->setOnboardingDebt(0);

        self::assertFalse($order->hasUnpaidDebt());
    }

    private function createOrder(): Order
    {
        $user = new User(Uuid::v7(), 'user@example.com', 'password', 'Test', 'User', new \DateTimeImmutable('2025-06-15 12:00:00'));
        $owner = new User(Uuid::v7(), 'owner@example.com', 'password', 'Test', 'Owner', new \DateTimeImmutable('2025-06-15 12:00:00'));

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Small Box',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
            owner: $owner,
        );

        return new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('2025-06-16'),
            endDate: new \DateTimeImmutable('2025-07-16'),
            firstPaymentPrice: 35000,
            expiresAt: new \DateTimeImmutable('2025-06-22'),
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );
    }
}
