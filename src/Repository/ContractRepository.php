<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\User;
use App\Exception\ContractNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class ContractRepository
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
}
