<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Place;
use App\Entity\PlaceStorageCodeUsage;
use App\Entity\Storage;
use App\Enum\StorageCodeUsageType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

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

    public function remove(PlaceStorageCodeUsage $usage): void
    {
        $this->entityManager->remove($usage);
    }

    public function find(Uuid $id): ?PlaceStorageCodeUsage
    {
        return $this->entityManager->find(PlaceStorageCodeUsage::class, $id);
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

    public function findOneByPlaceAndCode(Place $place, string $code): ?PlaceStorageCodeUsage
    {
        return $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(PlaceStorageCodeUsage::class, 'u')
            ->where('u.place = :place')
            ->andWhere('u.code = :code')
            ->setParameter('place', $place)
            ->setParameter('code', $code)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Codes are zero-padded to a fixed length, so string order equals numeric order.
     *
     * @return list<PlaceStorageCodeUsage>
     */
    public function findForPlace(Place $place): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(PlaceStorageCodeUsage::class, 'u')
            ->where('u.place = :place')
            ->orderBy('u.code', 'ASC')
            ->setParameter('place', $place)
            ->getQuery()
            ->getResult();
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
     * Delete USED usage rows whose code is NOT currently the lockCode of any
     * non-deleted Storage at the place. EXCLUDED rows (system codes) are never
     * touched — un-excluding is an explicit per-row action, not part of Reset.
     * Returns the number of rows deleted.
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
            ->andWhere('u.type = :used')
            ->andWhere(sprintf('NOT EXISTS (%s)', $subQuery))
            ->setParameter('place', $place)
            ->setParameter('used', StorageCodeUsageType::USED)
            ->getQuery()
            ->execute();
    }
}
