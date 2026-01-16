<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Place;
use App\Entity\User;
use App\Exception\PlaceNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class PlaceRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function save(Place $place): void
    {
        $this->entityManager->persist($place);
    }

    public function delete(Place $place): void
    {
        $this->entityManager->remove($place);
    }

    public function get(Uuid $id): Place
    {
        return $this->entityManager->find(Place::class, $id)
            ?? throw PlaceNotFound::withId($id);
    }

    public function find(Uuid $id): ?Place
    {
        return $this->entityManager->find(Place::class, $id);
    }

    /**
     * @return Place[]
     */
    public function findAll(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Place::class, 'p')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Place[]
     */
    public function findAllPaginated(int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;

        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Place::class, 'p')
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countTotal(): int
    {
        $connection = $this->entityManager->getConnection();
        $result = $connection->executeQuery('SELECT COUNT(id) FROM place')->fetchOne();

        return (int) $result;
    }

    /**
     * @return Place[]
     */
    public function findAllActive(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Place::class, 'p')
            ->where('p.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count distinct places that have storages owned by the given user.
     */
    public function countPlacesWithStoragesByOwner(User $owner): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT p.id)')
            ->from(Place::class, 'p')
            ->innerJoin('App\Entity\Storage', 's', 'WITH', 's.place = p')
            ->where('s.owner = :owner')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find places that have storages owned by the given user.
     *
     * @return Place[]
     */
    public function findByOwner(User $owner): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('DISTINCT p')
            ->from(Place::class, 'p')
            ->innerJoin('App\Entity\Storage', 's', 'WITH', 's.place = p')
            ->where('s.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
