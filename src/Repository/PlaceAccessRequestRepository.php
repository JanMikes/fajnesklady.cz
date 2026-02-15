<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Place;
use App\Entity\PlaceAccessRequest;
use App\Entity\User;
use App\Enum\PlaceAccessRequestStatus;
use App\Exception\PlaceAccessRequestNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class PlaceAccessRequestRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function save(PlaceAccessRequest $request): void
    {
        $this->entityManager->persist($request);
    }

    public function get(Uuid $id): PlaceAccessRequest
    {
        return $this->entityManager->find(PlaceAccessRequest::class, $id)
            ?? throw PlaceAccessRequestNotFound::withId($id);
    }

    public function findPendingByUserAndPlace(User $user, Place $place): ?PlaceAccessRequest
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(PlaceAccessRequest::class, 'r')
            ->where('r.requestedBy = :user')
            ->andWhere('r.place = :place')
            ->andWhere('r.status = :status')
            ->setParameter('user', $user)
            ->setParameter('place', $place)
            ->setParameter('status', PlaceAccessRequestStatus::PENDING)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return PlaceAccessRequest[]
     */
    public function findByUser(User $user): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(PlaceAccessRequest::class, 'r')
            ->where('r.requestedBy = :user')
            ->setParameter('user', $user)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PlaceAccessRequest[]
     */
    public function findPending(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(PlaceAccessRequest::class, 'r')
            ->join('r.place', 'p')
            ->join('r.requestedBy', 'u')
            ->where('r.status = :status')
            ->setParameter('status', PlaceAccessRequestStatus::PENDING)
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Uuid[]
     */
    public function findPendingPlaceIdsByUser(User $user): array
    {
        $results = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(r.place) as placeId')
            ->from(PlaceAccessRequest::class, 'r')
            ->where('r.requestedBy = :user')
            ->andWhere('r.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', PlaceAccessRequestStatus::PENDING)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(fn (string $id) => Uuid::fromString($id), $results);
    }
}
