<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Place;
use App\Entity\StorageType;
use App\Repository\StorageTypeRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Covers the public-ordering filter: an admin-only storage type must never be
 * returned by findPubliclyOrderableByPlace (the source for every customer-facing
 * surface), but must still be visible to the admin-facing listing methods.
 */
final class StorageTypeRepositoryTest extends KernelTestCase
{
    private const string ADMIN_ONLY_NAME = 'Admin box (skrytý)';

    private StorageTypeRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->repository = $container->get(StorageTypeRepository::class);
    }

    public function testPubliclyOrderableExcludesAdminOnlyType(): void
    {
        $place = $this->prahaCentrum();

        $names = $this->names($this->repository->findPubliclyOrderableByPlace($place));

        self::assertNotContains(self::ADMIN_ONLY_NAME, $names, 'Admin-only type must not be publicly orderable.');
        // A normal active type at the same place is still offered.
        self::assertContains('Maly box', $names);
    }

    public function testActiveByPlaceStillIncludesAdminOnlyType(): void
    {
        $place = $this->prahaCentrum();

        $names = $this->names($this->repository->findActiveByPlace($place));

        // The admin-facing listing (storage form, canvas) must still see the type.
        self::assertContains(self::ADMIN_ONLY_NAME, $names);
    }

    public function testFindByPlaceStillIncludesAdminOnlyType(): void
    {
        $place = $this->prahaCentrum();

        $names = $this->names($this->repository->findByPlace($place));

        // Admin onboarding lists types via findByPlace and must still see the type.
        self::assertContains(self::ADMIN_ONLY_NAME, $names);
    }

    /**
     * @param StorageType[] $storageTypes
     *
     * @return string[]
     */
    private function names(array $storageTypes): array
    {
        return array_map(static fn (StorageType $type): string => $type->name, $storageTypes);
    }

    private function prahaCentrum(): Place
    {
        $container = static::getContainer();
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $place = $doctrine->getManager()->getRepository(Place::class)
            ->findOneBy(['name' => 'Sklad Praha - Centrum']);
        \assert($place instanceof Place, 'Fixture place "Sklad Praha - Centrum" not found');

        return $place;
    }
}
