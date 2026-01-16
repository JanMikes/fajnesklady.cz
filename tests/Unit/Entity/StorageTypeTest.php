<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\StorageType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class StorageTypeTest extends TestCase
{
    public function testCreateStorageType(): void
    {
        $now = new \DateTimeImmutable();

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Small Box',
            innerWidth: 150,
            innerHeight: 200,
            innerLength: 250,
            defaultPricePerWeek: 15000,
            defaultPricePerMonth: 50000,
            createdAt: $now,
        );

        $this->assertInstanceOf(Uuid::class, $storageType->id);
        $this->assertSame('Small Box', $storageType->name);
        $this->assertSame(150, $storageType->innerWidth);
        $this->assertSame(200, $storageType->innerHeight);
        $this->assertSame(250, $storageType->innerLength);
        $this->assertSame(15000, $storageType->defaultPricePerWeek);
        $this->assertSame(50000, $storageType->defaultPricePerMonth);
        $this->assertSame($now, $storageType->createdAt);
        $this->assertSame($now, $storageType->updatedAt);
    }

    public function testGetDefaultPricePerWeekInCzk(): void
    {
        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 15000,
            defaultPricePerMonth: 50000,
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertSame(150.0, $storageType->getDefaultPricePerWeekInCzk());
    }

    public function testGetDefaultPricePerMonthInCzk(): void
    {
        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 15000,
            defaultPricePerMonth: 50000,
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertSame(500.0, $storageType->getDefaultPricePerMonthInCzk());
    }

    public function testGetVolumeInCubicMeters(): void
    {
        // 200cm x 300cm x 400cm = 2m x 3m x 4m = 24 m³
        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            innerWidth: 200,
            innerHeight: 300,
            innerLength: 400,
            defaultPricePerWeek: 15000,
            defaultPricePerMonth: 50000,
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertSame(24.0, $storageType->getVolumeInCubicMeters());
    }

    public function testGetFloorAreaInSquareMeters(): void
    {
        // 200cm x 400cm = 2m x 4m = 8 m²
        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            innerWidth: 200,
            innerHeight: 300,
            innerLength: 400,
            defaultPricePerWeek: 15000,
            defaultPricePerMonth: 50000,
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertSame(8.0, $storageType->getFloorAreaInSquareMeters());
    }

    public function testGetInnerDimensionsInMeters(): void
    {
        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            innerWidth: 200,
            innerHeight: 300,
            innerLength: 400,
            defaultPricePerWeek: 15000,
            defaultPricePerMonth: 50000,
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertSame('2.00 x 3.00 x 4.00 m', $storageType->getInnerDimensionsInMeters());
    }

    public function testOuterDimensions(): void
    {
        // Without outer dimensions
        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            innerWidth: 200,
            innerHeight: 300,
            innerLength: 400,
            defaultPricePerWeek: 15000,
            defaultPricePerMonth: 50000,
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertFalse($storageType->hasOuterDimensions());
        $this->assertNull($storageType->getOuterDimensionsInMeters());

        // With outer dimensions
        $storageTypeWithOuter = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            innerWidth: 200,
            innerHeight: 300,
            innerLength: 400,
            defaultPricePerWeek: 15000,
            defaultPricePerMonth: 50000,
            createdAt: new \DateTimeImmutable(),
            outerWidth: 220,
            outerHeight: 320,
            outerLength: 420,
        );

        $this->assertTrue($storageTypeWithOuter->hasOuterDimensions());
        $this->assertSame('2.20 x 3.20 x 4.20 m', $storageTypeWithOuter->getOuterDimensionsInMeters());
    }

    public function testUpdateDetails(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $updatedAt = new \DateTimeImmutable('2024-01-01 11:00:00');

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Original',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 30000,
            createdAt: $createdAt,
        );

        $storageType->updateDetails(
            name: 'Updated',
            innerWidth: 200,
            innerHeight: 200,
            innerLength: 200,
            outerWidth: 220,
            outerHeight: 220,
            outerLength: 220,
            defaultPricePerWeek: 20000,
            defaultPricePerMonth: 60000,
            description: 'Test description',
            now: $updatedAt,
        );

        $this->assertSame('Updated', $storageType->name);
        $this->assertSame(200, $storageType->innerWidth);
        $this->assertSame(200, $storageType->innerHeight);
        $this->assertSame(200, $storageType->innerLength);
        $this->assertSame(220, $storageType->outerWidth);
        $this->assertSame(220, $storageType->outerHeight);
        $this->assertSame(220, $storageType->outerLength);
        $this->assertSame(20000, $storageType->defaultPricePerWeek);
        $this->assertSame(60000, $storageType->defaultPricePerMonth);
        $this->assertSame('Test description', $storageType->description);
        $this->assertSame($createdAt, $storageType->createdAt);
        $this->assertSame($updatedAt, $storageType->updatedAt);
    }

    public function testCreatedAtIsImmutable(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $updatedAt = new \DateTimeImmutable('2024-01-01 11:00:00');

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 30000,
            createdAt: $createdAt,
        );

        $storageType->updateDetails(
            name: 'Updated',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            outerWidth: null,
            outerHeight: null,
            outerLength: null,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 30000,
            description: null,
            now: $updatedAt,
        );

        $this->assertSame($createdAt, $storageType->createdAt);
    }

    public function testActivateDeactivate(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 30000,
            createdAt: $createdAt,
        );

        $this->assertTrue($storageType->isActive);

        $storageType->deactivate(new \DateTimeImmutable('2024-01-01 11:00:00'));
        $this->assertFalse($storageType->isActive);

        $storageType->activate(new \DateTimeImmutable('2024-01-01 12:00:00'));
        $this->assertTrue($storageType->isActive);
    }
}
