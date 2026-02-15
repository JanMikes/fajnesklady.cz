<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exception;

use App\Entity\Place;
use App\Entity\StorageType;
use App\Exception\NoStorageAvailable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class NoStorageAvailableTest extends TestCase
{
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

    private function createStorageType(string $name): StorageType
    {
        return new StorageType(
            id: Uuid::v7(),
            place: $this->createPlace(),
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
            'Žádný sklad typu "Premium Box" není dostupný od 01.06.2024 do 30.06.2024.',
            $exception->getMessage(),
        );
    }

    public function testForStorageTypeWithUnlimitedPeriod(): void
    {
        $storageType = $this->createStorageType('Standard Box');
        $startDate = new \DateTimeImmutable('2024-03-15');

        $exception = NoStorageAvailable::forStorageType($storageType, $startDate, null);

        $this->assertSame(
            'Žádný sklad typu "Standard Box" není dostupný od 15.03.2024 (neomezeně).',
            $exception->getMessage(),
        );
    }

    public function testForPeriodWithLimitedPeriod(): void
    {
        $startDate = new \DateTimeImmutable('2024-01-10');
        $endDate = new \DateTimeImmutable('2024-02-28');

        $exception = NoStorageAvailable::forPeriod($startDate, $endDate);

        $this->assertSame(
            'Žádný sklad není dostupný od 10.01.2024 do 28.02.2024.',
            $exception->getMessage(),
        );
    }

    public function testForPeriodWithUnlimitedPeriod(): void
    {
        $startDate = new \DateTimeImmutable('2024-12-01');

        $exception = NoStorageAvailable::forPeriod($startDate, null);

        $this->assertSame(
            'Žádný sklad není dostupný od 01.12.2024 (neomezeně).',
            $exception->getMessage(),
        );
    }
}
