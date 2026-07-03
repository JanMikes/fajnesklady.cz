<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class OrderBillingModeTest extends TestCase
{
    public function testDefaultBillingModeIsAutoRecurring(): void
    {
        $order = $this->createOrder(30);

        self::assertSame(BillingMode::AUTO_RECURRING, $order->billingMode);
    }

    public function testSetBillingModeChangesIt(): void
    {
        $order = $this->createOrder(30);

        $order->setBillingMode(BillingMode::MANUAL_RECURRING);

        self::assertSame(BillingMode::MANUAL_RECURRING, $order->billingMode);
    }

    public function testIsRecurringMatchesBillingModeShape(): void
    {
        // Pins the duplicated 28-day boundary between Order::isRecurring()
        // and BillingMode::isRecurring(). isRecurring(false) ⇒ ONE_TIME is
        // the correct billing mode; isRecurring(true) ⇒ AUTO or MANUAL.
        $shortFixedTerm = $this->createOrder(7);
        $longFixedTerm = $this->createOrder(45);
        $yearLongFixedTerm = $this->createOrder(365);

        self::assertFalse($shortFixedTerm->isRecurring());
        self::assertTrue($longFixedTerm->isRecurring());
        self::assertTrue($yearLongFixedTerm->isRecurring());
    }

    public function testIsPaidInUpfrontTranches(): void
    {
        // Spec 078 tranches: only upfront (ONE_TIME) rentals longer than
        // 12 monthly billing periods split into yearly tranches.
        self::assertTrue($this->createOrder(456, PaymentFrequency::ONE_TIME)->isPaidInUpfrontTranches()); // ~15 months
        self::assertFalse($this->createOrder(92, PaymentFrequency::ONE_TIME)->isPaidInUpfrontTranches()); // ~3 months
        self::assertFalse($this->createOrder(456, PaymentFrequency::MONTHLY)->isPaidInUpfrontTranches());
        self::assertFalse($this->createOrder(456, PaymentFrequency::YEARLY)->isPaidInUpfrontTranches());
    }

    public function testIsPaidInUpfrontTranchesFalseForExactlyTwelveMonths(): void
    {
        // createOrder() anchors startDate at 2025-06-16; +365 days lands exactly
        // on 2026-06-16 = startDate + 12 calendar months → single tranche.
        self::assertFalse($this->createOrder(365, PaymentFrequency::ONE_TIME)->isPaidInUpfrontTranches());
        // One day longer → a 13th monthly period exists → tranches.
        self::assertTrue($this->createOrder(366, PaymentFrequency::ONE_TIME)->isPaidInUpfrontTranches());
    }

    public function testDefaultManualBillingScheduleMatchesPlaceDefaults(): void
    {
        $order = $this->createOrder(30);

        self::assertSame(-7, $order->manualBillingOffsetInitial);
        self::assertSame(-2, $order->manualBillingOffsetReminder);
        self::assertSame(0, $order->manualBillingOffsetFinalDue);
        self::assertSame(3, $order->manualBillingOffsetOverdueFirst);
        self::assertSame(7, $order->manualBillingOffsetOverdueFinal);
    }

    public function testSetManualBillingScheduleSnapshotsAllFiveOffsets(): void
    {
        $order = $this->createOrder(30);

        $order->setManualBillingSchedule(-14, -5, 0, 1, 14);

        self::assertSame(-14, $order->manualBillingOffsetInitial);
        self::assertSame(-5, $order->manualBillingOffsetReminder);
        self::assertSame(0, $order->manualBillingOffsetFinalDue);
        self::assertSame(1, $order->manualBillingOffsetOverdueFirst);
        self::assertSame(14, $order->manualBillingOffsetOverdueFinal);
    }

    private function createOrder(int $durationDays, PaymentFrequency $paymentFrequency = PaymentFrequency::MONTHLY): Order
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $user = new User(Uuid::v7(), 'user@example.com', 'password', 'Test', 'User', $now);
        $place = new Place(
            id: Uuid::v7(),
            name: 'Place',
            address: 'A',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $now,
        );
        $type = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Type',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            defaultPricePerMonthLongTerm: 35000,
            defaultPricePerYear: 35000 * 12,
            createdAt: $now,
        );
        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $type,
            place: $place,
            createdAt: $now,
        );

        $startDate = $now->modify('+1 day');
        $endDate = $startDate->modify('+'.$durationDays.' days');

        return new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: $paymentFrequency,
            startDate: $startDate,
            endDate: $endDate,
            firstPaymentPrice: 35000,
            expiresAt: $now->modify('+7 days'),
            createdAt: $now,
        );
    }
}
