<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exception;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Exception\StorageCannotBeDeleted;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class StorageCannotBeDeletedTest extends TestCase
{
    public function testBecauseItIsOccupied(): void
    {
        $storage = $this->createStorage('A1');

        $exception = StorageCannotBeDeleted::becauseItIsOccupied($storage);

        $this->assertStringContainsString('A1', $exception->getMessage());
        $this->assertStringContainsString('obsazený', $exception->getMessage());
    }

    public function testBecauseItIsReserved(): void
    {
        $storage = $this->createStorage('B2');

        $exception = StorageCannotBeDeleted::becauseItIsReserved($storage);

        $this->assertStringContainsString('B2', $exception->getMessage());
        $this->assertStringContainsString('rezervaci', $exception->getMessage());
    }

    private function createStorage(string $number): Storage
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

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Test Type',
            innerWidth: 100,
            innerHeight: 200,
            innerLength: 150,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            defaultPricePerMonthLongTerm: 35000,
            defaultPricePerYear: 35000 * 12,
            createdAt: $now,
        );

        return new Storage(
            id: Uuid::v7(),
            number: $number,
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $now,
        );
    }
}
