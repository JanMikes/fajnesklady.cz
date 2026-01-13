<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Place;
use App\Entity\User;
use App\Exception\PlaceNotFound;
use App\Repository\PlaceRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class PlaceRepositoryTest extends KernelTestCase
{
    private PlaceRepository $repository;
    private UserRepository $userRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->repository = $container->get(PlaceRepository::class);
        $this->userRepository = $container->get(UserRepository::class);
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
    }

    private function createUser(string $email): User
    {
        $user = new User(Uuid::v7(), $email, 'password123', 'Test', 'User', new \DateTimeImmutable());
        $this->userRepository->save($user);

        return $user;
    }

    private function createPlace(User $owner, string $name, bool $isActive = true): Place
    {
        $place = new Place(
            id: Uuid::v7(),
            name: $name,
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            owner: $owner,
            createdAt: new \DateTimeImmutable(),
        );

        if (!$isActive) {
            $place->deactivate(new \DateTimeImmutable());
        }

        $this->repository->save($place);

        return $place;
    }

    public function testSaveAndGetPlace(): void
    {
        $owner = $this->createUser('owner1@example.com');
        $place = $this->createPlace($owner, 'Test Place');
        $this->entityManager->flush();

        $foundPlace = $this->repository->get($place->id);

        $this->assertSame('Test Place', $foundPlace->name);
        $this->assertSame('Test Address', $foundPlace->address);
        $this->assertSame('Praha', $foundPlace->city);
    }

    public function testGetThrowsForNonexistent(): void
    {
        $nonexistentId = Uuid::v7();

        $this->expectException(PlaceNotFound::class);

        $this->repository->get($nonexistentId);
    }

    public function testFindReturnsNullForNonexistent(): void
    {
        $nonexistentId = Uuid::v7();

        $place = $this->repository->find($nonexistentId);

        $this->assertNull($place);
    }

    public function testFindByOwnerReturnsOnlyOwnedPlaces(): void
    {
        $owner1 = $this->createUser('landlord1@example.com');
        $owner2 = $this->createUser('landlord2@example.com');

        $place1 = $this->createPlace($owner1, 'Owner1 Place A');
        $place2 = $this->createPlace($owner1, 'Owner1 Place B');
        $place3 = $this->createPlace($owner2, 'Owner2 Place');

        $this->entityManager->flush();

        $owner1Places = $this->repository->findByOwner($owner1);
        $owner2Places = $this->repository->findByOwner($owner2);

        $this->assertCount(2, $owner1Places);
        $this->assertCount(1, $owner2Places);

        $owner1Names = array_map(fn (Place $p) => $p->name, $owner1Places);
        $this->assertContains('Owner1 Place A', $owner1Names);
        $this->assertContains('Owner1 Place B', $owner1Names);
        $this->assertSame('Owner2 Place', $owner2Places[0]->name);
    }

    public function testFindAllActiveExcludesInactive(): void
    {
        $owner = $this->createUser('landlord3@example.com');

        $activePlace1 = $this->createPlace($owner, 'Active Place 1', true);
        $activePlace2 = $this->createPlace($owner, 'Active Place 2', true);
        $inactivePlace = $this->createPlace($owner, 'Inactive Place', false);

        $this->entityManager->flush();

        $activePlaces = $this->repository->findAllActive();
        $activeNames = array_map(fn (Place $p) => $p->name, $activePlaces);

        $this->assertContains('Active Place 1', $activeNames);
        $this->assertContains('Active Place 2', $activeNames);
        $this->assertNotContains('Inactive Place', $activeNames);
    }

    public function testFindAllPaginatedRespectsLimits(): void
    {
        $owner = $this->createUser('landlord4@example.com');

        for ($i = 1; $i <= 5; ++$i) {
            $this->createPlace($owner, "Paginated Place {$i}");
        }
        $this->entityManager->flush();

        $page1 = $this->repository->findAllPaginated(1, 2);
        $page2 = $this->repository->findAllPaginated(2, 2);
        $page3 = $this->repository->findAllPaginated(3, 2);

        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);
        // Page 3 may have variable count depending on fixtures
        $this->assertLessThanOrEqual(2, count($page3));

        // Verify pages return different results
        if (count($page1) > 0 && count($page2) > 0) {
            $this->assertNotEquals($page1[0]->id, $page2[0]->id);
        }
    }

    public function testFindByOwnerPaginated(): void
    {
        $owner = $this->createUser('landlord5@example.com');
        $otherOwner = $this->createUser('other5@example.com');

        for ($i = 1; $i <= 4; ++$i) {
            $this->createPlace($owner, "Owner Place {$i}");
        }
        $this->createPlace($otherOwner, 'Other Owner Place');

        $this->entityManager->flush();

        $page1 = $this->repository->findByOwnerPaginated($owner, 1, 2);
        $page2 = $this->repository->findByOwnerPaginated($owner, 2, 2);

        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);

        // All places should belong to the owner
        foreach ($page1 as $place) {
            $this->assertTrue($place->isOwnedBy($owner));
        }
        foreach ($page2 as $place) {
            $this->assertTrue($place->isOwnedBy($owner));
        }
    }

    public function testCountTotal(): void
    {
        $initialCount = $this->repository->countTotal();

        $owner = $this->createUser('landlord6@example.com');
        $this->createPlace($owner, 'Count Test 1');
        $this->createPlace($owner, 'Count Test 2');
        $this->createPlace($owner, 'Count Test 3');
        $this->entityManager->flush();

        $newCount = $this->repository->countTotal();

        $this->assertSame($initialCount + 3, $newCount);
    }

    public function testCountByOwner(): void
    {
        $owner1 = $this->createUser('landlord7@example.com');
        $owner2 = $this->createUser('landlord8@example.com');

        $this->createPlace($owner1, 'Owner1 Count 1');
        $this->createPlace($owner1, 'Owner1 Count 2');
        $this->createPlace($owner2, 'Owner2 Count 1');
        $this->entityManager->flush();

        $owner1Count = $this->repository->countByOwner($owner1);
        $owner2Count = $this->repository->countByOwner($owner2);

        $this->assertSame(2, $owner1Count);
        $this->assertSame(1, $owner2Count);
    }

    public function testDeleteRemovesPlace(): void
    {
        $owner = $this->createUser('landlord9@example.com');
        $place = $this->createPlace($owner, 'Delete Test');
        $this->entityManager->flush();

        $placeId = $place->id;
        $this->assertNotNull($this->repository->find($placeId));

        $this->repository->delete($place);
        $this->entityManager->flush();

        $this->assertNull($this->repository->find($placeId));
    }
}
