<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Place;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class PlaceTest extends TestCase
{
    private function createUser(string $email = 'owner@example.com'): User
    {
        return new User(Uuid::v7(), $email, 'password', 'Test', 'Owner', new \DateTimeImmutable());
    }

    public function testCreatePlace(): void
    {
        $now = new \DateTimeImmutable();
        $owner = $this->createUser();

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address 123',
            city: 'Praha',
            postalCode: '110 00',
            description: 'Test description',
            owner: $owner,
            createdAt: $now,
        );

        $this->assertInstanceOf(Uuid::class, $place->id);
        $this->assertSame('Test Place', $place->name);
        $this->assertSame('Test Address 123', $place->address);
        $this->assertSame('Praha', $place->city);
        $this->assertSame('110 00', $place->postalCode);
        $this->assertSame('Test description', $place->description);
        $this->assertSame($owner, $place->owner);
        $this->assertSame($now, $place->createdAt);
        $this->assertSame($now, $place->updatedAt);
    }

    public function testCreatePlaceWithNullDescription(): void
    {
        $now = new \DateTimeImmutable();
        $owner = $this->createUser();

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address 123',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            owner: $owner,
            createdAt: $now,
        );

        $this->assertNull($place->description);
    }

    public function testUpdateDetails(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $updatedAt = new \DateTimeImmutable('2024-01-01 11:00:00');
        $owner = $this->createUser();

        $place = new Place(
            id: Uuid::v7(),
            name: 'Original Name',
            address: 'Original Address',
            city: 'Praha',
            postalCode: '110 00',
            description: 'Original Description',
            owner: $owner,
            createdAt: $createdAt,
        );

        $place->updateDetails(
            name: 'Updated Name',
            address: 'Updated Address',
            city: 'Brno',
            postalCode: '602 00',
            description: 'Updated Description',
            now: $updatedAt,
        );

        $this->assertSame('Updated Name', $place->name);
        $this->assertSame('Updated Address', $place->address);
        $this->assertSame('Brno', $place->city);
        $this->assertSame('602 00', $place->postalCode);
        $this->assertSame('Updated Description', $place->description);
        $this->assertSame($createdAt, $place->createdAt);
        $this->assertSame($updatedAt, $place->updatedAt);
    }

    public function testIsOwnedByReturnsTrueForOwner(): void
    {
        $now = new \DateTimeImmutable();
        $owner = $this->createUser();

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            owner: $owner,
            createdAt: $now,
        );

        $this->assertTrue($place->isOwnedBy($owner));
    }

    public function testIsOwnedByReturnsFalseForDifferentUser(): void
    {
        $now = new \DateTimeImmutable();
        $owner = $this->createUser('owner@example.com');
        $otherUser = $this->createUser('other@example.com');

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            owner: $owner,
            createdAt: $now,
        );

        $this->assertFalse($place->isOwnedBy($otherUser));
    }

    public function testCreatedAtIsImmutable(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $updatedAt = new \DateTimeImmutable('2024-01-01 11:00:00');
        $owner = $this->createUser();

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            owner: $owner,
            createdAt: $createdAt,
        );

        $place->updateDetails(
            name: 'Updated',
            address: 'Updated',
            city: 'Brno',
            postalCode: '602 00',
            description: null,
            now: $updatedAt,
        );

        $this->assertSame($createdAt, $place->createdAt);
    }

    public function testDefaultValues(): void
    {
        $now = new \DateTimeImmutable();
        $owner = $this->createUser();

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            owner: $owner,
            createdAt: $now,
        );

        $this->assertTrue($place->isActive);
        $this->assertSame(0, $place->daysInAdvance);
        $this->assertNull($place->latitude);
        $this->assertNull($place->longitude);
        $this->assertNull($place->mapImagePath);
        $this->assertNull($place->contractTemplatePath);
    }

    public function testActivateDeactivate(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $owner = $this->createUser();

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            owner: $owner,
            createdAt: $createdAt,
        );

        $this->assertTrue($place->isActive);

        $place->deactivate(new \DateTimeImmutable('2024-01-01 11:00:00'));
        $this->assertFalse($place->isActive);

        $place->activate(new \DateTimeImmutable('2024-01-01 12:00:00'));
        $this->assertTrue($place->isActive);
    }
}
