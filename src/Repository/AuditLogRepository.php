<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;

final class AuditLogRepository
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
}
