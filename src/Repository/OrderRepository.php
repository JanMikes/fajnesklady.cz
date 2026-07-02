<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Exception\OrderNotFound;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class OrderRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Order $order): void
    {
        $this->entityManager->persist($order);
    }

    public function find(Uuid $id): ?Order
    {
        return $this->entityManager->find(Order::class, $id);
    }

    public function get(Uuid $id): Order
    {
        return $this->find($id) ?? throw OrderNotFound::withId($id);
    }

    /**
     * @return Order[]
     */
    public function findByUser(User $user): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.user = :user')
            ->setParameter('user', $user)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All of a user's orders, newest first, with storage / storage type / place
     * join-fetched so the admin user-detail grid renders "what & where" without
     * N+1 lazy loads.
     *
     * @return list<Order>
     */
    public function findByUserWithDetails(Uuid $userId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('o', 's', 'st', 'p')
            ->from(Order::class, 'o')
            ->leftJoin('o.storage', 's')
            ->leftJoin('s.storageType', 'st')
            ->leftJoin('s.place', 'p')
            ->where('o.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Order[]
     */
    public function findByStorage(Storage $storage): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.storage = :storage')
            ->setParameter('storage', $storage)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find orders that block a storage (created, reserved, awaiting payment, or paid).
     *
     * @return Order[]
     */
    public function findActiveByStorage(Storage $storage): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.storage = :storage')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('storage', $storage)
            ->setParameter('statuses', [
                OrderStatus::CREATED,
                OrderStatus::RESERVED,
                OrderStatus::AWAITING_PAYMENT,
                OrderStatus::PAID,
            ])
            ->orderBy('o.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find orders that have expired but not yet transitioned to EXPIRED status.
     *
     * @return Order[]
     */
    public function findExpiredOrders(\DateTimeImmutable $now): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.expiresAt < :now')
            ->andWhere('o.status NOT IN (:terminalStatuses)')
            ->andWhere('o.status != :paidStatus')
            ->setParameter('now', $now)
            ->setParameter('terminalStatuses', [
                OrderStatus::COMPLETED,
                OrderStatus::CANCELLED,
                OrderStatus::EXPIRED,
            ])
            ->setParameter('paidStatus', OrderStatus::PAID)
            ->getQuery()
            ->getResult();
    }

    /**
     * Admin-created GoPay onboardings that the customer signed but never paid.
     * Candidates for `app:send-onboarding-payment-reminders`. Window is bounded
     * by `expiresAt > now` so we don't email customers whose orders are about
     * to be swept up by `app:expire-orders`.
     *
     * @return Order[]
     */
    public function findUnpaidSignedOnboarding(\DateTimeImmutable $now): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.isAdminCreated = :true')
            ->andWhere('o.paymentMethod IN (:paymentMethods)')
            ->andWhere('o.status IN (:openStatuses)')
            ->andWhere('o.signedAt IS NOT NULL')
            ->andWhere('o.expiresAt > :now')
            ->setParameter('true', true)
            ->setParameter('paymentMethods', [PaymentMethod::GOPAY, PaymentMethod::BANK_TRANSFER])
            ->setParameter('openStatuses', [OrderStatus::RESERVED, OrderStatus::AWAITING_PAYMENT])
            ->setParameter('now', $now)
            ->orderBy('o.signedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countUnpaidSignedOnboarding(\DateTimeImmutable $now): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(Order::class, 'o')
            ->where('o.isAdminCreated = :true')
            ->andWhere('o.paymentMethod IN (:paymentMethods)')
            ->andWhere('o.status IN (:openStatuses)')
            ->andWhere('o.signedAt IS NOT NULL')
            ->andWhere('o.expiresAt > :now')
            ->setParameter('true', true)
            ->setParameter('paymentMethods', [PaymentMethod::GOPAY, PaymentMethod::BANK_TRANSFER])
            ->setParameter('openStatuses', [OrderStatus::RESERVED, OrderStatus::AWAITING_PAYMENT])
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Completed (paid) orders that should have an invoice by now but don't.
     * Used by IssueMissingInvoicesCommand as a backstop for the (rare)
     * case where SendRentalActivatedEmailHandler couldn't issue the invoice
     * synchronously — e.g. Fakturoid was unreachable during the post-payment
     * burst. Grace window keeps us out of the way of the synchronous path.
     *
     * EXTERNAL-payment orders are excluded: those were marked "paid"
     * administratively (paper-contract migration, bank-transfer prepayment)
     * without money flowing through the system, so they must never produce
     * an invoice here.
     *
     * @return Order[]
     */
    public function findCompletedWithoutInvoice(\DateTimeImmutable $cutoff): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.status = :completed')
            ->andWhere('o.firstPaymentPrice > 0')
            ->andWhere('o.paidAt IS NOT NULL')
            ->andWhere('o.paidAt < :cutoff')
            ->andWhere('o.paymentMethod IS NULL OR o.paymentMethod != :external')
            ->andWhere('NOT EXISTS (SELECT 1 FROM App\\Entity\\Invoice i WHERE i.order = o)')
            ->setParameter('completed', OrderStatus::COMPLETED)
            ->setParameter('external', PaymentMethod::EXTERNAL)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('o.paidAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countByUser(User $user): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(Order::class, 'o')
            ->where('o.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Order statuses that occupy a storage for its rental window. Shared by the
     * single- and bulk-storage overlap queries so the availability map (via
     * {@see \App\Service\StorageAvailabilityChecker}) can never disagree with
     * order-acceptance enforcement about what "blocking" means.
     *
     * @var list<OrderStatus>
     */
    private const BLOCKING_STATUSES = [
        OrderStatus::CREATED,
        OrderStatus::RESERVED,
        OrderStatus::AWAITING_PAYMENT,
        OrderStatus::PAID,
    ];

    /**
     * Find orders that overlap with a given period and block the storage.
     * Considers orders in active states (created, reserved, awaiting_payment, paid).
     *
     * @return Order[]
     */
    public function findOverlappingByStorage(
        Storage $storage,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        ?Order $excludeOrder = null,
    ): array {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.storage = :storage')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('storage', $storage)
            ->setParameter('statuses', self::BLOCKING_STATUSES);

        if (null !== $excludeOrder) {
            $qb->andWhere('o.id != :excludeId')
                ->setParameter('excludeId', $excludeOrder->id);
        }

        // Spec 076 availability guarantee: a card-recurring (AUTO_RECURRING)
        // order blocks its storage open-endedly while alive — nobody may
        // pre-book any future window of a guaranteed unit. Legacy NULL-end
        // rows block open-endedly too.
        if (null === $endDate) {
            // Requested period is indefinite - any order overlaps if it starts before or ends after requested start
            $qb->andWhere('o.billingMode = :autoRecurring OR o.endDate IS NULL OR o.endDate >= :startDate')
                ->setParameter('startDate', $startDate)
                ->setParameter('autoRecurring', BillingMode::AUTO_RECURRING);
        } else {
            // Standard overlap: startA <= endB AND startB <= endA
            $qb->andWhere('o.startDate <= :endDate')
                ->andWhere('o.billingMode = :autoRecurring OR o.endDate IS NULL OR o.endDate >= :startDate')
                ->setParameter('startDate', $startDate)
                ->setParameter('endDate', $endDate)
                ->setParameter('autoRecurring', BillingMode::AUTO_RECURRING);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Bulk variant of {@see self::findOverlappingByStorage()} — all blocking
     * orders overlapping [$startDate, $endDate] for a set of storages in one
     * query. Mirrors the single-storage predicate exactly (same statuses, same
     * null-end open-ended logic). Used to compute the availability map without
     * an N+1 per storage.
     *
     * @param Storage[] $storages
     *
     * @return Order[]
     */
    public function findOverlappingByStorages(
        array $storages,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
    ): array {
        if ([] === $storages) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.storage IN (:storages)')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('storages', $storages)
            ->setParameter('statuses', self::BLOCKING_STATUSES);

        // Spec 076: AUTO_RECURRING orders block open-endedly (see the single-storage twin).
        if (null === $endDate) {
            $qb->andWhere('o.billingMode = :autoRecurring OR o.endDate IS NULL OR o.endDate >= :startDate')
                ->setParameter('startDate', $startDate)
                ->setParameter('autoRecurring', BillingMode::AUTO_RECURRING);
        } else {
            $qb->andWhere('o.startDate <= :endDate')
                ->andWhere('o.billingMode = :autoRecurring OR o.endDate IS NULL OR o.endDate >= :startDate')
                ->setParameter('startDate', $startDate)
                ->setParameter('endDate', $endDate)
                ->setParameter('autoRecurring', BillingMode::AUTO_RECURRING);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find orders for storages owned by a landlord.
     *
     * @return Order[]
     */
    public function findByLandlord(User $landlord, int $limit = 0): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.storage', 's')
            ->where('s.owner = :landlord')
            ->setParameter('landlord', $landlord)
            ->orderBy('o.createdAt', 'DESC');

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Count paid orders for storages owned by a landlord.
     */
    public function countPaidByLandlord(User $landlord): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(Order::class, 'o')
            ->join('o.storage', 's')
            ->where('s.owner = :landlord')
            ->andWhere('o.status IN (:paidStatuses)')
            ->setParameter('landlord', $landlord)
            ->setParameter('paidStatuses', [OrderStatus::PAID, OrderStatus::COMPLETED])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Sum total revenue (in haléře) from paid orders for storages owned by a landlord.
     */
    public function sumRevenueByLandlord(User $landlord): int
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('SUM(o.firstPaymentPrice)')
            ->from(Order::class, 'o')
            ->join('o.storage', 's')
            ->where('s.owner = :landlord')
            ->andWhere('o.status IN (:paidStatuses)')
            ->setParameter('landlord', $landlord)
            ->setParameter('paidStatuses', [OrderStatus::PAID, OrderStatus::COMPLETED])
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Find active orders (reserved, awaiting_payment, paid, completed) for a storage type
     * that overlap with a given date range.
     *
     * @return Order[]
     */
    public function findActiveByStorageTypeInDateRange(
        \App\Entity\StorageType $storageType,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
    ): array {
        return $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.storage', 's')
            ->where('s.storageType = :storageType')
            ->andWhere('o.status IN (:statuses)')
            ->andWhere('o.startDate <= :endDate')
            ->andWhere('o.endDate IS NULL OR o.endDate >= :startDate')
            ->setParameter('storageType', $storageType)
            ->setParameter('statuses', [
                OrderStatus::CREATED,
                OrderStatus::RESERVED,
                OrderStatus::AWAITING_PAYMENT,
                OrderStatus::PAID,
                OrderStatus::COMPLETED,
            ])
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('o.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active orders for specific storages that overlap with a given date range.
     *
     * @param Storage[] $storages
     *
     * @return Order[]
     */
    public function findActiveByStoragesInDateRange(
        array $storages,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
    ): array {
        if ([] === $storages) {
            return [];
        }

        return $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.storage IN (:storages)')
            ->andWhere('o.status IN (:statuses)')
            ->andWhere('o.startDate <= :endDate')
            ->andWhere('o.endDate IS NULL OR o.endDate >= :startDate')
            ->setParameter('storages', $storages)
            ->setParameter('statuses', [
                OrderStatus::CREATED,
                OrderStatus::RESERVED,
                OrderStatus::AWAITING_PAYMENT,
                OrderStatus::PAID,
                OrderStatus::COMPLETED,
            ])
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('o.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Earliest future startDate per storage among orders that block the storage
     * (CREATED/RESERVED/AWAITING_PAYMENT/PAID) with startDate strictly greater
     * than $strictlyAfter. Counterpart of {@see ContractRepository::findNextStartByStorages()}.
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
            ->select('IDENTITY(o.storage) AS storageId, MIN(o.startDate) AS nextStart')
            ->from(Order::class, 'o')
            ->where('o.storage IN (:storages)')
            ->andWhere('o.status IN (:statuses)')
            ->andWhere('o.startDate > :after')
            ->setParameter('storages', $storages)
            ->setParameter('statuses', [
                OrderStatus::CREATED,
                OrderStatus::RESERVED,
                OrderStatus::AWAITING_PAYMENT,
                OrderStatus::PAID,
            ])
            ->setParameter('after', $strictlyAfter)
            ->groupBy('o.storage')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['storageId']] = new \DateTimeImmutable((string) $row['nextStart']);
        }

        return $result;
    }

    /**
     * @return Order[]
     */
    public function findAllPaginated(int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;

        return $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->orderBy('o.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countTotal(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(Order::class, 'o')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Paginated admin orders, optionally narrowed by an onboarding-related filter
     * and/or a free-text search (order/contract reference or customer name/email).
     *
     * @param ?string $filter null | 'individual' | 'external' | 'ending' | 'free'
     *
     * @return Order[]
     */
    public function findAdminFiltered(\DateTimeImmutable $now, ?string $filter, int $page, int $limit, ?string $search = null): array
    {
        $qb = $this->buildAdminFilteredQueryBuilder($now, $filter, $search)
            ->orderBy('o.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    public function countByAdminFilter(\DateTimeImmutable $now, ?string $filter, ?string $search = null): int
    {
        $qb = $this->buildAdminFilteredQueryBuilder($now, $filter, $search)
            ->select('COUNT(DISTINCT o.id)');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array{individual: int, external: int, ending: int, free: int}
     */
    public function countAllAdminFilters(\DateTimeImmutable $now, ?string $search = null): array
    {
        return [
            'individual' => $this->countByAdminFilter($now, 'individual', $search),
            'external' => $this->countByAdminFilter($now, 'external', $search),
            'ending' => $this->countByAdminFilter($now, 'ending', $search),
            'free' => $this->countByAdminFilter($now, 'free', $search),
        ];
    }

    private function buildAdminFilteredQueryBuilder(\DateTimeImmutable $now, ?string $filter, ?string $search = null): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(Order::class, 'o')
            ->select('o');

        if (null !== $search && '' !== $search) {
            // CAST(uuid AS text) isn't expressible in core DQL, so the textual
            // id/name matching runs as a native query (Postgres `::text`) that
            // returns the matching order ids; we then constrain the DQL builder
            // to that set, keeping the filter predicates (and pagination/count)
            // in one place.
            $matchedIds = $this->searchOrderIds($search);

            if ([] === $matchedIds) {
                // No textual match — force an empty result without an invalid
                // empty `IN ()` (which blows up at the DBAL layer).
                $qb->andWhere('1 = 0');
            } else {
                $qb->andWhere('o.id IN (:searchIds)')->setParameter('searchIds', $matchedIds);
            }
        }

        switch ($filter) {
            case 'individual':
                $qb->andWhere('o.individualMonthlyAmount IS NOT NULL')
                    ->andWhere('o.individualMonthlyAmount > 0');

                break;
            case 'free':
                $qb->andWhere('o.individualMonthlyAmount = 0');

                break;
            case 'external':
                $qb->andWhere('o.paidThroughDate IS NOT NULL');

                break;
            case 'ending':
                $today = $now->setTime(0, 0, 0);
                $qb->andWhere('o.paidThroughDate IS NOT NULL')
                    ->andWhere('o.paidThroughDate >= :endingFrom')
                    ->andWhere('o.paidThroughDate <= :endingTo')
                    ->setParameter('endingFrom', $today->modify('-1 day'))
                    ->setParameter('endingTo', $today->modify('+14 days'));

                break;
        }

        return $qb;
    }

    /**
     * Order ids matching a free-text search. Matches the canonical order
     * reference (spec 067) and the legacy contract-derived number, plus
     * customer name / e-mail.
     *
     * The reference token is the segment after the last '-' so a pasted
     * `2026-0601-019E4643` and a bare `019E4643` both reduce to the same uuid8
     * prefix; it's matched (lower-cased) against the leading 8 hex chars of
     * BOTH the order id and the contract id (the customer-facing number is
     * order-derived, historical "Číslo smlouvy" was contract-derived).
     *
     * @return list<Uuid>
     */
    private function searchOrderIds(string $search): array
    {
        $dashPos = strrpos($search, '-');
        $refToken = strtolower(false !== $dashPos ? substr($search, $dashPos + 1) : $search);

        $rows = $this->entityManager->getConnection()->executeQuery(
            <<<'SQL'
                SELECT o.id::text AS id
                FROM orders o
                INNER JOIN users u ON u.id = o.user_id
                LEFT JOIN contract c ON c.order_id = o.id
                WHERE o.id::text LIKE :ref
                   OR c.id::text LIKE :ref
                   OR LOWER(u.first_name || ' ' || u.last_name) LIKE :nameq
                   OR LOWER(u.email) LIKE :nameq
                SQL,
            [
                'ref' => $refToken.'%',
                'nameq' => '%'.mb_strtolower($search).'%',
            ],
        )->fetchAllAssociative();

        return array_map(static fn (array $r): Uuid => Uuid::fromString((string) $r['id']), $rows);
    }

    /**
     * Streamed iteration for export. Honours the same admin filter as
     * {@see self::findAdminFiltered()} but without pagination.
     *
     * @return iterable<Order>
     */
    public function streamAdminFiltered(\DateTimeImmutable $now, ?string $filter, ?string $search = null): iterable
    {
        $qb = $this->buildAdminFilteredQueryBuilder($now, $filter, $search)
            ->orderBy('o.createdAt', 'DESC');

        $batch = 0;
        foreach ($qb->getQuery()->toIterable() as $order) {
            yield $order;
            if (++$batch >= 200) {
                $this->entityManager->clear();
                $batch = 0;
            }
        }
    }

    public function findBySigningToken(string $token): ?Order
    {
        return $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.signingToken = :token')
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByVariableSymbol(string $variableSymbol): ?Order
    {
        return $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.variableSymbol = :vs')
            ->setParameter('vs', $variableSymbol)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByGoPayPaymentId(string $paymentId): ?Order
    {
        return $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.goPayPaymentId = :paymentId')
            ->setParameter('paymentId', $paymentId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Lookup + row lock used by the GoPay webhook. Concurrent deliveries of the
     * same payment notification serialise on the order row: the second caller
     * blocks until the first commits and then re-reads PAID, falling out of
     * canBePaid(). Without this, both webhooks reach OrderService::completeOrder
     * and the second crashes the contract.order_id unique constraint.
     */
    public function findByGoPayPaymentIdForUpdate(string $paymentId): ?Order
    {
        return $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.goPayPaymentId = :paymentId')
            ->setParameter('paymentId', $paymentId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    public function findByDebtGoPayPaymentIdForUpdate(string $paymentId): ?Order
    {
        return $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.debtGoPayPaymentId = :paymentId')
            ->setParameter('paymentId', $paymentId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /**
     * @return Order[]
     */
    public function findRecent(int $limit): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Recent orders at a place, scoped to an optional owner.
     *
     * @return Order[]
     */
    public function findRecentAtPlace(Place $place, int $limit, ?User $owner): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.storage', 's')
            ->where('s.place = :place')
            ->setParameter('place', $place)
            ->orderBy('o.createdAt', 'DESC');

        if (null !== $owner) {
            $qb->andWhere('s.owner = :owner')->setParameter('owner', $owner);
        }

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Orders that are still in flight (RESERVED / AWAITING_PAYMENT / PAID) and
     * scheduled to start within $daysAhead from $now. Surfaces "incoming tenants".
     *
     * @return Order[]
     */
    public function findUpcomingAtPlace(
        Place $place,
        int $daysAhead,
        \DateTimeImmutable $now,
        ?User $owner,
    ): array {
        $futureDate = $now->modify("+{$daysAhead} days");

        $qb = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.storage', 's')
            ->where('s.place = :place')
            ->andWhere('o.status IN (:statuses)')
            ->andWhere('o.startDate >= :now')
            ->andWhere('o.startDate <= :futureDate')
            ->setParameter('place', $place)
            ->setParameter('statuses', [
                OrderStatus::RESERVED,
                OrderStatus::AWAITING_PAYMENT,
                OrderStatus::PAID,
            ])
            ->setParameter('now', $now)
            ->setParameter('futureDate', $futureDate)
            ->orderBy('o.startDate', 'ASC');

        if (null !== $owner) {
            $qb->andWhere('s.owner = :owner')->setParameter('owner', $owner);
        }

        return $qb->getQuery()->getResult();
    }
}
