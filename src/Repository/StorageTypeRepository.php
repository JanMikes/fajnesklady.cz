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
    ) {
    }

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

    /**
     * @return StorageType[]
     */
    public function findAll(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(StorageType::class, 's')
            ->orderBy('s.createdAt', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return StorageType[]
     */
    public function findByOwner(User $owner): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(StorageType::class, 's')
            ->join('s.place', 'p')
            ->where('p.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('s.createdAt', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->getQuery()
            ->getResult();
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
            ->orderBy('s.createdAt', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return StorageType[]
     */
    public function findByOwnerPaginated(User $owner, int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;

        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(StorageType::class, 's')
            ->join('s.place', 'p')
            ->where('p.owner = :owner')
            ->setParameter('owner', $owner)
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
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    public function countByOwner(User $owner): int
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(StorageType::class, 's')
            ->join('s.place', 'p')
            ->where('p.owner = :owner')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
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
            ->setParameter('place', $place)
            ->orderBy('st.pricePerMonth', 'ASC')
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
            ->setParameter('place', $place)
            ->setParameter('active', true)
            ->orderBy('st.pricePerMonth', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function find(Uuid $id): ?StorageType
    {
        return $this->entityManager->find(StorageType::class, $id);
    }
}
