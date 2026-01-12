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

final class OrderRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Order $order): void
    {
        $this->entityManager->persist($order);
    }

    public function get(Uuid $id): Order
    {
        return $this->entityManager->find(Order::class, $id)
            ?? throw OrderNotFound::withId($id);
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
}
