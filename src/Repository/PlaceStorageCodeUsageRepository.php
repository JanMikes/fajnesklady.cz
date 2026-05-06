<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Place;
use App\Entity\PlaceStorageCodeUsage;
use App\Entity\Storage;
use Doctrine\ORM\EntityManagerInterface;

class PlaceStorageCodeUsageRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(PlaceStorageCodeUsage $usage): void
    {
        $this->entityManager->persist($usage);
    }

    public function existsForPlace(Place $place, string $code): bool
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('1')
            ->from(PlaceStorageCodeUsage::class, 'u')
            ->where('u.place = :place')
            ->andWhere('u.code = :code')
            ->setParameter('place', $place)
            ->setParameter('code', $code)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return null !== $result;
    }

    /**
     * @return string[]
     */
    public function findCodesForPlace(Place $place): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('u.code')
            ->from(PlaceStorageCodeUsage::class, 'u')
            ->where('u.place = :place')
            ->setParameter('place', $place)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): string => (string) $r['code'], $rows);
    }

    /**
     * Delete usage rows whose code is NOT currently the lockCode of any
     * non-deleted Storage at the place. Returns the number of rows deleted.
     */
    public function releaseUnusedForPlace(Place $place): int
    {
        $subQuery = $this->entityManager->createQueryBuilder()
            ->select('1')
            ->from(Storage::class, 's')
            ->where('s.place = :place')
            ->andWhere('s.deletedAt IS NULL')
            ->andWhere('s.lockCode = u.code')
            ->getDQL();

        return (int) $this->entityManager->createQueryBuilder()
            ->delete(PlaceStorageCodeUsage::class, 'u')
            ->where('u.place = :place')
            ->andWhere(sprintf('NOT EXISTS (%s)', $subQuery))
            ->setParameter('place', $place)
            ->getQuery()
            ->execute();
    }
}
