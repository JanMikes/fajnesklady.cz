<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use App\Service\PriceCalculator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class PriceCalculatorTest extends TestCase
{
    private PriceCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new PriceCalculator();
    }

    private function createStorageType(int $pricePerWeek, int $pricePerMonth): StorageType
    {
        return new StorageType(
            id: Uuid::v7(),
            place: $this->createPlace(),
            name: 'Test Type',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: $pricePerWeek,
            defaultPricePerMonth: $pricePerMonth,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createPlace(): Place
    {
        return new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createStorage(StorageType $storageType, Place $place): Storage
    {
        return new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );
    }

    public function testSevenDaysEqualsOneWeek(): void
    {
        // 7 days = 1 × weekly rate
        $storageType = $this->createStorageType(10000, 35000); // 100 Kč/week, 350 Kč/month
        $startDate = new \DateTimeImmutable('2024-01-01');
        $endDate = new \DateTimeImmutable('2024-01-08'); // 7 days

        $price = $this->calculator->calculatePrice($storageType, $startDate, $endDate);

        $this->assertSame(10000, $price);
    }

    public function testTenDaysEqualsOneWeekPlusThreeDays(): void
    {
        // 10 days = 1 × weekly + 3 × (weekly/7)
        $storageType = $this->createStorageType(7000, 28000); // 70 Kč/week = 10 Kč/day
        $startDate = new \DateTimeImmutable('2024-01-01');
        $endDate = new \DateTimeImmutable('2024-01-11'); // 10 days

        $price = $this->calculator->calculatePrice($storageType, $startDate, $endDate);

        // 1 week (7000) + 3 days * (7000/7) = 7000 + 3000 = 10000
        $this->assertSame(10000, $price);
    }

    public function testFourteenDaysEqualsTwoWeeks(): void
    {
        // 14 days = 2 × weekly rate
        $storageType = $this->createStorageType(10000, 35000);
        $startDate = new \DateTimeImmutable('2024-01-01');
        $endDate = new \DateTimeImmutable('2024-01-15'); // 14 days

        $price = $this->calculator->calculatePrice($storageType, $startDate, $endDate);

        $this->assertSame(20000, $price);
    }

    public function testTwentySevenDaysUsesWeeklyRate(): void
    {
        // 27 days (still < 28) = weekly rate calculation
        // 3 weeks + 6 days
        $storageType = $this->createStorageType(7000, 28000);
        $startDate = new \DateTimeImmutable('2024-01-01');
        $endDate = new \DateTimeImmutable('2024-01-28'); // 27 days

        $price = $this->calculator->calculatePrice($storageType, $startDate, $endDate);

        // 3 weeks (21000) + 6 days * (7000/7) = 21000 + 6000 = 27000
        $this->assertSame(27000, $price);
    }

    public function testTwentyEightDaysUsesMonthlyRate(): void
    {
        // 28 days = 1 × monthly rate (threshold)
        $storageType = $this->createStorageType(10000, 30000); // 100 Kč/week, 300 Kč/month
        $startDate = new \DateTimeImmutable('2024-01-01');
        $endDate = new \DateTimeImmutable('2024-01-29'); // 28 days

        $price = $this->calculator->calculatePrice($storageType, $startDate, $endDate);

        // 28 days / 30 = 0 full months + 28 remaining days
        // 0 * 30000 + 28 * (30000/30) = 0 + 28000 = 28000
        $this->assertSame(28000, $price);
    }

    public function testThirtyDaysEqualsOneMonth(): void
    {
        // 30 days = 1 × monthly rate
        $storageType = $this->createStorageType(10000, 30000);
        $startDate = new \DateTimeImmutable('2024-01-01');
        $endDate = new \DateTimeImmutable('2024-01-31'); // 30 days

        $price = $this->calculator->calculatePrice($storageType, $startDate, $endDate);

        $this->assertSame(30000, $price);
    }

    public function testFortyFiveDaysEqualsOneMonthPlusFifteenDays(): void
    {
        // 45 days = 1 × monthly + 15 × (monthly/30)
        $storageType = $this->createStorageType(10000, 30000); // 300 Kč/month = 10 Kč/day
        $startDate = new \DateTimeImmutable('2024-01-01');
        $endDate = new \DateTimeImmutable('2024-02-15'); // 45 days

        $price = $this->calculator->calculatePrice($storageType, $startDate, $endDate);

        // 1 month (30000) + 15 days * (30000/30) = 30000 + 15000 = 45000
        $this->assertSame(45000, $price);
    }

    public function testSixtyDaysEqualsTwoMonths(): void
    {
        // 60 days = 2 × monthly rate
        $storageType = $this->createStorageType(10000, 30000);
        $startDate = new \DateTimeImmutable('2024-01-01');
        $endDate = new \DateTimeImmutable('2024-03-01'); // 60 days

        $price = $this->calculator->calculatePrice($storageType, $startDate, $endDate);

        $this->assertSame(60000, $price);
    }

    public function testUnlimitedRentalReturnsMonthlyPrice(): void
    {
        // Unlimited rental = first month's price
        $storageType = $this->createStorageType(10000, 35000);
        $startDate = new \DateTimeImmutable('2024-01-01');

        $price = $this->calculator->calculatePrice($storageType, $startDate, null);

        $this->assertSame(35000, $price);
    }

    public function testZeroDaysReturnsZero(): void
    {
        $storageType = $this->createStorageType(10000, 30000);
        $startDate = new \DateTimeImmutable('2024-01-01');
        $endDate = new \DateTimeImmutable('2024-01-01'); // Same day = 0 days

        $price = $this->calculator->calculatePrice($storageType, $startDate, $endDate);

        $this->assertSame(0, $price);
    }

    public function testOneDayCalculation(): void
    {
        // 1 day = (weekly/7)
        $storageType = $this->createStorageType(7000, 28000); // 70 Kč/week = 10 Kč/day
        $startDate = new \DateTimeImmutable('2024-01-01');
        $endDate = new \DateTimeImmutable('2024-01-02'); // 1 day

        $price = $this->calculator->calculatePrice($storageType, $startDate, $endDate);

        // 0 weeks + 1 day * (7000/7) = 1000
        $this->assertSame(1000, $price);
    }

    public function testPriceBreakdownForWeeklyRate(): void
    {
        $storageType = $this->createStorageType(7000, 28000);
        $startDate = new \DateTimeImmutable('2024-01-01');
        $endDate = new \DateTimeImmutable('2024-01-11'); // 10 days

        $breakdown = $this->calculator->getPriceBreakdown($storageType, $startDate, $endDate);

        $this->assertSame(10, $breakdown['days']);
        $this->assertSame('weekly', $breakdown['rate_type']);
        $this->assertSame(1, $breakdown['full_periods']);
        $this->assertSame(3, $breakdown['remaining_days']);
        $this->assertSame(7000, $breakdown['period_price']);
        $this->assertSame(3000, $breakdown['remaining_price']);
        $this->assertSame(10000, $breakdown['total_price']);
    }

    public function testPriceBreakdownForMonthlyRate(): void
    {
        $storageType = $this->createStorageType(10000, 30000);
        $startDate = new \DateTimeImmutable('2024-01-01');
        $endDate = new \DateTimeImmutable('2024-02-15'); // 45 days

        $breakdown = $this->calculator->getPriceBreakdown($storageType, $startDate, $endDate);

        $this->assertSame(45, $breakdown['days']);
        $this->assertSame('monthly', $breakdown['rate_type']);
        $this->assertSame(1, $breakdown['full_periods']);
        $this->assertSame(15, $breakdown['remaining_days']);
        $this->assertSame(30000, $breakdown['period_price']);
        $this->assertSame(15000, $breakdown['remaining_price']);
        $this->assertSame(45000, $breakdown['total_price']);
    }

    public function testPriceBreakdownForUnlimited(): void
    {
        $storageType = $this->createStorageType(10000, 35000);
        $startDate = new \DateTimeImmutable('2024-01-01');

        $breakdown = $this->calculator->getPriceBreakdown($storageType, $startDate, null);

        $this->assertSame(0, $breakdown['days']);
        $this->assertSame('monthly', $breakdown['rate_type']);
        $this->assertSame(1, $breakdown['full_periods']);
        $this->assertSame(0, $breakdown['remaining_days']);
        $this->assertSame(35000, $breakdown['period_price']);
        $this->assertSame(0, $breakdown['remaining_price']);
        $this->assertSame(35000, $breakdown['total_price']);
    }

    public function testRoundingHandling(): void
    {
        // Prorated portion of the price always rounds UP to the nearest whole
        // CZK (= 100 halere). The customer sees the same number on step 1, step
        // 2 of the order flow, and on GoPay — no more "1 429 Kč here but charged
        // 1 428,57 Kč there" surprises.
        $storageType = $this->createStorageType(10000, 30100); // 100 Kč/week
        $startDate = new \DateTimeImmutable('2024-01-01');
        $endDate = new \DateTimeImmutable('2024-01-02'); // 1 day

        $price = $this->calculator->calculatePrice($storageType, $startDate, $endDate);

        // 1 day = 10000 / 7 = 1428.57 halere → ceil to whole CZK = 1500 halere (15 Kč)
        $this->assertSame(1500, $price);
    }

    public function testTenDaysAtWholeCzkWeeklyRoundsTailUp(): void
    {
        // The exact case from the customer bug report: 10-day rental against a
        // weekly rate that doesn't divide cleanly into days. The displayed
        // "Cena celkem" on step 1 must equal what the customer is actually
        // charged at GoPay.
        $storageType = $this->createStorageType(100000, 400000); // 1 000 Kč/week
        $startDate = new \DateTimeImmutable('2024-01-01');
        $endDate = new \DateTimeImmutable('2024-01-11'); // 10 days

        $price = $this->calculator->calculatePrice($storageType, $startDate, $endDate);

        // 1 week (100000) + 3 days × (100000/7 = 14 285,71…) = 142 857,14 halere
        // ceil to whole CZK → 100000 + 42900 = 142 900 halere (1 429 Kč)
        $this->assertSame(142900, $price);
    }

    public function testCalculatePriceForStorageWithoutCustomPrices(): void
    {
        // When storage has no custom prices, it uses storage type defaults
        $storageType = $this->createStorageType(10000, 35000); // 100 Kč/week, 350 Kč/month
        $place = $this->createPlace();
        $storage = $this->createStorage($storageType, $place);

        $startDate = new \DateTimeImmutable('2024-01-01');
        $endDate = new \DateTimeImmutable('2024-01-08'); // 7 days

        $price = $this->calculator->calculatePriceForStorage($storage, $startDate, $endDate);

        $this->assertSame(10000, $price);
    }

    public function testCalculatePriceForStorageWithCustomPrices(): void
    {
        // When storage has custom prices, it uses those instead of defaults
        $storageType = $this->createStorageType(10000, 35000); // defaults: 100 Kč/week, 350 Kč/month
        $place = $this->createPlace();
        $storage = $this->createStorage($storageType, $place);

        // Set custom prices (150 Kč/week, 500 Kč/month)
        $storage->updatePrices(15000, 50000, new \DateTimeImmutable());

        $startDate = new \DateTimeImmutable('2024-01-01');
        $endDate = new \DateTimeImmutable('2024-01-08'); // 7 days

        $price = $this->calculator->calculatePriceForStorage($storage, $startDate, $endDate);

        // Should use custom weekly price (15000), not default (10000)
        $this->assertSame(15000, $price);
    }

    public function testCalculatePriceForStorageWithCustomPricesMonthly(): void
    {
        // Test monthly calculation with custom prices (>= 28 days)
        $storageType = $this->createStorageType(10000, 30000); // defaults
        $place = $this->createPlace();
        $storage = $this->createStorage($storageType, $place);

        // Set custom prices (200 Kč/week, 600 Kč/month)
        $storage->updatePrices(20000, 60000, new \DateTimeImmutable());

        $startDate = new \DateTimeImmutable('2024-01-01');
        $endDate = new \DateTimeImmutable('2024-01-31'); // 30 days

        $price = $this->calculator->calculatePriceForStorage($storage, $startDate, $endDate);

        // Should use custom monthly price (60000), not default (30000)
        $this->assertSame(60000, $price);
    }

    public function testCalculatePriceForStorageUnlimitedRental(): void
    {
        // Unlimited rental should return monthly price
        $storageType = $this->createStorageType(10000, 35000);
        $place = $this->createPlace();
        $storage = $this->createStorage($storageType, $place);

        // Set custom prices
        $storage->updatePrices(15000, 50000, new \DateTimeImmutable());

        $startDate = new \DateTimeImmutable('2024-01-01');

        $price = $this->calculator->calculatePriceForStorage($storage, $startDate, null);

        // Should use custom monthly price for unlimited
        $this->assertSame(50000, $price);
    }

    public function testCalculatePriceForStorageZeroDays(): void
    {
        $storageType = $this->createStorageType(10000, 30000);
        $place = $this->createPlace();
        $storage = $this->createStorage($storageType, $place);

        $startDate = new \DateTimeImmutable('2024-01-01');
        $endDate = new \DateTimeImmutable('2024-01-01'); // Same day = 0 days

        $price = $this->calculator->calculatePriceForStorage($storage, $startDate, $endDate);

        $this->assertSame(0, $price);
    }

    public function testBuildPaymentScheduleUnlimitedRental(): void
    {
        $storageType = $this->createStorageType(10000, 180000); // 1 800 Kč/month
        $place = $this->createPlace();
        $storage = $this->createStorage($storageType, $place);
        $startDate = new \DateTimeImmutable('2026-05-09');

        $schedule = $this->calculator->buildPaymentSchedule($storage, $startDate, null);

        $this->assertTrue($schedule->isRecurring);
        $this->assertTrue($schedule->isOpenEnded);
        $this->assertSame(1, $schedule->entryCount());
        $this->assertSame(180000, $schedule->firstPayment()->amount);
        $this->assertEquals($startDate, $schedule->firstPayment()->chargeDate);
        $this->assertSame(180000, $schedule->monthlyAmount);
    }

    public function testBuildPaymentScheduleShortRentalIsOneShot(): void
    {
        $storageType = $this->createStorageType(70000, 180000); // 700 Kč/week
        $place = $this->createPlace();
        $storage = $this->createStorage($storageType, $place);
        $startDate = new \DateTimeImmutable('2026-05-09');
        $endDate = new \DateTimeImmutable('2026-05-19'); // 10 days

        $schedule = $this->calculator->buildPaymentSchedule($storage, $startDate, $endDate);

        $this->assertFalse($schedule->isRecurring);
        $this->assertFalse($schedule->isOpenEnded);
        $this->assertSame(1, $schedule->entryCount());
        // 1 week (70000) + 3 days × (70000/7) = 70000 + 30000 = 100000
        $this->assertSame(100000, $schedule->firstPayment()->amount);
        $this->assertNull($schedule->monthlyAmount);
    }

    public function testBuildPaymentScheduleFortyNineDaysProducesTwoCharges(): void
    {
        // The exact case from the bug report — 49 days, 1 800 Kč/měsíc.
        // Expected: 1 800 (start) + prorated tail (18 days × 60) = 1 080 → total 2 880.
        $storageType = $this->createStorageType(50000, 180000);
        $place = $this->createPlace();
        $storage = $this->createStorage($storageType, $place);
        $startDate = new \DateTimeImmutable('2026-05-09');
        $endDate = new \DateTimeImmutable('2026-06-27'); // 49 days

        $schedule = $this->calculator->buildPaymentSchedule($storage, $startDate, $endDate);

        $this->assertTrue($schedule->isRecurring);
        $this->assertFalse($schedule->isOpenEnded);
        $this->assertSame(2, $schedule->entryCount());
        $this->assertSame(180000, $schedule->entries[0]->amount);
        $this->assertEquals($startDate, $schedule->entries[0]->chargeDate);
        // Second billing on 9.6.2026, prorates 18 days × 6000 halire/day = 108000
        $this->assertEquals(new \DateTimeImmutable('2026-06-09'), $schedule->entries[1]->chargeDate);
        $this->assertSame(108000, $schedule->entries[1]->amount);
        $this->assertSame(288000, $schedule->totalKnownAmount());
        $this->assertSame(180000, $schedule->monthlyAmount);
    }

    public function testBuildPaymentScheduleExactlyOneMonth(): void
    {
        $storageType = $this->createStorageType(50000, 180000);
        $place = $this->createPlace();
        $storage = $this->createStorage($storageType, $place);
        $startDate = new \DateTimeImmutable('2026-05-09');
        $endDate = new \DateTimeImmutable('2026-06-09'); // exactly 1 calendar month

        $schedule = $this->calculator->buildPaymentSchedule($storage, $startDate, $endDate);

        $this->assertTrue($schedule->isRecurring);
        $this->assertSame(1, $schedule->entryCount());
        $this->assertSame(180000, $schedule->totalKnownAmount());
    }

    public function testBuildPaymentScheduleZeroDays(): void
    {
        $storageType = $this->createStorageType(50000, 180000);
        $place = $this->createPlace();
        $storage = $this->createStorage($storageType, $place);
        $startDate = new \DateTimeImmutable('2026-05-09');

        $schedule = $this->calculator->buildPaymentSchedule($storage, $startDate, $startDate);

        $this->assertTrue($schedule->isEmpty());
        $this->assertFalse($schedule->isRecurring);
    }

    public function testCalculateFirstPaymentPriceMatchesScheduleFirstEntry(): void
    {
        // Belt-and-braces: the legacy method must always agree with the schedule
        // it now delegates to, otherwise OrderAcceptController would persist a
        // firstPaymentPrice different from what the schedule promises.
        $storageType = $this->createStorageType(50000, 180000);
        $place = $this->createPlace();
        $storage = $this->createStorage($storageType, $place);
        $startDate = new \DateTimeImmutable('2026-05-09');

        foreach ([null, new \DateTimeImmutable('2026-05-19'), new \DateTimeImmutable('2026-06-27')] as $endDate) {
            $first = $this->calculator->calculateFirstPaymentPrice($storage, $startDate, $endDate);
            $schedule = $this->calculator->buildPaymentSchedule($storage, $startDate, $endDate);
            $this->assertSame($first, $schedule->firstPayment()->amount);
        }
    }

    public function testBuildScheduleFromOrderOneTime(): void
    {
        $order = $this->createOrder(
            rentalType: RentalType::LIMITED,
            startDate: new \DateTimeImmutable('2025-06-15'),
            endDate: new \DateTimeImmutable('2025-06-29'), // 14 days
            firstPaymentPrice: 180000,
        );

        $schedule = $this->calculator->buildScheduleFromOrder($order);

        $this->assertCount(1, $schedule->entries);
        $this->assertSame(180000, $schedule->firstPayment()->amount);
        $this->assertFalse($schedule->isRecurring);
        $this->assertFalse($schedule->isOpenEnded);
        $this->assertNull($schedule->monthlyAmount);
    }

    public function testBuildScheduleFromOrderFixedTermRecurring(): void
    {
        // 90 days @ 5 000 Kč/měsíc = 3 entries (full + full + prorated tail)
        $order = $this->createOrder(
            rentalType: RentalType::LIMITED,
            startDate: new \DateTimeImmutable('2025-06-15'),
            endDate: new \DateTimeImmutable('2025-09-13'),
            firstPaymentPrice: 500000,
        );

        $schedule = $this->calculator->buildScheduleFromOrder($order);

        $this->assertGreaterThanOrEqual(2, count($schedule->entries));
        $this->assertTrue($schedule->isRecurring);
        $this->assertFalse($schedule->isOpenEnded);
        $this->assertSame(500000, $schedule->monthlyAmount);
        $this->assertSame(500000, $schedule->firstPayment()->amount);
    }

    public function testBuildScheduleFromOrderUnlimited(): void
    {
        $order = $this->createOrder(
            rentalType: RentalType::UNLIMITED,
            startDate: new \DateTimeImmutable('2025-06-15'),
            endDate: null,
            firstPaymentPrice: 500000,
        );

        $schedule = $this->calculator->buildScheduleFromOrder($order);

        $this->assertCount(1, $schedule->entries);
        $this->assertSame(500000, $schedule->firstPayment()->amount);
        $this->assertTrue($schedule->isRecurring);
        $this->assertTrue($schedule->isOpenEnded);
        $this->assertSame(500000, $schedule->monthlyAmount);
    }

    /**
     * The locked-in invariant: once an order is created, subsequent changes
     * to Storage.pricePerMonth must NOT influence the schedule rendered for
     * that order (the order's firstPaymentPrice is the locked anchor).
     */
    public function testBuildScheduleFromOrderIgnoresStoragePriceChanges(): void
    {
        $storageType = $this->createStorageType(50000, 500000); // 5 000 Kč/month default
        $place = $this->createPlace();
        $storage = $this->createStorage($storageType, $place);

        $order = $this->createOrderWithStorage(
            $storage,
            rentalType: RentalType::UNLIMITED,
            startDate: new \DateTimeImmutable('2025-06-15'),
            endDate: null,
            firstPaymentPrice: 500000,
        );

        // Storage price hike *after* the order is placed.
        $storage->updatePrices(70000, 700000, new \DateTimeImmutable('2025-07-01'));

        $schedule = $this->calculator->buildScheduleFromOrder($order);

        $this->assertSame(500000, $schedule->monthlyAmount, 'order monthly stayed at locked rate');
        $this->assertSame(500000, $schedule->firstPayment()->amount);
    }

    private function createUser(): User
    {
        return new User(
            id: Uuid::v7(),
            email: 'user@example.com',
            password: 'password',
            firstName: 'Test',
            lastName: 'User',
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createOrder(
        RentalType $rentalType,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        int $firstPaymentPrice,
    ): Order {
        $storageType = $this->createStorageType(50000, 180000);
        $place = $this->createPlace();
        $storage = $this->createStorage($storageType, $place);

        return $this->createOrderWithStorage($storage, $rentalType, $startDate, $endDate, $firstPaymentPrice);
    }

    private function createOrderWithStorage(
        Storage $storage,
        RentalType $rentalType,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        int $firstPaymentPrice,
    ): Order {
        $createdAt = new \DateTimeImmutable('2025-06-01');

        return new Order(
            id: Uuid::v7(),
            user: $this->createUser(),
            storage: $storage,
            rentalType: $rentalType,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $startDate,
            endDate: $endDate,
            firstPaymentPrice: $firstPaymentPrice,
            expiresAt: $createdAt->modify('+7 days'),
            createdAt: $createdAt,
        );
    }
}
