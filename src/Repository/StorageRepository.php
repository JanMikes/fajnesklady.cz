<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\StorageStatus;
use App\Exception\StorageNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class StorageRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Storage $storage): void
    {
        $this->entityManager->persist($storage);
    }

    public function delete(Storage $storage): void
    {
        $this->entityManager->remove($storage);
    }

    public function get(Uuid $id): Storage
    {
        return $this->entityManager->find(Storage::class, $id)
            ?? throw StorageNotFound::withId($id);
    }

    /**
     * @return Storage[]
     */
    public function findByStorageType(StorageType $storageType): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.storageType = :storageType')
            ->setParameter('storageType', $storageType)
            ->orderBy('s.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Storage[]
     */
    public function findByPlace(Place $place): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->join('s.storageType', 'st')
            ->where('st.place = :place')
            ->setParameter('place', $place)
            ->orderBy('s.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Storage[]
     */
    public function findAvailableByStorageType(StorageType $storageType): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.storageType = :storageType')
            ->andWhere('s.status = :status')
            ->setParameter('storageType', $storageType)
            ->setParameter('status', StorageStatus::AVAILABLE)
            ->orderBy('s.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countByStorageType(StorageType $storageType): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.storageType = :storageType')
            ->setParameter('storageType', $storageType)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAvailableByStorageType(StorageType $storageType): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.storageType = :storageType')
            ->andWhere('s.status = :status')
            ->setParameter('storageType', $storageType)
            ->setParameter('status', StorageStatus::AVAILABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByOwner(User $owner): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->join('s.storageType', 'st')
            ->join('st.place', 'p')
            ->where('p.owner = :owner')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countOccupiedByOwner(User $owner): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->join('s.storageType', 'st')
            ->join('st.place', 'p')
            ->where('p.owner = :owner')
            ->andWhere('s.status = :status')
            ->setParameter('owner', $owner)
            ->setParameter('status', StorageStatus::OCCUPIED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAvailableByOwner(User $owner): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->join('s.storageType', 'st')
            ->join('st.place', 'p')
            ->where('p.owner = :owner')
            ->andWhere('s.status = :status')
            ->setParameter('owner', $owner)
            ->setParameter('status', StorageStatus::AVAILABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
