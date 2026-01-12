<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Place;
use App\Entity\User;
use App\Exception\PlaceNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class PlaceRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

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

    /**
     * @return Place[]
     */
    public function findAll(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Place::class, 'p')
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Place[]
     */
    public function findByOwner(User $owner): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Place::class, 'p')
            ->where('p.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
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

    /**
     * @return Place[]
     */
    public function findByOwnerPaginated(User $owner, int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;

        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Place::class, 'p')
            ->where('p.owner = :owner')
            ->setParameter('owner', $owner)
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
        $result = $connection->executeQuery('SELECT COUNT(id) FROM places')->fetchOne();

        return (int) $result;
    }

    public function countByOwner(User $owner): int
    {
        $connection = $this->entityManager->getConnection();
        $result = $connection->executeQuery(
            'SELECT COUNT(id) FROM places WHERE owner_id = :ownerId',
            ['ownerId' => $owner->id->toRfc4122()]
        )->fetchOne();

        return (int) $result;
    }
}
