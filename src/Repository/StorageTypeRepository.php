<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Exception\StorageTypeNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class StorageTypeRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function save(StorageType $storageType): void
    {
        $this->entityManager->persist($storageType);
    }

    public function delete(StorageType $storageType): void
    {
        $this->entityManager->remove($storageType);
    }

    public function get(Uuid $id): StorageType
    {
        return $this->entityManager->find(StorageType::class, $id)
            ?? throw StorageTypeNotFound::withId($id);
    }

    public function find(Uuid $id): ?StorageType
    {
        return $this->entityManager->find(StorageType::class, $id);
    }

    /**
     * @return StorageType[]
     */
    public function findAll(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(StorageType::class, 's')
            ->where('s.deletedAt IS NULL')
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return StorageType[]
     */
    public function findAllActive(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(StorageType::class, 's')
            ->where('s.isActive = :active')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('active', true)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active storage types that have storages at a given place.
     *
     * @return StorageType[]
     */
    public function findActiveByPlace(Place $place): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('DISTINCT st')
            ->from(StorageType::class, 'st')
            ->innerJoin('App\Entity\Storage', 's', 'ON', 's.storageType = st')
            ->where('st.isActive = :active')
            ->andWhere('s.place = :place')
            ->andWhere('st.deletedAt IS NULL')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('active', true)
            ->setParameter('place', $place)
            ->orderBy('st.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find storage types that have storages owned by the given user.
     *
     * @return StorageType[]
     */
    public function findByOwner(User $owner): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('DISTINCT st')
            ->from(StorageType::class, 'st')
            ->innerJoin('App\Entity\Storage', 's', 'ON', 's.storageType = st')
            ->where('s.owner = :owner')
            ->andWhere('st.deletedAt IS NULL')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('owner', $owner)
            ->orderBy('st.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find storage types that have storages owned by the given user at a specific place.
     *
     * @return StorageType[]
     */
    public function findByOwnerAndPlace(User $owner, Place $place): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('DISTINCT st')
            ->from(StorageType::class, 'st')
            ->innerJoin('App\Entity\Storage', 's', 'ON', 's.storageType = st')
            ->where('s.owner = :owner')
            ->andWhere('s.place = :place')
            ->andWhere('st.deletedAt IS NULL')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('owner', $owner)
            ->setParameter('place', $place)
            ->orderBy('st.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if the given user owns any storages of this storage type.
     */
    public function isOwnedBy(StorageType $storageType, User $user): bool
    {
        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from('App\Entity\Storage', 's')
            ->where('s.storageType = :storageType')
            ->andWhere('s.owner = :owner')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('storageType', $storageType)
            ->setParameter('owner', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Check if the given user owns any storages of this storage type at a specific place.
     */
    public function isOwnedByAtPlace(StorageType $storageType, User $user, Place $place): bool
    {
        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from('App\Entity\Storage', 's')
            ->where('s.storageType = :storageType')
            ->andWhere('s.owner = :owner')
            ->andWhere('s.place = :place')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('storageType', $storageType)
            ->setParameter('owner', $user)
            ->setParameter('place', $place)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * @return StorageType[]
     */
    public function findAllPaginated(int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;

        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(StorageType::class, 's')
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
        $result = $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(StorageType::class, 's')
            ->where('s.deletedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    /**
     * Find storage types that have storages at a given place.
     *
     * @return StorageType[]
     */
    public function findByPlace(Place $place): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('DISTINCT st')
            ->from(StorageType::class, 'st')
            ->innerJoin('App\Entity\Storage', 's', 'ON', 's.storageType = st')
            ->where('s.place = :place')
            ->andWhere('st.deletedAt IS NULL')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('place', $place)
            ->orderBy('st.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
