<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Form\StorageFormData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class StorageFormDataTest extends TestCase
{
    public function testGetCoordinatesReturnsCorrectArray(): void
    {
        $formData = new StorageFormData();
        $formData->coordinateX = 10;
        $formData->coordinateY = 20;
        $formData->coordinateWidth = 100;
        $formData->coordinateHeight = 150;
        $formData->coordinateRotation = 45;

        $expected = [
            'x' => 10,
            'y' => 20,
            'width' => 100,
            'height' => 150,
            'rotation' => 45,
        ];

        $this->assertSame($expected, $formData->getCoordinates());
    }

    public function testGetCoordinatesWithDefaultValues(): void
    {
        $formData = new StorageFormData();

        $expected = [
            'x' => 0,
            'y' => 0,
            'width' => 50,
            'height' => 50,
            'rotation' => 0,
        ];

        $this->assertSame($expected, $formData->getCoordinates());
    }

    public function testFromStorageCreatesFormDataCorrectly(): void
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

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test Storage Type',
            innerWidth: 100,
            innerHeight: 200,
            innerLength: 150,
            pricePerWeek: 10000,
            pricePerMonth: 35000,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );

        $coordinates = ['x' => 25, 'y' => 50, 'width' => 75, 'height' => 100, 'rotation' => 90];
        $storage = new Storage(
            id: Uuid::v7(),
            number: 'B5',
            coordinates: $coordinates,
            storageType: $storageType,
            createdAt: new \DateTimeImmutable(),
        );

        $formData = StorageFormData::fromStorage($storage);

        $this->assertSame('B5', $formData->number);
        $this->assertSame($storageType->id->toRfc4122(), $formData->storageTypeId);
        $this->assertSame(25, $formData->coordinateX);
        $this->assertSame(50, $formData->coordinateY);
        $this->assertSame(75, $formData->coordinateWidth);
        $this->assertSame(100, $formData->coordinateHeight);
        $this->assertSame(90, $formData->coordinateRotation);
    }
}
