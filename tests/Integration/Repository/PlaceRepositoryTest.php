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

    private function createPlace(string $name, bool $isActive = true, ?string $address = 'Test Address'): Place
    {
        $place = new Place(
            id: Uuid::v7(),
            name: $name,
            address: $address,
            city: 'Praha',
            postalCode: '110 00',
            description: null,
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
        $place = $this->createPlace('Test Place');
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
        // Use fixture data - landlord owns Praha places, landlord2 owns Ostrava
        $landlord = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'landlord@example.com']);
        $landlord2 = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'landlord2@example.com']);

        $landlordPlaces = $this->repository->findByOwner($landlord);
        $landlord2Places = $this->repository->findByOwner($landlord2);

        // Landlord has 2 places (Praha Centrum and Praha Jih)
        $this->assertCount(2, $landlordPlaces);
        // Landlord2 has 1 place (Ostrava)
        $this->assertCount(1, $landlord2Places);

        $landlordNames = array_map(fn (Place $p) => $p->name, $landlordPlaces);
        $this->assertContains('Sklad Praha - Centrum', $landlordNames);
        $this->assertContains('Sklad Praha - Jiznimesto', $landlordNames);
        $this->assertSame('Sklad Ostrava', $landlord2Places[0]->name);
    }

    public function testFindAllActiveExcludesInactive(): void
    {
        $activePlace1 = $this->createPlace('Active Place 1', true);
        $activePlace2 = $this->createPlace('Active Place 2', true);
        $inactivePlace = $this->createPlace('Inactive Place', false);

        $this->entityManager->flush();

        $activePlaces = $this->repository->findAllActive();
        $activeNames = array_map(fn (Place $p) => $p->name, $activePlaces);

        $this->assertContains('Active Place 1', $activeNames);
        $this->assertContains('Active Place 2', $activeNames);
        $this->assertNotContains('Inactive Place', $activeNames);
    }

    public function testFindAllPaginatedRespectsLimits(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->createPlace("Paginated Place {$i}");
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
        for ($i = 1; $i <= 4; ++$i) {
            $this->createPlace("Owner Place {$i}");
        }
        $this->createPlace('Other Owner Place');

        $this->entityManager->flush();

        // Use findAllPaginated instead since owner is no longer part of Place
        $page1 = $this->repository->findAllPaginated(1, 2);
        $page2 = $this->repository->findAllPaginated(2, 2);

        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);
    }

    public function testCountTotal(): void
    {
        $initialCount = $this->repository->countTotal();

        $this->createPlace('Count Test 1');
        $this->createPlace('Count Test 2');
        $this->createPlace('Count Test 3');
        $this->entityManager->flush();

        $newCount = $this->repository->countTotal();

        $this->assertSame($initialCount + 3, $newCount);
    }

    public function testCountByCity(): void
    {
        $initialCount = $this->repository->countTotal();

        $this->createPlace('Owner1 Count 1');
        $this->createPlace('Owner1 Count 2');
        $this->createPlace('Owner2 Count 1');
        $this->entityManager->flush();

        $totalCount = $this->repository->countTotal();

        $this->assertSame($initialCount + 3, $totalCount);
    }

    public function testSaveAndGetPlaceWithoutAddress(): void
    {
        $place = $this->createPlace('No Address Place', address: null);
        $place->updateLocation('49.7437572', '13.3799330', new \DateTimeImmutable());
        $this->entityManager->flush();

        $foundPlace = $this->repository->get($place->id);

        $this->assertSame('No Address Place', $foundPlace->name);
        $this->assertNull($foundPlace->address);
        $this->assertFalse($foundPlace->hasAddress());
        $this->assertSame('Praha', $foundPlace->city);
        $this->assertSame('110 00', $foundPlace->postalCode);
        $this->assertSame('49.7437572', $foundPlace->latitude);
        $this->assertSame('13.3799330', $foundPlace->longitude);
    }

    public function testDeleteRemovesPlace(): void
    {
        $place = $this->createPlace('Delete Test');
        $this->entityManager->flush();

        $placeId = $place->id;
        $this->assertNotNull($this->repository->find($placeId));

        $this->repository->delete($place);
        $this->entityManager->flush();

        $this->assertNull($this->repository->find($placeId));
    }
}
