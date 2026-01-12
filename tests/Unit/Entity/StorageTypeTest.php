<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\StorageType;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class StorageTypeTest extends TestCase
{
    private function createUser(string $email = 'owner@example.com'): User
    {
        return new User(Uuid::v7(), $email, 'password', 'Test Owner', new \DateTimeImmutable());
    }

    public function testCreateStorageType(): void
    {
        $now = new \DateTimeImmutable();
        $owner = $this->createUser();

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Small Box',
            width: '1.5',
            height: '2.0',
            length: '2.5',
            pricePerWeek: 15000,
            pricePerMonth: 50000,
            owner: $owner,
            createdAt: $now,
        );

        $this->assertInstanceOf(Uuid::class, $storageType->id);
        $this->assertSame('Small Box', $storageType->name);
        $this->assertSame('1.5', $storageType->width);
        $this->assertSame('2.0', $storageType->height);
        $this->assertSame('2.5', $storageType->length);
        $this->assertSame(15000, $storageType->pricePerWeek);
        $this->assertSame(50000, $storageType->pricePerMonth);
        $this->assertSame($owner, $storageType->owner);
        $this->assertSame($now, $storageType->createdAt);
        $this->assertSame($now, $storageType->updatedAt);
    }

    public function testGetPricePerWeekInCzk(): void
    {
        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            width: '1.0',
            height: '1.0',
            length: '1.0',
            pricePerWeek: 15000,
            pricePerMonth: 50000,
            owner: $this->createUser(),
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertSame(150.0, $storageType->getPricePerWeekInCzk());
    }

    public function testGetPricePerMonthInCzk(): void
    {
        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            width: '1.0',
            height: '1.0',
            length: '1.0',
            pricePerWeek: 15000,
            pricePerMonth: 50000,
            owner: $this->createUser(),
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertSame(500.0, $storageType->getPricePerMonthInCzk());
    }

    public function testGetVolume(): void
    {
        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            width: '2.0',
            height: '3.0',
            length: '4.0',
            pricePerWeek: 15000,
            pricePerMonth: 50000,
            owner: $this->createUser(),
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertSame(24.0, $storageType->getVolume());
    }

    public function testGetDimensions(): void
    {
        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            width: '2.00',
            height: '3.00',
            length: '4.00',
            pricePerWeek: 15000,
            pricePerMonth: 50000,
            owner: $this->createUser(),
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertSame('2.00 x 3.00 x 4.00 m', $storageType->getDimensions());
    }

    public function testUpdateDetails(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $updatedAt = new \DateTimeImmutable('2024-01-01 11:00:00');
        $owner = $this->createUser();

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Original',
            width: '1.0',
            height: '1.0',
            length: '1.0',
            pricePerWeek: 10000,
            pricePerMonth: 30000,
            owner: $owner,
            createdAt: $createdAt,
        );

        $storageType->updateDetails(
            name: 'Updated',
            width: '2.0',
            height: '2.0',
            length: '2.0',
            pricePerWeek: 20000,
            pricePerMonth: 60000,
            now: $updatedAt,
        );

        $this->assertSame('Updated', $storageType->name);
        $this->assertSame('2.0', $storageType->width);
        $this->assertSame('2.0', $storageType->height);
        $this->assertSame('2.0', $storageType->length);
        $this->assertSame(20000, $storageType->pricePerWeek);
        $this->assertSame(60000, $storageType->pricePerMonth);
        $this->assertSame($createdAt, $storageType->createdAt);
        $this->assertSame($updatedAt, $storageType->updatedAt);
    }

    public function testIsOwnedByReturnsTrueForOwner(): void
    {
        $now = new \DateTimeImmutable();
        $owner = $this->createUser();

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            width: '1.0',
            height: '1.0',
            length: '1.0',
            pricePerWeek: 10000,
            pricePerMonth: 30000,
            owner: $owner,
            createdAt: $now,
        );

        $this->assertTrue($storageType->isOwnedBy($owner));
    }

    public function testIsOwnedByReturnsFalseForDifferentUser(): void
    {
        $now = new \DateTimeImmutable();
        $owner = $this->createUser('owner@example.com');
        $otherUser = $this->createUser('other@example.com');

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            width: '1.0',
            height: '1.0',
            length: '1.0',
            pricePerWeek: 10000,
            pricePerMonth: 30000,
            owner: $owner,
            createdAt: $now,
        );

        $this->assertFalse($storageType->isOwnedBy($otherUser));
    }

    public function testCreatedAtIsImmutable(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $updatedAt = new \DateTimeImmutable('2024-01-01 11:00:00');
        $owner = $this->createUser();

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test',
            width: '1.0',
            height: '1.0',
            length: '1.0',
            pricePerWeek: 10000,
            pricePerMonth: 30000,
            owner: $owner,
            createdAt: $createdAt,
        );

        $storageType->updateDetails(
            name: 'Updated',
            width: '1.0',
            height: '1.0',
            length: '1.0',
            pricePerWeek: 10000,
            pricePerMonth: 30000,
            now: $updatedAt,
        );

        $this->assertSame($createdAt, $storageType->createdAt);
    }
}
