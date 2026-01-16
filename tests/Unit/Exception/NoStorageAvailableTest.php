<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exception;

use App\Entity\StorageType;
use App\Exception\NoStorageAvailable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class NoStorageAvailableTest extends TestCase
{
    private function createStorageType(string $name): StorageType
    {
        return new StorageType(
            id: Uuid::v7(),
            name: $name,
            innerWidth: 100,
            innerHeight: 200,
            innerLength: 150,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            createdAt: new \DateTimeImmutable(),
        );
    }

    public function testForStorageTypeWithLimitedPeriod(): void
    {
        $storageType = $this->createStorageType('Premium Box');
        $startDate = new \DateTimeImmutable('2024-06-01');
        $endDate = new \DateTimeImmutable('2024-06-30');

        $exception = NoStorageAvailable::forStorageType($storageType, $startDate, $endDate);

        $this->assertSame(
            'No storage of type "Premium Box" is available from 2024-06-01 to 2024-06-30.',
            $exception->getMessage(),
        );
    }

    public function testForStorageTypeWithUnlimitedPeriod(): void
    {
        $storageType = $this->createStorageType('Standard Box');
        $startDate = new \DateTimeImmutable('2024-03-15');

        $exception = NoStorageAvailable::forStorageType($storageType, $startDate, null);

        $this->assertSame(
            'No storage of type "Standard Box" is available from 2024-03-15 (unlimited).',
            $exception->getMessage(),
        );
    }

    public function testForPeriodWithLimitedPeriod(): void
    {
        $startDate = new \DateTimeImmutable('2024-01-10');
        $endDate = new \DateTimeImmutable('2024-02-28');

        $exception = NoStorageAvailable::forPeriod($startDate, $endDate);

        $this->assertSame(
            'No storage is available from 2024-01-10 to 2024-02-28.',
            $exception->getMessage(),
        );
    }

    public function testForPeriodWithUnlimitedPeriod(): void
    {
        $startDate = new \DateTimeImmutable('2024-12-01');

        $exception = NoStorageAvailable::forPeriod($startDate, null);

        $this->assertSame(
            'No storage is available from 2024-12-01 (unlimited).',
            $exception->getMessage(),
        );
    }
}
