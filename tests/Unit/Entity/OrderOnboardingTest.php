<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentMethod;
use App\Enum\RentalType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class OrderOnboardingTest extends TestCase
{
    public function testMarkAsAdminCreated(): void
    {
        $order = $this->createOrder();

        $this->assertNull($order->isAdminCreated);

        $order->markAsAdminCreated();

        $this->assertTrue($order->isAdminCreated);
    }

    public function testSetSigningToken(): void
    {
        $order = $this->createOrder();

        $this->assertNull($order->signingToken);

        $order->setSigningToken('abc123-token');

        $this->assertSame('abc123-token', $order->signingToken);
    }

    public function testClearSigningToken(): void
    {
        $order = $this->createOrder();
        $order->setSigningToken('abc123-token');

        $this->assertSame('abc123-token', $order->signingToken);

        $order->clearSigningToken();

        $this->assertNull($order->signingToken);
    }

    public function testSetPaymentMethodExternal(): void
    {
        $order = $this->createOrder();

        $this->assertNull($order->paymentMethod);

        $order->setPaymentMethod(PaymentMethod::EXTERNAL);

        $this->assertSame(PaymentMethod::EXTERNAL, $order->paymentMethod);
    }

    public function testSetPaymentMethodGoPay(): void
    {
        $order = $this->createOrder();

        $order->setPaymentMethod(PaymentMethod::GOPAY);

        $this->assertSame(PaymentMethod::GOPAY, $order->paymentMethod);
    }

    public function testSetOnboardingBillingTermsWritesBothFields(): void
    {
        $order = $this->createOrder();

        $this->assertNull($order->individualMonthlyAmount);
        $this->assertNull($order->paidThroughDate);

        $paidThrough = new \DateTimeImmutable('2026-12-31');
        $order->setOnboardingBillingTerms(80_000, $paidThrough);

        $this->assertSame(80_000, $order->individualMonthlyAmount);
        $this->assertEquals($paidThrough, $order->paidThroughDate);
    }

    public function testSetOnboardingBillingTermsAcceptsZeroForFreeContracts(): void
    {
        $order = $this->createOrder();

        $order->setOnboardingBillingTerms(0, null);

        $this->assertSame(0, $order->individualMonthlyAmount);
        $this->assertNull($order->paidThroughDate);
    }

    public function testExtendExpiration(): void
    {
        $order = $this->createOrder();
        $originalExpiresAt = $order->expiresAt;

        $newExpiresAt = new \DateTimeImmutable('2025-06-15 12:00:00');
        $order->extendExpiration($newExpiresAt);

        $this->assertSame($newExpiresAt, $order->expiresAt);
        $this->assertNotSame($originalExpiresAt, $order->expiresAt);
    }

    private function createOrder(): Order
    {
        $user = new User(Uuid::v7(), 'user@example.com', 'password', 'Test', 'User', new \DateTimeImmutable());
        $owner = new User(Uuid::v7(), 'owner@example.com', 'password', 'Test', 'Owner', new \DateTimeImmutable());

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable(),
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
            defaultPricePerMonthLongTerm: 35000,
            defaultPricePerYear: 35000 * 12,
            createdAt: new \DateTimeImmutable(),
        );

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
            owner: $owner,
        );

        return new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('+1 day'),
            endDate: new \DateTimeImmutable('+30 days'),
            firstPaymentPrice: 35000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable(),
        );
    }
}
