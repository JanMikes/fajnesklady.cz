<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
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
            ->orderBy('c.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Bulk variant of {@see self::findActiveByStorage()} — used by
     * {@see \App\Service\Storage\StorageOccupancyService} to fetch the active
     * contract per storage in one query.
     *
     * @param Storage[] $storages
     *
     * @return Contract[]
     */
    public function findActiveByStorages(array $storages, \DateTimeImmutable $now): array
    {
        if ([] === $storages) {
            return [];
        }

        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.storage IN (:storages)')
            ->andWhere('c.terminatedAt IS NULL')
            ->andWhere('c.startDate <= :now')
            ->andWhere('c.endDate IS NULL OR c.endDate >= :now')
            ->setParameter('storages', $storages)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * Active (non-terminated) contracts overlapping [$from, $to] for the given
     * storages. Drives the calendar Gantt strip + per-day detail panel.
     *
     * @param Storage[] $storages
     *
     * @return Contract[]
     */
    public function findOverlappingByStorages(
        array $storages,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        if ([] === $storages) {
            return [];
        }

        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.storage IN (:storages)')
            ->andWhere('c.terminatedAt IS NULL')
            ->andWhere('c.startDate <= :to')
            ->andWhere('c.endDate IS NULL OR c.endDate >= :from')
            ->setParameter('storages', $storages)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('c.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Earliest future startDate per storage among active or upcoming contracts
     * with startDate strictly greater than $strictlyAfter. Used to compute
     * "next reservation" hints on free storages.
     *
     * MIN() over a DATE_IMMUTABLE column comes back as a Y-m-d string —
     * Doctrine skips its type-conversion pass on SQL aggregates, so we
     * rehydrate in PHP.
     *
     * @param Storage[] $storages
     *
     * @return array<string, \DateTimeImmutable> keyed by Storage->id->toRfc4122()
     */
    public function findNextStartByStorages(array $storages, \DateTimeImmutable $strictlyAfter): array
    {
        if ([] === $storages) {
            return [];
        }

        /** @var array<int, array{storageId: string, nextStart: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(c.storage) AS storageId, MIN(c.startDate) AS nextStart')
            ->from(Contract::class, 'c')
            ->where('c.storage IN (:storages)')
            ->andWhere('c.terminatedAt IS NULL')
            ->andWhere('c.startDate > :after')
            ->setParameter('storages', $storages)
            ->setParameter('after', $strictlyAfter)
            ->groupBy('c.storage')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['storageId']] = new \DateTimeImmutable((string) $row['nextStart']);
        }

        return $result;
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
            ->andWhere('c.endDate IS NULL OR c.endDate >= :now')
            ->andWhere('c.terminatesAt IS NULL OR c.terminatesAt >= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find recurring contracts that need a 7-business-day advance notice
     * because ≥6 months have elapsed since the last successful charge
     * (Podmínky opakovaných plateb čl. V).
     *
     * Window: nextBillingDate is 8–10 calendar days away (covers 7 working
     * days with margin even in a week with one Czech public holiday). Skip
     * contracts that already received an advance notice in the last 90 days
     * — that's the idempotency guard against the daily cron.
     *
     * @return Contract[]
     */
    public function findRequiringAdvanceNotice(\DateTimeImmutable $now): array
    {
        $windowStart = $now->modify('+8 days');
        $windowEnd = $now->modify('+10 days');
        $sixMonthsAgo = $now->modify('-6 months');
        $idempotencyCutoff = $now->modify('-90 days');

        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.goPayParentPaymentId IS NOT NULL')
            ->andWhere('c.terminatedAt IS NULL')
            ->andWhere('c.nextBillingDate IS NOT NULL')
            ->andWhere('c.nextBillingDate >= :windowStart')
            ->andWhere('c.nextBillingDate <= :windowEnd')
            ->andWhere('c.lastBilledAt IS NOT NULL')
            ->andWhere('c.lastBilledAt <= :sixMonthsAgo')
            ->andWhere('c.lastAdvanceNoticeSentAt IS NULL OR c.lastAdvanceNoticeSentAt <= :idempotencyCutoff')
            ->setParameter('windowStart', $windowStart)
            ->setParameter('windowEnd', $windowEnd)
            ->setParameter('sixMonthsAgo', $sixMonthsAgo)
            ->setParameter('idempotencyCutoff', $idempotencyCutoff)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find contracts that need retry after failed billing (3 days later).
     *
     * @return Contract[]
     */
    /**
     * Find contracts needing retry: attempt 1 after 3 days, attempt 2 after 7 days.
     *
     * @return Contract[]
     */
    public function findNeedingRetry(\DateTimeImmutable $now): array
    {
        $retryAfter3Days = $now->modify('-3 days');
        $retryAfter7Days = $now->modify('-7 days');

        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.goPayParentPaymentId IS NOT NULL')
            ->andWhere('c.terminatedAt IS NULL')
            ->andWhere('c.lastBillingFailedAt IS NOT NULL')
            ->andWhere(
                '(c.failedBillingAttempts = 1 AND c.lastBillingFailedAt <= :retryAfter3Days) OR '
                .'(c.failedBillingAttempts = 2 AND c.lastBillingFailedAt <= :retryAfter7Days)'
            )
            ->setParameter('retryAfter3Days', $retryAfter3Days)
            ->setParameter('retryAfter7Days', $retryAfter7Days)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find contracts due for termination:
     * - UNLIMITED with terminatesAt <= now and not yet terminated
     * - LIMITED with endDate <= now and not yet terminated
     *
     * @return Contract[]
     */
    public function findDueForTermination(\DateTimeImmutable $now): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.terminatedAt IS NULL')
            ->andWhere(
                '(c.terminatesAt IS NOT NULL AND c.terminatesAt <= :now) OR '
                .'(c.endDate IS NOT NULL AND c.endDate <= :now)'
            )
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find contracts with payment issues for admin dashboard.
     *
     * @return Contract[]
     */
    public function findWithPaymentIssues(\DateTimeImmutable $now): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where(
                // Active contracts with billing problems
                '(c.terminatedAt IS NULL AND (c.failedBillingAttempts > 0 OR '
                .'(c.nextBillingDate IS NOT NULL AND c.nextBillingDate < :overdueThreshold))) OR '
                // Terminated contracts with outstanding debt
                .'(c.outstandingDebtAmount IS NOT NULL AND c.outstandingDebtAmount > 0)'
            )
            ->setParameter('overdueThreshold', $now->modify('-1 day'))
            ->orderBy('c.outstandingDebtAmount', 'DESC')
            ->addOrderBy('c.failedBillingAttempts', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all contracts with outstanding debt (terminated due to payment failure).
     *
     * @return Contract[]
     */
    public function findWithOutstandingDebt(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.outstandingDebtAmount IS NOT NULL')
            ->andWhere('c.outstandingDebtAmount > 0')
            ->orderBy('c.terminatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Sum total outstanding debt across all contracts.
     */
    public function sumOutstandingDebt(): int
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('SUM(c.outstandingDebtAmount)')
            ->from(Contract::class, 'c')
            ->where('c.outstandingDebtAmount IS NOT NULL')
            ->andWhere('c.outstandingDebtAmount > 0')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Count contracts with outstanding debt.
     */
    public function countWithOutstandingDebt(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Contract::class, 'c')
            ->where('c.outstandingDebtAmount IS NOT NULL')
            ->andWhere('c.outstandingDebtAmount > 0')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countOverdueContracts(\DateTimeImmutable $now): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Contract::class, 'c')
            ->where(
                '(c.terminatedAt IS NULL AND (c.failedBillingAttempts > 0 OR '
                .'(c.nextBillingDate IS NOT NULL AND c.nextBillingDate < :overdueThreshold))) OR '
                .'(c.outstandingDebtAmount IS NOT NULL AND c.outstandingDebtAmount > 0)'
            )
            ->setParameter('overdueThreshold', $now->modify('-1 day'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function sumOverdueAmount(\DateTimeImmutable $now): int
    {
        // SUM = outstandingDebt for terminated, else order.firstPaymentPrice (one unpaid month).
        $result = $this->entityManager->createQueryBuilder()
            ->select(
                'SUM(CASE WHEN c.terminatedAt IS NOT NULL AND c.outstandingDebtAmount > 0 '
                .'THEN c.outstandingDebtAmount ELSE o.firstPaymentPrice END)'
            )
            ->from(Contract::class, 'c')
            ->join('c.order', 'o')
            ->where(
                '(c.terminatedAt IS NULL AND (c.failedBillingAttempts > 0 OR '
                .'(c.nextBillingDate IS NOT NULL AND c.nextBillingDate < :overdueThreshold))) OR '
                .'(c.outstandingDebtAmount IS NOT NULL AND c.outstandingDebtAmount > 0)'
            )
            ->setParameter('overdueThreshold', $now->modify('-1 day'))
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Returns RFC-4122 user UUID strings of users who have ≥1 overdue contract.
     *
     * @param Uuid[]|null $restrictToUserIds when non-null, the result is the
     *                                       intersection of overdue users and
     *                                       this list — used by paginated lists
     *                                       to badge only the visible page's users
     *
     * @return string[]
     */
    public function findOverdueUserIds(\DateTimeImmutable $now, ?array $restrictToUserIds = null): array
    {
        if (null !== $restrictToUserIds && [] === $restrictToUserIds) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT IDENTITY(c.user) AS userId')
            ->from(Contract::class, 'c')
            ->where(
                '(c.terminatedAt IS NULL AND (c.failedBillingAttempts > 0 OR '
                .'(c.nextBillingDate IS NOT NULL AND c.nextBillingDate < :overdueThreshold))) OR '
                .'(c.outstandingDebtAmount IS NOT NULL AND c.outstandingDebtAmount > 0)'
            )
            ->setParameter('overdueThreshold', $now->modify('-1 day'));

        if (null !== $restrictToUserIds) {
            $qb->andWhere('c.user IN (:ids)')->setParameter('ids', $restrictToUserIds);
        }

        /** @var array<int, array{userId: string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map(static fn (array $r): string => (string) $r['userId'], $rows);
    }

    /**
     * Aggregate per-user contract stats keyed by RFC-4122 user UUID string.
     *
     * Stats per user:
     *  - totalCount  — every contract ever (including terminated and expired)
     *  - activeCount — non-terminated contracts whose endDate is null or future
     *  - mrrInHaler  — sum of Order.firstPaymentPrice across active "recurring shape" contracts
     *                  ("recurring shape" = endDate IS NULL OR (endDate - startDate) >= 28 days,
     *                  matching PriceCalculator::WEEKLY_THRESHOLD_DAYS — short LIMITED rentals
     *                  are one-shots and must not inflate monthly revenue)
     *
     * Users with no contracts are absent from the result; callers default to zeros.
     *
     * @param Uuid[] $userIds
     *
     * @return array<string, array{activeCount: int, totalCount: int, mrrInHaler: int}>
     */
    public function loadCustomerStatsByUserIds(array $userIds, \DateTimeImmutable $now): array
    {
        if ([] === $userIds) {
            return [];
        }

        $idStrings = array_map(static fn (Uuid $id): string => (string) $id, $userIds);

        $rows = $this->entityManager->getConnection()->executeQuery(
            <<<'SQL'
                SELECT
                    c.user_id::text AS user_id,
                    COUNT(*) AS total_count,
                    COUNT(*) FILTER (
                        WHERE c.terminated_at IS NULL
                          AND (c.end_date IS NULL OR c.end_date >= :now)
                    ) AS active_count,
                    COALESCE(SUM(o.total_price) FILTER (
                        WHERE c.terminated_at IS NULL
                          AND (c.end_date IS NULL OR c.end_date >= :now)
                          AND (c.end_date IS NULL OR (c.end_date - c.start_date) >= 28)
                    ), 0) AS mrr
                FROM contract c
                INNER JOIN orders o ON o.id = c.order_id
                WHERE c.user_id IN (:userIds)
                GROUP BY c.user_id
                SQL,
            ['now' => $now, 'userIds' => $idStrings],
            [
                'now' => \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE,
                'userIds' => \Doctrine\DBAL\ArrayParameterType::STRING,
            ]
        )->fetchAllAssociative();

        $stats = [];
        foreach ($rows as $row) {
            $stats[(string) $row['user_id']] = [
                'activeCount' => (int) $row['active_count'],
                'totalCount' => (int) $row['total_count'],
                'mrrInHaler' => (int) $row['mrr'],
            ];
        }

        return $stats;
    }

    /**
     * RFC-4122 user UUID strings of users with ≥1 active (non-terminated, not-yet-expired)
     * contract. Returns the zero-UUID sentinel when no users qualify — empty arrays
     * in `IN (:ids)` blow up at the DBAL layer (mirrors UserRepository::overdueUserIdsSubquery).
     *
     * @return string[]
     */
    public function findActiveContractUserIdsSubquery(\DateTimeImmutable $now): array
    {
        /** @var array<int, array{userId: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT IDENTITY(c.user) AS userId')
            ->from(Contract::class, 'c')
            ->where('c.terminatedAt IS NULL')
            ->andWhere('c.endDate IS NULL OR c.endDate >= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getArrayResult();

        if ([] === $rows) {
            return ['00000000-0000-0000-0000-000000000000'];
        }

        return array_map(static fn (array $r): string => (string) $r['userId'], $rows);
    }

    /**
     * Externally-prepaid contracts whose paidThroughDate falls within
     * [$rangeStart, $rangeEnd] and which have NOT yet established a GoPay token.
     * Idempotency: skip contracts whose lastAdvanceNoticeSentAt is at or after
     * $rangeStart so a once-per-day cron does not double-send.
     *
     * @return Contract[]
     */
    public function findExternalPrepaymentsEndingInRange(
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
    ): array {
        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.paidThroughDate IS NOT NULL')
            ->andWhere('c.goPayParentPaymentId IS NULL')
            ->andWhere('c.terminatedAt IS NULL')
            ->andWhere('c.paidThroughDate BETWEEN :rangeStart AND :rangeEnd')
            ->andWhere('c.lastAdvanceNoticeSentAt IS NULL OR c.lastAdvanceNoticeSentAt < :rangeStart')
            ->setParameter('rangeStart', $rangeStart)
            ->setParameter('rangeEnd', $rangeEnd)
            ->getQuery()
            ->getResult();
    }

    public function findByGoPayParentPaymentId(string $parentPaymentId): ?Contract
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

    /**
     * Find contracts with active recurring payments for a landlord's storages.
     *
     * @return Contract[]
     */
    public function findWithActiveRecurringByLandlord(User $landlord): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->join('c.storage', 's')
            ->where('s.owner = :landlord')
            ->andWhere('c.goPayParentPaymentId IS NOT NULL')
            ->andWhere('c.terminatedAt IS NULL')
            ->setParameter('landlord', $landlord)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Sum expected recurring revenue for a landlord (sum of order.firstPaymentPrice for active recurring contracts).
     */
    public function sumExpectedRecurringByLandlord(User $landlord): int
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('SUM(o.firstPaymentPrice)')
            ->from(Contract::class, 'c')
            ->join('c.storage', 's')
            ->join('c.order', 'o')
            ->where('s.owner = :landlord')
            ->andWhere('c.goPayParentPaymentId IS NOT NULL')
            ->andWhere('c.terminatedAt IS NULL')
            ->setParameter('landlord', $landlord)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Sum expected recurring revenue across all landlords.
     */
    public function sumExpectedRecurringAll(): int
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('SUM(o.firstPaymentPrice)')
            ->from(Contract::class, 'c')
            ->join('c.order', 'o')
            ->where('c.goPayParentPaymentId IS NOT NULL')
            ->andWhere('c.terminatedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Count contracts with active recurring payments for a landlord.
     */
    public function countActiveRecurringByLandlord(User $landlord): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Contract::class, 'c')
            ->join('c.storage', 's')
            ->where('s.owner = :landlord')
            ->andWhere('c.goPayParentPaymentId IS NOT NULL')
            ->andWhere('c.terminatedAt IS NULL')
            ->setParameter('landlord', $landlord)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Count all contracts with active recurring payments.
     */
    public function countActiveRecurringAll(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Contract::class, 'c')
            ->where('c.goPayParentPaymentId IS NOT NULL')
            ->andWhere('c.terminatedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countActiveRecurringAtPlace(Place $place, ?User $owner): int
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Contract::class, 'c')
            ->join('c.storage', 's')
            ->where('s.place = :place')
            ->andWhere('c.goPayParentPaymentId IS NOT NULL')
            ->andWhere('c.terminatedAt IS NULL')
            ->setParameter('place', $place);

        if (null !== $owner) {
            $qb->andWhere('s.owner = :owner')->setParameter('owner', $owner);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function sumExpectedRecurringAtPlace(Place $place, ?User $owner): int
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('SUM(o.firstPaymentPrice)')
            ->from(Contract::class, 'c')
            ->join('c.storage', 's')
            ->join('c.order', 'o')
            ->where('s.place = :place')
            ->andWhere('c.goPayParentPaymentId IS NOT NULL')
            ->andWhere('c.terminatedAt IS NULL')
            ->setParameter('place', $place);

        if (null !== $owner) {
            $qb->andWhere('s.owner = :owner')->setParameter('owner', $owner);
        }

        return (int) ($qb->getQuery()->getSingleScalarResult() ?? 0);
    }

    /**
     * Active contracts at $place whose endDate falls within $days from $now.
     * Same semantics as findExpiringWithinDays but place-scoped.
     *
     * @return Contract[]
     */
    public function findExpiringWithinDaysAtPlace(
        int $days,
        \DateTimeImmutable $now,
        Place $place,
        ?User $owner,
    ): array {
        $futureDate = $now->modify("+{$days} days");

        $qb = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->join('c.storage', 's')
            ->where('s.place = :place')
            ->andWhere('c.terminatedAt IS NULL')
            ->andWhere('c.endDate IS NOT NULL')
            ->andWhere('c.endDate >= :now')
            ->andWhere('c.endDate <= :futureDate')
            ->setParameter('place', $place)
            ->setParameter('now', $now)
            ->setParameter('futureDate', $futureDate)
            ->orderBy('c.endDate', 'ASC');

        if (null !== $owner) {
            $qb->andWhere('s.owner = :owner')->setParameter('owner', $owner);
        }

        return $qb->getQuery()->getResult();
    }
}
