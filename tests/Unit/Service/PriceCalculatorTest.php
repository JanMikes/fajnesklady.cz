<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
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
        // Test that rounding works correctly for prices that don't divide evenly
        $storageType = $this->createStorageType(10000, 30100); // 301 Kč/month, ~10.03 Kč/day
        $startDate = new \DateTimeImmutable('2024-01-01');
        $endDate = new \DateTimeImmutable('2024-01-02'); // 1 day, but >= 28 threshold doesn't apply

        $price = $this->calculator->calculatePrice($storageType, $startDate, $endDate);

        // 1 day = 10000 / 7 = 1428.57... rounded to 1429
        $this->assertSame(1429, $price);
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
}
