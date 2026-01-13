<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exception;

use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Exception\NoStorageAvailable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class NoStorageAvailableTest extends TestCase
{
    private function createStorageType(string $name): StorageType
    {
        $owner = new User(
            Uuid::v7(),
            'owner@example.com',
            'password',
            'Test',
            'User',
            new \DateTimeImmutable(),
        );

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            owner: $owner,
            createdAt: new \DateTimeImmutable(),
        );

        return new StorageType(
            id: Uuid::v7(),
            name: $name,
            width: 100,
            height: 200,
            length: 150,
            pricePerWeek: 10000,
            pricePerMonth: 35000,
            place: $place,
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
