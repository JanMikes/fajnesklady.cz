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
     * @return AuditLog[]
     */
    public function findForOrderTimeline(string $orderId, ?string $contractId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('al')
            ->from(AuditLog::class, 'al')
            ->orderBy('al.createdAt', 'ASC');

        if (null !== $contractId) {
            $qb->where('(al.entityType = :orderType AND al.entityId = :orderId) OR (al.entityType = :contractType AND al.entityId = :contractId)')
                ->setParameter('orderType', 'order')
                ->setParameter('orderId', $orderId)
                ->setParameter('contractType', 'contract')
                ->setParameter('contractId', $contractId);
        } else {
            $qb->where('al.entityType = :orderType AND al.entityId = :orderId')
                ->setParameter('orderType', 'order')
                ->setParameter('orderId', $orderId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Streamed iteration for export. Same filter shape as
     * {@see self::findPaginatedWithFilters()} but without pagination.
     *
     * @return iterable<AuditLog>
     */
    public function streamWithFilters(
        ?string $entityType,
        ?string $eventType,
        ?string $search,
    ): iterable {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('al')
            ->from(AuditLog::class, 'al')
            ->orderBy('al.createdAt', 'DESC');

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

        $batch = 0;
        foreach ($qb->getQuery()->toIterable() as $log) {
            yield $log;
            if (++$batch >= 200) {
                $this->entityManager->clear();
                $batch = 0;
            }
        }
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
