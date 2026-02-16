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

class StorageRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

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

    public function find(Uuid $id): ?Storage
    {
        return $this->entityManager->find(Storage::class, $id);
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
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('storageType', $storageType)
            ->orderBy('s.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Storage[]
     */
    public function findByStorageTypeAndPlace(StorageType $storageType, Place $place): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.storageType = :storageType')
            ->andWhere('s.place = :place')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('storageType', $storageType)
            ->setParameter('place', $place)
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
            ->where('s.place = :place')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('place', $place)
            ->orderBy('s.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Storage[]
     */
    public function findByOwner(User $owner): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.owner = :owner')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('owner', $owner)
            ->orderBy('s.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Storage[]
     */
    public function findByOwnerAndPlace(User $owner, Place $place): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.owner = :owner')
            ->andWhere('s.place = :place')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('owner', $owner)
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
            ->andWhere('s.deletedAt IS NULL')
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
            ->andWhere('s.deletedAt IS NULL')
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
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('storageType', $storageType)
            ->setParameter('status', StorageStatus::AVAILABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByPlace(Place $place): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.place = :place')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('place', $place)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByOwnerAndPlace(User $owner, Place $place): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.owner = :owner')
            ->andWhere('s.place = :place')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('owner', $owner)
            ->setParameter('place', $place)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByOwner(User $owner): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.owner = :owner')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countOccupiedByOwner(User $owner): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.owner = :owner')
            ->andWhere('s.status = :status')
            ->andWhere('s.deletedAt IS NULL')
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
            ->where('s.owner = :owner')
            ->andWhere('s.status = :status')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('owner', $owner)
            ->setParameter('status', StorageStatus::AVAILABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countBlockedByOwner(User $owner): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.owner = :owner')
            ->andWhere('s.status = :status')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('owner', $owner)
            ->setParameter('status', StorageStatus::MANUALLY_UNAVAILABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function hasOrdersOrContracts(Storage $storage): bool
    {
        // Check for orders
        $orderCount = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from('App\Entity\Order', 'o')
            ->where('o.storage = :storage')
            ->setParameter('storage', $storage)
            ->getQuery()
            ->getSingleScalarResult();

        if ($orderCount > 0) {
            return true;
        }

        // Check for contracts
        $contractCount = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from('App\Entity\Contract', 'c')
            ->where('c.storage = :storage')
            ->setParameter('storage', $storage)
            ->getQuery()
            ->getSingleScalarResult();

        return $contractCount > 0;
    }

    /**
     * @return Storage[]
     */
    public function findAll(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.deletedAt IS NULL')
            ->orderBy('s.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Storage[]
     */
    public function findAllPaginated(int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;

        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.deletedAt IS NULL')
            ->orderBy('s.createdAt', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countTotal(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.deletedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countOccupied(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.status = :status')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('status', StorageStatus::OCCUPIED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find storages with optional filtering by owner, place, and storage type.
     *
     * @return Storage[]
     */
    public function findFiltered(?User $owner, ?Place $place, ?StorageType $storageType): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.deletedAt IS NULL');

        if (null !== $owner) {
            $qb->andWhere('s.owner = :owner')
                ->setParameter('owner', $owner);
        }

        if (null !== $place) {
            $qb->andWhere('s.place = :place')
                ->setParameter('place', $place);
        }

        if (null !== $storageType) {
            $qb->andWhere('s.storageType = :storageType')
                ->setParameter('storageType', $storageType);
        }

        return $qb->orderBy('s.number', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
