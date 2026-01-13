<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class StorageTypeTest extends TestCase
{
    private function createUser(string $email = 'owner@example.com'): User
    {
        return new User(Uuid::v7(), $email, 'password', 'Test', 'Owner', new \DateTimeImmutable());
    }

    private function createPlace(User $owner): Place
    {
        return new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            owner: $owner,
            createdAt: new \DateTimeImmutable(),
        );
    }

    public function testCreateStorageType(): void
    {
        $now = new \DateTimeImmutable();
        $owner = $this->createUser();
        $place = $this->createPlace($owner);

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Small Box',
            innerWidth: 150,
            innerHeight: 200,
            innerLength: 250,
            pricePerWeek: 15000,
            pricePerMonth: 50000,
            place: $place,
            createdAt: $now,
        );

        $this->assertInstanceOf(Uuid::class, $storageType->id);
        $this->assertSame('Small Box', $storageType->name);
        $this->assertSame(150, $storageType->innerWidth);
        $this->assertSame(200, $storageType->innerHeight);
        $this->assertSame(250, $storageType->innerLength);
        $this->assertSame(15000, $storageType->pricePerWeek);
        $this->assertSame(50000, $storageType->pricePerMonth);
        $this->assertSame($place, $storageType->place);
        $this->assertSame($now, $storageType->createdAt);
        $this->assertSame($now, $storageType->updatedAt);
    }

    public function testGetPricePerWeekInCzk(): void
    {
        $owner = $this->createUser();
        $place = $this->createPlace($owner);

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            pricePerWeek: 15000,
            pricePerMonth: 50000,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertSame(150.0, $storageType->getPricePerWeekInCzk());
    }

    public function testGetPricePerMonthInCzk(): void
    {
        $owner = $this->createUser();
        $place = $this->createPlace($owner);

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            pricePerWeek: 15000,
            pricePerMonth: 50000,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertSame(500.0, $storageType->getPricePerMonthInCzk());
    }

    public function testGetVolumeInCubicMeters(): void
    {
        $owner = $this->createUser();
        $place = $this->createPlace($owner);

        // 200cm x 300cm x 400cm = 2m x 3m x 4m = 24 m³
        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            innerWidth: 200,
            innerHeight: 300,
            innerLength: 400,
            pricePerWeek: 15000,
            pricePerMonth: 50000,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertSame(24.0, $storageType->getVolumeInCubicMeters());
    }

    public function testGetFloorAreaInSquareMeters(): void
    {
        $owner = $this->createUser();
        $place = $this->createPlace($owner);

        // 200cm x 400cm = 2m x 4m = 8 m²
        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            innerWidth: 200,
            innerHeight: 300,
            innerLength: 400,
            pricePerWeek: 15000,
            pricePerMonth: 50000,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertSame(8.0, $storageType->getFloorAreaInSquareMeters());
    }

    public function testGetInnerDimensionsInMeters(): void
    {
        $owner = $this->createUser();
        $place = $this->createPlace($owner);

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            innerWidth: 200,
            innerHeight: 300,
            innerLength: 400,
            pricePerWeek: 15000,
            pricePerMonth: 50000,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertSame('2.00 x 3.00 x 4.00 m', $storageType->getInnerDimensionsInMeters());
    }

    public function testOuterDimensions(): void
    {
        $owner = $this->createUser();
        $place = $this->createPlace($owner);

        // Without outer dimensions
        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            innerWidth: 200,
            innerHeight: 300,
            innerLength: 400,
            pricePerWeek: 15000,
            pricePerMonth: 50000,
            place: $place,
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
            pricePerWeek: 15000,
            pricePerMonth: 50000,
            place: $place,
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
        $owner = $this->createUser();
        $place = $this->createPlace($owner);

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Original',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            pricePerWeek: 10000,
            pricePerMonth: 30000,
            place: $place,
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
            pricePerWeek: 20000,
            pricePerMonth: 60000,
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
        $this->assertSame(20000, $storageType->pricePerWeek);
        $this->assertSame(60000, $storageType->pricePerMonth);
        $this->assertSame('Test description', $storageType->description);
        $this->assertSame($createdAt, $storageType->createdAt);
        $this->assertSame($updatedAt, $storageType->updatedAt);
    }

    public function testIsOwnedByReturnsTrueForOwner(): void
    {
        $now = new \DateTimeImmutable();
        $owner = $this->createUser();
        $place = $this->createPlace($owner);

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            pricePerWeek: 10000,
            pricePerMonth: 30000,
            place: $place,
            createdAt: $now,
        );

        $this->assertTrue($storageType->isOwnedBy($owner));
    }

    public function testIsOwnedByReturnsFalseForDifferentUser(): void
    {
        $now = new \DateTimeImmutable();
        $owner = $this->createUser('owner@example.com');
        $otherUser = $this->createUser('other@example.com');
        $place = $this->createPlace($owner);

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            pricePerWeek: 10000,
            pricePerMonth: 30000,
            place: $place,
            createdAt: $now,
        );

        $this->assertFalse($storageType->isOwnedBy($otherUser));
    }

    public function testBelongsToPlace(): void
    {
        $now = new \DateTimeImmutable();
        $owner = $this->createUser();
        $place = $this->createPlace($owner);
        $otherPlace = $this->createPlace($owner);

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            pricePerWeek: 10000,
            pricePerMonth: 30000,
            place: $place,
            createdAt: $now,
        );

        $this->assertTrue($storageType->belongsToPlace($place));
        $this->assertFalse($storageType->belongsToPlace($otherPlace));
    }

    public function testCreatedAtIsImmutable(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $updatedAt = new \DateTimeImmutable('2024-01-01 11:00:00');
        $owner = $this->createUser();
        $place = $this->createPlace($owner);

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            pricePerWeek: 10000,
            pricePerMonth: 30000,
            place: $place,
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
            pricePerWeek: 10000,
            pricePerMonth: 30000,
            description: null,
            now: $updatedAt,
        );

        $this->assertSame($createdAt, $storageType->createdAt);
    }

    public function testActivateDeactivate(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $owner = $this->createUser();
        $place = $this->createPlace($owner);

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            pricePerWeek: 10000,
            pricePerMonth: 30000,
            place: $place,
            createdAt: $createdAt,
        );

        $this->assertTrue($storageType->isActive);

        $storageType->deactivate(new \DateTimeImmutable('2024-01-01 11:00:00'));
        $this->assertFalse($storageType->isActive);

        $storageType->activate(new \DateTimeImmutable('2024-01-01 12:00:00'));
        $this->assertTrue($storageType->isActive);
    }
}
