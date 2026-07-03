<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Repository\StorageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class StorageRepositoryTest extends KernelTestCase
{
    private StorageRepository $repository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->repository = $container->get(StorageRepository::class);
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
    }

    public function testCountAtPlaceWithoutOwnerReturnsTotal(): void
    {
        $place = $this->createPlace();
        $st = $this->createStorageType($place);
        $owner1 = $this->createUser('storage-owner-1@test.com');
        $owner2 = $this->createUser('storage-owner-2@test.com');

        $this->createStorage($st, $place, 'CAP1', $owner1);
        $this->createStorage($st, $place, 'CAP2', $owner2);
        $this->createStorage($st, $place, 'CAP3', null);
        $this->entityManager->flush();

        $this->assertSame(3, $this->repository->countAtPlace($place, null));
    }

    public function testCountAtPlaceFiltersByOwner(): void
    {
        $place = $this->createPlace();
        $st = $this->createStorageType($place);
        $owner1 = $this->createUser('storage-owner-3@test.com');
        $owner2 = $this->createUser('storage-owner-4@test.com');

        $this->createStorage($st, $place, 'OF1', $owner1);
        $this->createStorage($st, $place, 'OF2', $owner1);
        $this->createStorage($st, $place, 'OF3', $owner2);
        $this->entityManager->flush();

        $this->assertSame(2, $this->repository->countAtPlace($place, $owner1));
        $this->assertSame(1, $this->repository->countAtPlace($place, $owner2));
    }

    public function testCountStatusBucketsAtPlace(): void
    {
        $place = $this->createPlace();
        $st = $this->createStorageType($place);
        $owner = $this->createUser('storage-status@test.com');
        $now = new \DateTimeImmutable();

        $available = $this->createStorage($st, $place, 'STA1', $owner);
        $occupied = $this->createStorage($st, $place, 'STO1', $owner);
        $occupied->occupy($now);
        $blocked = $this->createStorage($st, $place, 'STB1', $owner);
        $blocked->markUnavailable($now);
        $this->entityManager->flush();
        unset($available);

        $this->assertSame(1, $this->repository->countAvailableAtPlace($place, $owner));
        $this->assertSame(1, $this->repository->countOccupiedAtPlace($place, $owner));
        $this->assertSame(1, $this->repository->countBlockedAtPlace($place, $owner));
    }

    public function testHasCoOwnersIsTrueWhenAnotherUserOwnsStorageAtPlace(): void
    {
        $place = $this->createPlace();
        $st = $this->createStorageType($place);
        $me = $this->createUser('me-coowner@test.com');
        $other = $this->createUser('other-coowner@test.com');

        $this->createStorage($st, $place, 'CO1', $me);
        $this->createStorage($st, $place, 'CO2', $other);
        $this->entityManager->flush();

        $this->assertTrue($this->repository->hasCoOwners($place, $me));
    }

    public function testFindByPlaceSortsNumbersNaturally(): void
    {
        $place = $this->createPlace();
        $st = $this->createStorageType($place);

        foreach (['11', '2', '1', '12', 'A2', 'A10'] as $number) {
            $this->createStorage($st, $place, $number, null);
        }
        $this->entityManager->flush();

        $numbers = array_map(
            static fn (Storage $storage): string => $storage->number,
            $this->repository->findByPlace($place),
        );

        $this->assertSame(['1', '2', '11', '12', 'A2', 'A10'], $numbers);
    }

    public function testHasCoOwnersIsFalseWhenOnlyMineOrUnownedStoragesExist(): void
    {
        $place = $this->createPlace();
        $st = $this->createStorageType($place);
        $me = $this->createUser('solo-coowner@test.com');

        $this->createStorage($st, $place, 'NC1', $me);
        $this->createStorage($st, $place, 'NC2', null);
        $this->entityManager->flush();

        $this->assertFalse($this->repository->hasCoOwners($place, $me));
    }

    private function createUser(string $email): User
    {
        $user = new User(Uuid::v7(), $email, 'password', 'Test', 'User', new \DateTimeImmutable());
        $this->entityManager->persist($user);

        return $user;
    }

    private function createPlace(): Place
    {
        $place = new Place(
            id: Uuid::v7(),
            name: 'Place '.bin2hex(random_bytes(3)),
            address: 'Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable(),
        );
        $this->entityManager->persist($place);

        return $place;
    }

    private function createStorageType(Place $place, string $name = 'Test Type'): StorageType
    {
        $storageType = new StorageType(
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
        $this->entityManager->persist($storageType);

        return $storageType;
    }

    private function createStorage(StorageType $storageType, Place $place, string $number, ?User $owner): Storage
    {
        $storage = new Storage(
            id: Uuid::v7(),
            number: $number,
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
            owner: $owner,
        );
        $this->entityManager->persist($storage);

        return $storage;
    }
}
