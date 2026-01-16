<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Exception\ContractNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class ContractRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Contract $contract): void
    {
        $this->entityManager->persist($contract);
    }

    public function get(Uuid $id): Contract
    {
        return $this->entityManager->find(Contract::class, $id)
            ?? throw ContractNotFound::withId($id);
    }

    public function findByOrder(Order $order): ?Contract
    {
        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.order = :order')
            ->setParameter('order', $order)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Contract[]
     */
    public function findByUser(User $user): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Contract[]
     */
    public function findActiveByUser(User $user, \DateTimeImmutable $now): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.user = :user')
            ->andWhere('c.terminatedAt IS NULL')
            ->andWhere('c.endDate IS NULL OR c.endDate >= :now')
            ->setParameter('user', $user)
            ->setParameter('now', $now)
            ->orderBy('c.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Contract[]
     */
    public function findByStorage(Storage $storage): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.storage = :storage')
            ->setParameter('storage', $storage)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active contracts for a storage (not terminated and not past end date).
     *
     * @return Contract[]
     */
    public function findActiveByStorage(Storage $storage, \DateTimeImmutable $now): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.storage = :storage')
            ->andWhere('c.terminatedAt IS NULL')
            ->andWhere('c.endDate IS NULL OR c.endDate >= :now')
            ->setParameter('storage', $storage)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find contracts expiring within a given number of days.
     *
     * @return Contract[]
     */
    public function findExpiringWithinDays(int $days, \DateTimeImmutable $now): array
    {
        $futureDate = $now->modify("+{$days} days");

        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.terminatedAt IS NULL')
            ->andWhere('c.endDate IS NOT NULL')
            ->andWhere('c.endDate >= :now')
            ->andWhere('c.endDate <= :futureDate')
            ->setParameter('now', $now)
            ->setParameter('futureDate', $futureDate)
            ->orderBy('c.endDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countByUser(User $user): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Contract::class, 'c')
            ->where('c.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find contracts that overlap with a given period.
     * Only considers active contracts (not terminated and not past end date).
     *
     * @return Contract[]
     */
    public function findOverlappingByStorage(
        Storage $storage,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        ?Contract $excludeContract = null,
    ): array {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.storage = :storage')
            ->andWhere('c.terminatedAt IS NULL')
            ->setParameter('storage', $storage);

        if (null !== $excludeContract) {
            $qb->andWhere('c.id != :excludeId')
                ->setParameter('excludeId', $excludeContract->id);
        }

        if (null === $endDate) {
            // Requested period is indefinite - any active contract overlaps
            $qb->andWhere('c.endDate IS NULL OR c.endDate >= :startDate')
                ->setParameter('startDate', $startDate);
        } else {
            // Standard overlap: startA <= endB AND startB <= endA
            $qb->andWhere('c.startDate <= :endDate')
                ->andWhere('c.endDate IS NULL OR c.endDate >= :startDate')
                ->setParameter('startDate', $startDate)
                ->setParameter('endDate', $endDate);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Contract[]
     */
    public function findAllPaginated(int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;

        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->orderBy('c.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countTotal(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Contract::class, 'c')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find contracts that are due for recurring billing.
     *
     * @return Contract[]
     */
    public function findDueForBilling(\DateTimeImmutable $now): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.goPayParentPaymentId IS NOT NULL')
            ->andWhere('c.terminatedAt IS NULL')
            ->andWhere('c.nextBillingDate IS NOT NULL')
            ->andWhere('c.nextBillingDate <= :now')
            ->andWhere('c.failedBillingAttempts = 0')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find contracts that need retry after failed billing (3 days later).
     *
     * @return Contract[]
     */
    public function findNeedingRetry(\DateTimeImmutable $now): array
    {
        $retryAfter = $now->modify('-3 days');

        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.goPayParentPaymentId IS NOT NULL')
            ->andWhere('c.terminatedAt IS NULL')
            ->andWhere('c.failedBillingAttempts = 1')
            ->andWhere('c.lastBillingFailedAt IS NOT NULL')
            ->andWhere('c.lastBillingFailedAt <= :retryAfter')
            ->setParameter('retryAfter', $retryAfter)
            ->getQuery()
            ->getResult();
    }

    public function findByGoPayParentPaymentId(int $parentPaymentId): ?Contract
    {
        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.goPayParentPaymentId = :paymentId')
            ->setParameter('paymentId', $parentPaymentId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find LIMITED contracts for a storage type that end in the future.
     * LIMITED contracts have an end date (unlimited contracts have NULL end date).
     *
     * @return Contract[]
     */
    public function findLimitedByStorageType(StorageType $storageType, \DateTimeImmutable $now): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->join('c.storage', 's')
            ->where('s.storageType = :storageType')
            ->andWhere('c.terminatedAt IS NULL')
            ->andWhere('c.endDate IS NOT NULL')
            ->andWhere('c.endDate > :now')
            ->setParameter('storageType', $storageType)
            ->setParameter('now', $now)
            ->orderBy('c.endDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
