<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BankTransaction;
use App\Entity\Contract;
use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class BankTransactionRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(BankTransaction $transaction): void
    {
        $this->entityManager->persist($transaction);
    }

    public function find(Uuid $id): ?BankTransaction
    {
        return $this->entityManager->find(BankTransaction::class, $id);
    }

    public function existsByFioTransactionId(string $fioTransactionId): bool
    {
        return null !== $this->entityManager->createQueryBuilder()
            ->select('1')
            ->from(BankTransaction::class, 'bt')
            ->where('bt.fioTransactionId = :fioId')
            ->setParameter('fioId', $fioTransactionId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return BankTransaction[]
     */
    public function findAll(string $statusFilter = 'all'): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('bt')
            ->from(BankTransaction::class, 'bt')
            ->orderBy('bt.transactionDate', 'DESC');

        if ('all' === $statusFilter) {
            $qb->where('bt.status != :ignored')
                ->setParameter('ignored', 'ignored');
        } else {
            $qb->where('bt.status = :status')
                ->setParameter('status', $statusFilter);
        }

        return $qb->getQuery()->getResult();
    }

    public function countUnmatched(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(bt.id)')
            ->from(BankTransaction::class, 'bt')
            ->where('bt.status = :status')
            ->setParameter('status', 'unmatched')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countIgnored(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(bt.id)')
            ->from(BankTransaction::class, 'bt')
            ->where('bt.status = :status')
            ->setParameter('status', 'ignored')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return BankTransaction[]
     */
    public function findAmountMismatchByOrder(Order $order): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('bt')
            ->from(BankTransaction::class, 'bt')
            ->where('bt.pairedOrder = :order')
            ->andWhere('bt.status = :status')
            ->setParameter('order', $order)
            ->setParameter('status', 'amount_mismatch')
            ->orderBy('bt.transactionDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function sumAmountMismatchByOrder(Order $order): int
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('SUM(bt.amount)')
            ->from(BankTransaction::class, 'bt')
            ->where('bt.pairedOrder = :order')
            ->andWhere('bt.status = :status')
            ->setParameter('order', $order)
            ->setParameter('status', 'amount_mismatch')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    public function sumReceivedByOrder(Order $order): int
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('SUM(bt.amount)')
            ->from(BankTransaction::class, 'bt')
            ->where('bt.pairedOrder = :order')
            ->andWhere('bt.status IN (:statuses)')
            ->setParameter('order', $order)
            ->setParameter('statuses', ['matched', 'amount_mismatch'])
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * @return BankTransaction[]
     */
    public function findByOrder(Order $order): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('bt')
            ->from(BankTransaction::class, 'bt')
            ->where('bt.pairedOrder = :order')
            ->setParameter('order', $order)
            ->orderBy('bt.transactionDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return BankTransaction[]
     */
    public function findByContract(Contract $contract): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('bt')
            ->from(BankTransaction::class, 'bt')
            ->where('bt.pairedContract = :contract')
            ->setParameter('contract', $contract)
            ->orderBy('bt.transactionDate', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
