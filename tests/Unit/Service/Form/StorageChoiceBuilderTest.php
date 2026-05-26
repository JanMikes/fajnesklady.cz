<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Form;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Repository\StorageRepository;
use App\Service\Form\StorageChoiceBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class StorageChoiceBuilderTest extends TestCase
{
    public function testGroupAndSortGroupsByPlaceAndType(): void
    {
        $praha = $this->createPlace('Sklad Praha');
        $brno = $this->createPlace('Sklad Brno');
        $small = $this->createStorageType($praha, 'Maly box');
        $premium = $this->createStorageType($brno, 'Premium box');

        $storages = [
            $this->createStorage($praha, $small, 'A2'),
            $this->createStorage($brno, $premium, 'P1'),
            $this->createStorage($praha, $small, 'A10'),
            $this->createStorage($praha, $small, 'A1'),
        ];

        $builder = new StorageChoiceBuilder($this->createStub(StorageRepository::class));
        $grouped = $builder->groupAndSort($storages);

        // Optgroups sorted alphabetically by "Place — Type" label.
        $this->assertSame(
            ['Sklad Brno — Premium box', 'Sklad Praha — Maly box'],
            array_keys($grouped),
        );

        // Inner options sorted by natural number ordering — A2 before A10.
        $this->assertSame(['A1', 'A2', 'A10'], array_keys($grouped['Sklad Praha — Maly box']));
        $this->assertSame(['P1'], array_keys($grouped['Sklad Brno — Premium box']));
    }

    public function testGroupAndSortReturnsEmptyArrayForNoStorages(): void
    {
        $builder = new StorageChoiceBuilder($this->createStub(StorageRepository::class));

        $this->assertSame([], $builder->groupAndSort([]));
    }

    private function createPlace(string $name): Place
    {
        return new Place(
            id: Uuid::v7(),
            name: $name,
            address: 'Test Address',
            city: 'Test',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createStorageType(Place $place, string $name): StorageType
    {
        return new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: $name,
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            defaultPricePerMonthLongTerm: 35000,
            defaultPricePerYear: 35000 * 12,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createStorage(Place $place, StorageType $type, string $number): Storage
    {
        return new Storage(
            id: Uuid::v7(),
            number: $number,
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $type,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );
    }
}
