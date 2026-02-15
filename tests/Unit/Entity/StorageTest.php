<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\StorageStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class StorageTest extends TestCase
{
    private function createUser(string $email = 'owner@example.com'): User
    {
        return new User(Uuid::v7(), $email, 'password', 'Test', 'Owner', new \DateTimeImmutable());
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

    private function createStorageType(): StorageType
    {
        return new StorageType(
            id: Uuid::v7(),
            place: $this->createPlace(),
            name: 'Small Box',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createStorage(StorageType $storageType, Place $place, ?User $owner = null): Storage
    {
        return new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
            owner: $owner,
        );
    }

    public function testCreateStorage(): void
    {
        $now = new \DateTimeImmutable();
        $place = $this->createPlace();
        $storageType = $this->createStorageType();

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 10, 'y' => 20, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $now,
        );

        $this->assertInstanceOf(Uuid::class, $storage->id);
        $this->assertSame('A1', $storage->number);
        $this->assertSame(['x' => 10, 'y' => 20, 'width' => 100, 'height' => 100, 'rotation' => 0], $storage->coordinates);
        $this->assertSame($storageType, $storage->storageType);
        $this->assertSame($place, $storage->place);
        $this->assertSame(StorageStatus::AVAILABLE, $storage->status);
        $this->assertSame($now, $storage->createdAt);
        $this->assertSame($now, $storage->updatedAt);
    }

    public function testDefaultStatusIsAvailable(): void
    {
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place);

        $this->assertSame(StorageStatus::AVAILABLE, $storage->status);
        $this->assertTrue($storage->isAvailable());
        $this->assertFalse($storage->isReserved());
        $this->assertFalse($storage->isOccupied());
    }

    public function testReserve(): void
    {
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place);
        $now = new \DateTimeImmutable();

        $storage->reserve($now);

        $this->assertSame(StorageStatus::RESERVED, $storage->status);
        $this->assertFalse($storage->isAvailable());
        $this->assertTrue($storage->isReserved());
        $this->assertFalse($storage->isOccupied());
        $this->assertSame($now, $storage->updatedAt);
    }

    public function testOccupy(): void
    {
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place);
        $now = new \DateTimeImmutable();

        $storage->occupy($now);

        $this->assertSame(StorageStatus::OCCUPIED, $storage->status);
        $this->assertFalse($storage->isAvailable());
        $this->assertFalse($storage->isReserved());
        $this->assertTrue($storage->isOccupied());
        $this->assertSame($now, $storage->updatedAt);
    }

    public function testRelease(): void
    {
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place);
        $reserveTime = new \DateTimeImmutable('2024-01-01 10:00:00');
        $releaseTime = new \DateTimeImmutable('2024-01-01 11:00:00');

        $storage->reserve($reserveTime);
        $this->assertSame(StorageStatus::RESERVED, $storage->status);

        $storage->release($releaseTime);

        $this->assertSame(StorageStatus::AVAILABLE, $storage->status);
        $this->assertTrue($storage->isAvailable());
        $this->assertSame($releaseTime, $storage->updatedAt);
    }

    public function testMarkUnavailable(): void
    {
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place);
        $now = new \DateTimeImmutable();

        $storage->markUnavailable($now);

        $this->assertSame(StorageStatus::MANUALLY_UNAVAILABLE, $storage->status);
        $this->assertFalse($storage->isAvailable());
        $this->assertSame($now, $storage->updatedAt);
    }

    public function testStatusTransitions(): void
    {
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place);

        // Available -> Reserved
        $storage->reserve(new \DateTimeImmutable());
        $this->assertSame(StorageStatus::RESERVED, $storage->status);

        // Reserved -> Occupied
        $storage->occupy(new \DateTimeImmutable());
        $this->assertSame(StorageStatus::OCCUPIED, $storage->status);

        // Occupied -> Available (released)
        $storage->release(new \DateTimeImmutable());
        $this->assertSame(StorageStatus::AVAILABLE, $storage->status);

        // Available -> Manually Unavailable
        $storage->markUnavailable(new \DateTimeImmutable());
        $this->assertSame(StorageStatus::MANUALLY_UNAVAILABLE, $storage->status);

        // Manually Unavailable -> Available
        $storage->release(new \DateTimeImmutable());
        $this->assertSame(StorageStatus::AVAILABLE, $storage->status);
    }

    public function testUpdateDetails(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $updatedAt = new \DateTimeImmutable('2024-01-01 11:00:00');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $createdAt,
        );

        $storage->updateDetails(
            number: 'B2',
            coordinates: ['x' => 50, 'y' => 50, 'width' => 200, 'height' => 200, 'rotation' => 90],
            now: $updatedAt,
        );

        $this->assertSame('B2', $storage->number);
        $this->assertSame(['x' => 50, 'y' => 50, 'width' => 200, 'height' => 200, 'rotation' => 90], $storage->coordinates);
        $this->assertSame($createdAt, $storage->createdAt);
        $this->assertSame($updatedAt, $storage->updatedAt);
    }

    public function testGetPlace(): void
    {
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place);

        $this->assertSame($place, $storage->place);
    }

    public function testIsOwnedByReturnsTrueForOwner(): void
    {
        $owner = $this->createUser();
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, $owner);

        $this->assertTrue($storage->isOwnedBy($owner));
    }

    public function testIsOwnedByReturnsFalseForDifferentUser(): void
    {
        $owner = $this->createUser('owner@example.com');
        $otherUser = $this->createUser('other@example.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, $owner);

        $this->assertFalse($storage->isOwnedBy($otherUser));
    }

    public function testIsOwnedByReturnsFalseWhenNoOwner(): void
    {
        $user = $this->createUser();
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place); // No owner

        $this->assertFalse($storage->isOwnedBy($user));
    }

    public function testGetEffectivePricePerWeekReturnsStorageTypePriceWhenNoCustomPrice(): void
    {
        $place = $this->createPlace();
        $storageType = $this->createStorageType(); // 10000 halíře per week
        $storage = $this->createStorage($storageType, $place);

        $this->assertSame(10000, $storage->getEffectivePricePerWeek());
    }

    public function testGetEffectivePricePerWeekReturnsCustomPriceWhenSet(): void
    {
        $place = $this->createPlace();
        $storageType = $this->createStorageType(); // 10000 halíře per week
        $storage = $this->createStorage($storageType, $place);

        $storage->updatePrices(15000, 50000, new \DateTimeImmutable());

        $this->assertSame(15000, $storage->getEffectivePricePerWeek());
    }

    public function testGetEffectivePricePerMonthReturnsStorageTypePriceWhenNoCustomPrice(): void
    {
        $place = $this->createPlace();
        $storageType = $this->createStorageType(); // 35000 halíře per month
        $storage = $this->createStorage($storageType, $place);

        $this->assertSame(35000, $storage->getEffectivePricePerMonth());
    }

    public function testGetEffectivePricePerMonthReturnsCustomPriceWhenSet(): void
    {
        $place = $this->createPlace();
        $storageType = $this->createStorageType(); // 35000 halíře per month
        $storage = $this->createStorage($storageType, $place);

        $storage->updatePrices(15000, 50000, new \DateTimeImmutable());

        $this->assertSame(50000, $storage->getEffectivePricePerMonth());
    }

    public function testUpdatePrices(): void
    {
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place);
        $now = new \DateTimeImmutable();

        $this->assertNull($storage->pricePerWeek);
        $this->assertNull($storage->pricePerMonth);

        $storage->updatePrices(12000, 40000, $now);

        $this->assertSame(12000, $storage->pricePerWeek);
        $this->assertSame(40000, $storage->pricePerMonth);
        $this->assertSame($now, $storage->updatedAt);
    }

    public function testUpdatePricesToNull(): void
    {
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place);
        $now = new \DateTimeImmutable();

        $storage->updatePrices(12000, 40000, $now);
        $this->assertSame(12000, $storage->pricePerWeek);
        $this->assertSame(40000, $storage->pricePerMonth);

        $storage->updatePrices(null, null, $now);

        $this->assertNull($storage->pricePerWeek);
        $this->assertNull($storage->pricePerMonth);
    }

    public function testHasCustomPricesReturnsFalseWhenNoCustomPrices(): void
    {
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place);

        $this->assertFalse($storage->hasCustomPrices());
    }

    public function testHasCustomPricesReturnsTrueWhenCustomPricesSet(): void
    {
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place);

        $storage->updatePrices(12000, 40000, new \DateTimeImmutable());

        $this->assertTrue($storage->hasCustomPrices());
    }

    public function testHasCustomPricesReturnsTrueWhenOnlyWeeklyPriceSet(): void
    {
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place);

        $storage->updatePrices(12000, null, new \DateTimeImmutable());

        $this->assertTrue($storage->hasCustomPrices());
    }

    public function testGetEffectivePricePerWeekInCzk(): void
    {
        $place = $this->createPlace();
        $storageType = $this->createStorageType(); // 10000 halíře = 100 CZK
        $storage = $this->createStorage($storageType, $place);

        $this->assertSame(100.0, $storage->getEffectivePricePerWeekInCzk());
    }

    public function testGetEffectivePricePerMonthInCzk(): void
    {
        $place = $this->createPlace();
        $storageType = $this->createStorageType(); // 35000 halíře = 350 CZK
        $storage = $this->createStorage($storageType, $place);

        $this->assertSame(350.0, $storage->getEffectivePricePerMonthInCzk());
    }

    public function testNewStorageIsNotDeleted(): void
    {
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place);

        $this->assertFalse($storage->isDeleted());
        $this->assertNull($storage->deletedAt);
    }

    public function testSoftDelete(): void
    {
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $storage->softDelete($now);

        $this->assertTrue($storage->isDeleted());
        $this->assertSame($now, $storage->deletedAt);
        $this->assertSame($now, $storage->updatedAt);
    }
}
