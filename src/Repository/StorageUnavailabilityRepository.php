<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Storage;
use App\Entity\StorageUnavailability;
use App\Exception\StorageUnavailabilityNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class StorageUnavailabilityRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(StorageUnavailability $unavailability): void
    {
        $this->entityManager->persist($unavailability);
    }

    public function delete(StorageUnavailability $unavailability): void
    {
        $this->entityManager->remove($unavailability);
    }

    public function find(Uuid $id): ?StorageUnavailability
    {
        return $this->entityManager->find(StorageUnavailability::class, $id);
    }

    /**
     * @return StorageUnavailability[]
     */
    public function findByStorage(Storage $storage): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('su')
            ->from(StorageUnavailability::class, 'su')
            ->where('su.storage = :storage')
            ->setParameter('storage', $storage)
            ->orderBy('su.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find unavailability records that are active on a given date.
     *
     * @return StorageUnavailability[]
     */
    public function findActiveByStorageOnDate(Storage $storage, \DateTimeImmutable $date): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('su')
            ->from(StorageUnavailability::class, 'su')
            ->where('su.storage = :storage')
            ->andWhere('su.startDate <= :date')
            ->andWhere('su.endDate IS NULL OR su.endDate >= :date')
            ->setParameter('storage', $storage)
            ->setParameter('date', $date)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find unavailability records that overlap with a given period.
     *
     * @return StorageUnavailability[]
     */
    public function findOverlappingByStorage(
        Storage $storage,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
    ): array {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('su')
            ->from(StorageUnavailability::class, 'su')
            ->where('su.storage = :storage')
            ->setParameter('storage', $storage);

        if (null === $endDate) {
            // Requested period is indefinite - any record starting before or ending after start date overlaps
            $qb->andWhere('su.endDate IS NULL OR su.endDate >= :startDate')
                ->setParameter('startDate', $startDate);
        } else {
            // Standard overlap: startA <= endB AND startB <= endA
            $qb->andWhere('su.startDate <= :endDate')
                ->andWhere('su.endDate IS NULL OR su.endDate >= :startDate')
                ->setParameter('startDate', $startDate)
                ->setParameter('endDate', $endDate);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find unavailability records for a storage type that overlap with a date range.
     *
     * @return StorageUnavailability[]
     */
    public function findByStorageTypeInDateRange(
        \App\Entity\StorageType $storageType,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
    ): array {
        return $this->entityManager->createQueryBuilder()
            ->select('su')
            ->from(StorageUnavailability::class, 'su')
            ->join('su.storage', 's')
            ->where('s.storageType = :storageType')
            ->andWhere('su.startDate <= :endDate')
            ->andWhere('su.endDate IS NULL OR su.endDate >= :startDate')
            ->setParameter('storageType', $storageType)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all unavailability records for storages owned by a user.
     *
     * @return StorageUnavailability[]
     */
    public function findByOwner(\App\Entity\User $owner): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('su')
            ->from(StorageUnavailability::class, 'su')
            ->join('su.storage', 's')
            ->join('s.storageType', 'st')
            ->join('st.place', 'p')
            ->where('p.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('su.startDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all unavailability records.
     *
     * @return StorageUnavailability[]
     */
    public function findAll(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('su')
            ->from(StorageUnavailability::class, 'su')
            ->orderBy('su.startDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function get(Uuid $id): StorageUnavailability
    {
        return $this->find($id) ?? throw StorageUnavailabilityNotFound::withId($id);
    }
}
