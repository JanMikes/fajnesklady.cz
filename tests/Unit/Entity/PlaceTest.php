<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Place;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class PlaceTest extends TestCase
{
    public function testCreatePlace(): void
    {
        $now = new \DateTimeImmutable();

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address 123',
            city: 'Praha',
            postalCode: '110 00',
            description: 'Test description',
            createdAt: $now,
        );

        $this->assertInstanceOf(Uuid::class, $place->id);
        $this->assertSame('Test Place', $place->name);
        $this->assertSame('Test Address 123', $place->address);
        $this->assertSame('Praha', $place->city);
        $this->assertSame('110 00', $place->postalCode);
        $this->assertSame('Test description', $place->description);
        $this->assertSame($now, $place->createdAt);
        $this->assertSame($now, $place->updatedAt);
    }

    public function testCreatePlaceWithNullDescription(): void
    {
        $now = new \DateTimeImmutable();

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address 123',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $now,
        );

        $this->assertNull($place->description);
    }

    public function testUpdateDetails(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $updatedAt = new \DateTimeImmutable('2024-01-01 11:00:00');

        $place = new Place(
            id: Uuid::v7(),
            name: 'Original Name',
            address: 'Original Address',
            city: 'Praha',
            postalCode: '110 00',
            description: 'Original Description',
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

    public function testCreatedAtIsImmutable(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $updatedAt = new \DateTimeImmutable('2024-01-01 11:00:00');

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
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

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $now,
        );

        $this->assertTrue($place->isActive);
        $this->assertSame(0, $place->daysInAdvance);
        $this->assertNull($place->latitude);
        $this->assertNull($place->longitude);
        $this->assertNull($place->mapImagePath);
    }

    public function testActivateDeactivate(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $createdAt,
        );

        $this->assertTrue($place->isActive);

        $place->deactivate(new \DateTimeImmutable('2024-01-01 11:00:00'));
        $this->assertFalse($place->isActive);

        $place->activate(new \DateTimeImmutable('2024-01-01 12:00:00'));
        $this->assertTrue($place->isActive);
    }
}
