<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Place;
use App\Entity\PlaceChangeRequest;
use App\Entity\User;
use App\Enum\RequestStatus;
use App\Exception\PlaceChangeRequestNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class PlaceChangeRequestRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function save(PlaceChangeRequest $request): void
    {
        $this->entityManager->persist($request);
    }

    public function get(Uuid $id): PlaceChangeRequest
    {
        return $this->entityManager->find(PlaceChangeRequest::class, $id)
            ?? throw PlaceChangeRequestNotFound::withId($id);
    }

    public function find(Uuid $id): ?PlaceChangeRequest
    {
        return $this->entityManager->find(PlaceChangeRequest::class, $id);
    }

    /**
     * @return PlaceChangeRequest[]
     */
    public function findPending(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(PlaceChangeRequest::class, 'r')
            ->where('r.status = :status')
            ->setParameter('status', RequestStatus::PENDING)
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PlaceChangeRequest[]
     */
    public function findByPlace(Place $place): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(PlaceChangeRequest::class, 'r')
            ->where('r.place = :place')
            ->setParameter('place', $place)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PlaceChangeRequest[]
     */
    public function findByUser(User $user): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(PlaceChangeRequest::class, 'r')
            ->where('r.requestedBy = :user')
            ->setParameter('user', $user)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PlaceChangeRequest[]
     */
    public function findAll(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(PlaceChangeRequest::class, 'r')
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countPending(): int
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(PlaceChangeRequest::class, 'r')
            ->where('r.status = :status')
            ->setParameter('status', RequestStatus::PENDING)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }
}
