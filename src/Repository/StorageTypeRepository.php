<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StorageType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class StorageTypeRepository
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

    public function findById(Uuid $id): ?StorageType
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
            ->where('s.owner = :owner')
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
            ->where('s.owner = :owner')
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
        $connection = $this->entityManager->getConnection();
        $result = $connection->executeQuery('SELECT COUNT(id) FROM storage_types')->fetchOne();

        return (int) $result;
    }

    public function countByOwner(User $owner): int
    {
        $connection = $this->entityManager->getConnection();
        $result = $connection->executeQuery(
            'SELECT COUNT(id) FROM storage_types WHERE owner_id = :ownerId',
            ['ownerId' => $owner->id->toRfc4122()]
        )->fetchOne();

        return (int) $result;
    }
}
