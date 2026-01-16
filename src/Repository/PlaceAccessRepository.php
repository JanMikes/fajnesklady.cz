<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Place;
use App\Entity\PlaceAccess;
use App\Entity\User;
use App\Exception\PlaceAccessNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class PlaceAccessRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function save(PlaceAccess $placeAccess): void
    {
        $this->entityManager->persist($placeAccess);
    }

    public function delete(PlaceAccess $placeAccess): void
    {
        $this->entityManager->remove($placeAccess);
    }

    public function get(Uuid $id): PlaceAccess
    {
        return $this->entityManager->find(PlaceAccess::class, $id)
            ?? throw PlaceAccessNotFound::withId($id);
    }

    public function find(Uuid $id): ?PlaceAccess
    {
        return $this->entityManager->find(PlaceAccess::class, $id);
    }

    /**
     * @return PlaceAccess[]
     */
    public function findByPlace(Place $place): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('pa')
            ->from(PlaceAccess::class, 'pa')
            ->join('pa.user', 'u')
            ->where('pa.place = :place')
            ->setParameter('place', $place)
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PlaceAccess[]
     */
    public function findByUser(User $user): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('pa')
            ->from(PlaceAccess::class, 'pa')
            ->join('pa.place', 'p')
            ->where('pa.user = :user')
            ->setParameter('user', $user)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function hasAccess(User $user, Place $place): bool
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('COUNT(pa.id)')
            ->from(PlaceAccess::class, 'pa')
            ->where('pa.user = :user')
            ->andWhere('pa.place = :place')
            ->setParameter('user', $user)
            ->setParameter('place', $place)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $result) > 0;
    }

    public function findByUserAndPlace(User $user, Place $place): ?PlaceAccess
    {
        return $this->entityManager->createQueryBuilder()
            ->select('pa')
            ->from(PlaceAccess::class, 'pa')
            ->where('pa.user = :user')
            ->andWhere('pa.place = :place')
            ->setParameter('user', $user)
            ->setParameter('place', $place)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
