<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;

class AuditLogRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(AuditLog $auditLog): void
    {
        $this->entityManager->persist($auditLog);
    }

    /**
     * @return AuditLog[]
     */
    public function findByEntity(string $entityType, string $entityId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('al')
            ->from(AuditLog::class, 'al')
            ->where('al.entityType = :entityType')
            ->andWhere('al.entityId = :entityId')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->orderBy('al.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AuditLog[]
     */
    public function findByEventType(string $eventType, int $limit = 100): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('al')
            ->from(AuditLog::class, 'al')
            ->where('al.eventType = :eventType')
            ->setParameter('eventType', $eventType)
            ->orderBy('al.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AuditLog[]
     */
    public function findRecent(int $limit = 100): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('al')
            ->from(AuditLog::class, 'al')
            ->orderBy('al.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AuditLog[]
     */
    public function findPaginated(int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;

        return $this->entityManager->createQueryBuilder()
            ->select('al')
            ->from(AuditLog::class, 'al')
            ->orderBy('al.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countTotal(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(al.id)')
            ->from(AuditLog::class, 'al')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return AuditLog[]
     */
    public function findPaginatedWithFilters(
        int $page,
        int $limit,
        ?string $entityType = null,
        ?string $eventType = null,
        ?string $search = null,
    ): array {
        $offset = ($page - 1) * $limit;

        $qb = $this->entityManager->createQueryBuilder()
            ->select('al')
            ->from(AuditLog::class, 'al')
            ->orderBy('al.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if (null !== $entityType && '' !== $entityType) {
            $qb->andWhere('al.entityType = :entityType')
                ->setParameter('entityType', $entityType);
        }

        if (null !== $eventType && '' !== $eventType) {
            $qb->andWhere('al.eventType = :eventType')
                ->setParameter('eventType', $eventType);
        }

        if (null !== $search && '' !== $search) {
            $qb->andWhere('al.entityId LIKE :search OR al.eventType LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        return $qb->getQuery()->getResult();
    }

    public function countWithFilters(
        ?string $entityType = null,
        ?string $eventType = null,
        ?string $search = null,
    ): int {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('COUNT(al.id)')
            ->from(AuditLog::class, 'al');

        if (null !== $entityType && '' !== $entityType) {
            $qb->andWhere('al.entityType = :entityType')
                ->setParameter('entityType', $entityType);
        }

        if (null !== $eventType && '' !== $eventType) {
            $qb->andWhere('al.eventType = :eventType')
                ->setParameter('eventType', $eventType);
        }

        if (null !== $search && '' !== $search) {
            $qb->andWhere('al.entityId LIKE :search OR al.eventType LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Get distinct entity types from audit log.
     *
     * @return string[]
     */
    public function getDistinctEntityTypes(): array
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT al.entityType')
            ->from(AuditLog::class, 'al')
            ->orderBy('al.entityType', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($result, 'entityType');
    }

    /**
     * Get distinct event types from audit log.
     *
     * @return string[]
     */
    public function getDistinctEventTypes(): array
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT al.eventType')
            ->from(AuditLog::class, 'al')
            ->orderBy('al.eventType', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($result, 'eventType');
    }
}
