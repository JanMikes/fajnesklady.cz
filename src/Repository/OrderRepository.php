<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Exception\OrderNotFound;
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
     * Find orders that block a storage (reserved, awaiting payment, or paid).
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
                OrderStatus::RESERVED,
                OrderStatus::AWAITING_PAYMENT,
                OrderStatus::PAID,
            ])
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
     * Only considers orders in reserving states (reserved, awaiting_payment, paid).
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
            ->select('SUM(o.totalPrice)')
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
                OrderStatus::RESERVED,
                OrderStatus::AWAITING_PAYMENT,
                OrderStatus::PAID,
                OrderStatus::COMPLETED,
            ])
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
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
                OrderStatus::RESERVED,
                OrderStatus::AWAITING_PAYMENT,
                OrderStatus::PAID,
                OrderStatus::COMPLETED,
            ])
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->getQuery()
            ->getResult();
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
}
