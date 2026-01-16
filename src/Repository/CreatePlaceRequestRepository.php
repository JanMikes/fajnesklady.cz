<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CreatePlaceRequest;
use App\Entity\User;
use App\Enum\RequestStatus;
use App\Exception\CreatePlaceRequestNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class CreatePlaceRequestRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function save(CreatePlaceRequest $request): void
    {
        $this->entityManager->persist($request);
    }

    public function get(Uuid $id): CreatePlaceRequest
    {
        return $this->entityManager->find(CreatePlaceRequest::class, $id)
            ?? throw CreatePlaceRequestNotFound::withId($id);
    }

    public function find(Uuid $id): ?CreatePlaceRequest
    {
        return $this->entityManager->find(CreatePlaceRequest::class, $id);
    }

    /**
     * @return CreatePlaceRequest[]
     */
    public function findPending(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(CreatePlaceRequest::class, 'r')
            ->where('r.status = :status')
            ->setParameter('status', RequestStatus::PENDING)
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return CreatePlaceRequest[]
     */
    public function findByUser(User $user): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(CreatePlaceRequest::class, 'r')
            ->where('r.requestedBy = :user')
            ->setParameter('user', $user)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return CreatePlaceRequest[]
     */
    public function findAll(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(CreatePlaceRequest::class, 'r')
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countPending(): int
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(CreatePlaceRequest::class, 'r')
            ->where('r.status = :status')
            ->setParameter('status', RequestStatus::PENDING)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }
}
