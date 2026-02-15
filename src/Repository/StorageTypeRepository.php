<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Place;
use App\Entity\StorageType;
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
     * @return StorageType[]
     */
    public function findByPlace(Place $place): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('st')
            ->from(StorageType::class, 'st')
            ->where('st.place = :place')
            ->andWhere('st.deletedAt IS NULL')
            ->setParameter('place', $place)
            ->orderBy('st.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return StorageType[]
     */
    public function findActiveByPlace(Place $place): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('st')
            ->from(StorageType::class, 'st')
            ->where('st.place = :place')
            ->andWhere('st.isActive = :active')
            ->andWhere('st.deletedAt IS NULL')
            ->setParameter('place', $place)
            ->setParameter('active', true)
            ->orderBy('st.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return StorageType[]
     */
    public function findByPlacePaginated(Place $place, int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;

        return $this->entityManager->createQueryBuilder()
            ->select('st')
            ->from(StorageType::class, 'st')
            ->where('st.place = :place')
            ->andWhere('st.deletedAt IS NULL')
            ->setParameter('place', $place)
            ->orderBy('st.createdAt', 'DESC')
            ->addOrderBy('st.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByPlace(Place $place): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(st.id)')
            ->from(StorageType::class, 'st')
            ->where('st.place = :place')
            ->andWhere('st.deletedAt IS NULL')
            ->setParameter('place', $place)
            ->getQuery()
            ->getSingleScalarResult();
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
}
