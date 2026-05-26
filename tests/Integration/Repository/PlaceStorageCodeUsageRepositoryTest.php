<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Place;
use App\Entity\PlaceStorageCodeUsage;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Enum\PlaceType;
use App\Repository\PlaceStorageCodeUsageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class PlaceStorageCodeUsageRepositoryTest extends KernelTestCase
{
    private PlaceStorageCodeUsageRepository $repository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->repository = $container->get(PlaceStorageCodeUsageRepository::class);
        $this->entityManager = $container->get('doctrine')->getManager();
    }

    public function testSaveAndFindCodes(): void
    {
        $place = $this->createPlace('Test Save');
        $this->repository->save($this->createUsage($place, '0001'));
        $this->repository->save($this->createUsage($place, '0002'));
        $this->entityManager->flush();

        $codes = $this->repository->findCodesForPlace($place);
        sort($codes);
        $this->assertSame(['0001', '0002'], $codes);
    }

    public function testExistsForPlace(): void
    {
        $place = $this->createPlace('Test Exists');
        $this->repository->save($this->createUsage($place, '0007'));
        $this->entityManager->flush();

        $this->assertTrue($this->repository->existsForPlace($place, '0007'));
        $this->assertFalse($this->repository->existsForPlace($place, '0008'));
    }

    public function testReleaseUnusedDeletesStaleHistoryButKeepsCodesStillAssigned(): void
    {
        $place = $this->createPlace('Test Release');
        $storageType = $this->createStorageType($place);

        $assignedStorage = $this->createStorage($place, $storageType, 'A1');
        $assignedStorage->updateLockCode('0042', new \DateTimeImmutable());
        $this->entityManager->persist($assignedStorage);

        $this->repository->save($this->createUsage($place, '0042'));
        $this->repository->save($this->createUsage($place, '0099'));
        $this->repository->save($this->createUsage($place, 'ABC')); // non-numeric stale value
        $this->entityManager->flush();

        $deleted = $this->repository->releaseUnusedForPlace($place);
        $this->entityManager->clear();

        $this->assertSame(2, $deleted);

        $remaining = $this->repository->findCodesForPlace(
            $this->entityManager->find(Place::class, $place->id),
        );
        $this->assertSame(['0042'], $remaining);
    }

    public function testReleaseUnusedIsScopedPerPlace(): void
    {
        $placeA = $this->createPlace('Place A');
        $placeB = $this->createPlace('Place B');

        $this->repository->save($this->createUsage($placeA, '0001'));
        $this->repository->save($this->createUsage($placeB, '0001'));
        $this->entityManager->flush();

        $deletedA = $this->repository->releaseUnusedForPlace($placeA);
        $this->entityManager->clear();

        $this->assertSame(1, $deletedA);

        $remainingB = $this->repository->findCodesForPlace(
            $this->entityManager->find(Place::class, $placeB->id),
        );
        $this->assertSame(['0001'], $remainingB);
    }

    private function createPlace(string $name): Place
    {
        $now = new \DateTimeImmutable();
        $place = new Place(
            id: Uuid::v7(),
            name: $name,
            address: 'Test',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $now,
            type: PlaceType::FAJNE_SKLADY,
        );
        $place->updateStorageCodeConfig(true, 4, 0, 9999, $now);
        $this->entityManager->persist($place);
        $this->entityManager->flush();

        return $place;
    }

    private function createStorageType(Place $place): StorageType
    {
        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Test Type',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            defaultPricePerMonthLongTerm: 35000,
            defaultPricePerYear: 35000 * 12,
            createdAt: new \DateTimeImmutable(),
        );
        $this->entityManager->persist($storageType);

        return $storageType;
    }

    private function createStorage(Place $place, StorageType $storageType, string $number): Storage
    {
        return new Storage(
            id: Uuid::v7(),
            number: $number,
            coordinates: ['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createUsage(Place $place, string $code): PlaceStorageCodeUsage
    {
        return new PlaceStorageCodeUsage(
            id: Uuid::v7(),
            place: $place,
            code: $code,
            usedAt: new \DateTimeImmutable(),
        );
    }
}
