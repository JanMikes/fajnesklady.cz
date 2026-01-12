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

    private function createStorageType(Place $place): StorageType
    {
        return new StorageType(
            id: Uuid::v7(),
            name: 'Small Box',
            width: 100,
            height: 100,
            length: 100,
            pricePerWeek: 10000,
            pricePerMonth: 35000,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createStorage(StorageType $storageType): Storage
    {
        return new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            createdAt: new \DateTimeImmutable(),
        );
    }

    public function testCreateStorage(): void
    {
        $now = new \DateTimeImmutable();
        $owner = $this->createUser();
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 10, 'y' => 20, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            createdAt: $now,
        );

        $this->assertInstanceOf(Uuid::class, $storage->id);
        $this->assertSame('A1', $storage->number);
        $this->assertSame(['x' => 10, 'y' => 20, 'width' => 100, 'height' => 100, 'rotation' => 0], $storage->coordinates);
        $this->assertSame($storageType, $storage->storageType);
        $this->assertSame(StorageStatus::AVAILABLE, $storage->status);
        $this->assertSame($now, $storage->createdAt);
        $this->assertSame($now, $storage->updatedAt);
    }

    public function testDefaultStatusIsAvailable(): void
    {
        $owner = $this->createUser();
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType);

        $this->assertSame(StorageStatus::AVAILABLE, $storage->status);
        $this->assertTrue($storage->isAvailable());
        $this->assertFalse($storage->isReserved());
        $this->assertFalse($storage->isOccupied());
    }

    public function testReserve(): void
    {
        $owner = $this->createUser();
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType);
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
        $owner = $this->createUser();
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType);
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
        $owner = $this->createUser();
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType);
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
        $owner = $this->createUser();
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType);
        $now = new \DateTimeImmutable();

        $storage->markUnavailable($now);

        $this->assertSame(StorageStatus::MANUALLY_UNAVAILABLE, $storage->status);
        $this->assertFalse($storage->isAvailable());
        $this->assertSame($now, $storage->updatedAt);
    }

    public function testStatusTransitions(): void
    {
        $owner = $this->createUser();
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType);

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
        $owner = $this->createUser();
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
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
        $owner = $this->createUser();
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType);

        $this->assertSame($place, $storage->getPlace());
    }

    public function testIsOwnedByReturnsTrueForOwner(): void
    {
        $owner = $this->createUser();
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType);

        $this->assertTrue($storage->isOwnedBy($owner));
    }

    public function testIsOwnedByReturnsFalseForDifferentUser(): void
    {
        $owner = $this->createUser('owner@example.com');
        $otherUser = $this->createUser('other@example.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType);

        $this->assertFalse($storage->isOwnedBy($otherUser));
    }
}
