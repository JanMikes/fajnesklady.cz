<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Service\CommissionCalculator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class CommissionCalculatorTest extends TestCase
{
    private CommissionCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new CommissionCalculator();
    }

    private function createUser(?string $commissionRate = null): User
    {
        $user = new User(
            id: Uuid::v7(),
            email: 'landlord@example.com',
            password: 'password',
            firstName: 'Test',
            lastName: 'Landlord',
            createdAt: new \DateTimeImmutable(),
        );

        if (null !== $commissionRate) {
            $user->updateCommissionRate($commissionRate, new \DateTimeImmutable());
        }

        return $user;
    }

    private function createStorageType(): StorageType
    {
        return new StorageType(
            id: Uuid::v7(),
            name: 'Test Type',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
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

    private function createStorage(
        StorageType $storageType,
        Place $place,
        ?User $owner = null,
        ?string $commissionRate = null,
    ): Storage {
        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
            owner: $owner,
        );

        if (null !== $commissionRate) {
            $storage->updateCommissionRate($commissionRate, new \DateTimeImmutable());
        }

        return $storage;
    }

    public function testStorageLevelRateTakesPriority(): void
    {
        $storageType = $this->createStorageType();
        $place = $this->createPlace();
        $landlord = $this->createUser('0.85'); // Landlord has 85%
        $storage = $this->createStorage($storageType, $place, $landlord, '0.80'); // Storage has 80%

        $rate = $this->calculator->getRate($storage);

        // Storage-level rate (80%) should take priority over landlord (85%) and default (90%)
        $this->assertSame('0.80', $rate);
    }

    public function testLandlordLevelRateFallback(): void
    {
        $storageType = $this->createStorageType();
        $place = $this->createPlace();
        $landlord = $this->createUser('0.85'); // Landlord has 85%
        $storage = $this->createStorage($storageType, $place, $landlord); // Storage has no rate

        $rate = $this->calculator->getRate($storage);

        // Landlord-level rate (85%) should be used when storage has no rate
        $this->assertSame('0.85', $rate);
    }

    public function testDefaultRateFallback(): void
    {
        $storageType = $this->createStorageType();
        $place = $this->createPlace();
        $landlord = $this->createUser(); // Landlord has no rate
        $storage = $this->createStorage($storageType, $place, $landlord); // Storage has no rate

        $rate = $this->calculator->getRate($storage);

        // Default rate (90%) should be used when neither storage nor landlord has a rate
        $this->assertSame('0.90', $rate);
    }

    public function testDefaultRateFallbackWhenNoOwner(): void
    {
        $storageType = $this->createStorageType();
        $place = $this->createPlace();
        $storage = $this->createStorage($storageType, $place); // No owner

        $rate = $this->calculator->getRate($storage);

        // Default rate (90%) should be used when storage has no owner
        $this->assertSame('0.90', $rate);
    }

    public function testCalculateNetAmountWithNinetyPercent(): void
    {
        $grossAmount = 100000; // 1000 CZK in haléře
        $rate = '0.90';

        $netAmount = $this->calculator->calculateNetAmount($grossAmount, $rate);

        // 90% of 100000 = 90000
        $this->assertSame(90000, $netAmount);
    }

    public function testCalculateNetAmountWithEightyFivePercent(): void
    {
        $grossAmount = 100000; // 1000 CZK
        $rate = '0.85';

        $netAmount = $this->calculator->calculateNetAmount($grossAmount, $rate);

        // 85% of 100000 = 85000
        $this->assertSame(85000, $netAmount);
    }

    public function testCalculateNetAmountWithRounding(): void
    {
        $grossAmount = 33333; // 333.33 CZK
        $rate = '0.90';

        $netAmount = $this->calculator->calculateNetAmount($grossAmount, $rate);

        // 90% of 33333 = 29999.7, rounded to 30000
        $this->assertSame(30000, $netAmount);
    }

    public function testCalculateNetAmountZeroGrossAmount(): void
    {
        $grossAmount = 0;
        $rate = '0.90';

        $netAmount = $this->calculator->calculateNetAmount($grossAmount, $rate);

        $this->assertSame(0, $netAmount);
    }

    public function testCalculateNetAmountOneHundredPercent(): void
    {
        $grossAmount = 50000; // 500 CZK
        $rate = '1.00';

        $netAmount = $this->calculator->calculateNetAmount($grossAmount, $rate);

        // 100% of 50000 = 50000
        $this->assertSame(50000, $netAmount);
    }

    public function testCalculateNetAmountSeventyPercent(): void
    {
        $grossAmount = 100000; // 1000 CZK
        $rate = '0.70';

        $netAmount = $this->calculator->calculateNetAmount($grossAmount, $rate);

        // 70% of 100000 = 70000
        $this->assertSame(70000, $netAmount);
    }

    public function testGetDefaultRate(): void
    {
        $defaultRate = $this->calculator->getDefaultRate();

        $this->assertSame('0.90', $defaultRate);
    }

    public function testStorageRateOverridesLandlordEvenWhenLower(): void
    {
        $storageType = $this->createStorageType();
        $place = $this->createPlace();
        $landlord = $this->createUser('0.95'); // Landlord has 95%
        $storage = $this->createStorage($storageType, $place, $landlord, '0.75'); // Storage has 75%

        $rate = $this->calculator->getRate($storage);

        // Storage rate should always take priority, even if lower
        $this->assertSame('0.75', $rate);
    }

    public function testStorageRateOverridesDefault(): void
    {
        $storageType = $this->createStorageType();
        $place = $this->createPlace();
        $landlord = $this->createUser(); // Landlord has no rate (would use 90% default)
        $storage = $this->createStorage($storageType, $place, $landlord, '0.80'); // Storage has 80%

        $rate = $this->calculator->getRate($storage);

        // Storage rate should override default
        $this->assertSame('0.80', $rate);
    }
}
