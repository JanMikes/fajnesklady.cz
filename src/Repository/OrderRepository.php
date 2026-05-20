<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\User;
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
            ->andWhere('o.paymentMethod = :gopay')
            ->andWhere('o.status IN (:openStatuses)')
            ->andWhere('o.signedAt IS NOT NULL')
            ->andWhere('o.expiresAt > :now')
            ->setParameter('true', true)
            ->setParameter('gopay', PaymentMethod::GOPAY)
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
            ->andWhere('o.paymentMethod = :gopay')
            ->andWhere('o.status IN (:openStatuses)')
            ->andWhere('o.signedAt IS NOT NULL')
            ->andWhere('o.expiresAt > :now')
            ->setParameter('true', true)
            ->setParameter('gopay', PaymentMethod::GOPAY)
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
            ->setParameter('statuses', [
                OrderStatus::CREATED,
                OrderStatus::RESERVED,
                OrderStatus::AWAITING_PAYMENT,
                OrderStatus::PAID,
            ]);

        if (null !== $excludeOrder) {
            $qb->andWhere('o.id != :excludeId')
                ->setParameter('excludeId', $excludeOrder->id);
        }

        if (null === $endDate) {
            // Requested period is indefinite - any order overlaps if it starts before or ends after requested start
            $qb->andWhere('o.endDate IS NULL OR o.endDate >= :startDate')
                ->setParameter('startDate', $startDate);
        } else {
            // Standard overlap: startA <= endB AND startB <= endA
            $qb->andWhere('o.startDate <= :endDate')
                ->andWhere('o.endDate IS NULL OR o.endDate >= :startDate')
                ->setParameter('startDate', $startDate)
                ->setParameter('endDate', $endDate);
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
     * Paginated admin orders, optionally narrowed by an onboarding-related filter.
     *
     * @param ?string $filter null | 'individual' | 'external' | 'ending' | 'free'
     *
     * @return Order[]
     */
    public function findAdminFiltered(\DateTimeImmutable $now, ?string $filter, int $page, int $limit): array
    {
        $qb = $this->buildAdminFilteredQueryBuilder($now, $filter)
            ->orderBy('o.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    public function countByAdminFilter(\DateTimeImmutable $now, ?string $filter): int
    {
        $qb = $this->buildAdminFilteredQueryBuilder($now, $filter)
            ->select('COUNT(o.id)');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array{individual: int, external: int, ending: int, free: int}
     */
    public function countAllAdminFilters(\DateTimeImmutable $now): array
    {
        return [
            'individual' => $this->countByAdminFilter($now, 'individual'),
            'external' => $this->countByAdminFilter($now, 'external'),
            'ending' => $this->countByAdminFilter($now, 'ending'),
            'free' => $this->countByAdminFilter($now, 'free'),
        ];
    }

    private function buildAdminFilteredQueryBuilder(\DateTimeImmutable $now, ?string $filter): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(Order::class, 'o')
            ->select('o');

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
     * Streamed iteration for export. Honours the same admin filter as
     * {@see self::findAdminFiltered()} but without pagination.
     *
     * @return iterable<Order>
     */
    public function streamAdminFiltered(\DateTimeImmutable $now, ?string $filter): iterable
    {
        $qb = $this->buildAdminFilteredQueryBuilder($now, $filter)
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
