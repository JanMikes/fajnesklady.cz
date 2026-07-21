<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BankTransaction;
use App\Entity\BankTransactionAllocation;
use App\Entity\Order;
use App\Enum\AllocationStepType;
use Doctrine\ORM\EntityManagerInterface;

final class BankTransactionAllocationRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(BankTransactionAllocation $allocation): void
    {
        $this->entityManager->persist($allocation);
    }

    /**
     * How much of this order's money has already gone to one specific obligation.
     *
     * Scoped by type on purpose: this is what keeps debt money and first-payment
     * money from being counted against each other (spec 091 D2).
     */
    public function sumForOrderByType(Order $order, AllocationStepType $type): int
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('SUM(a.amountInHaler)')
            ->from(BankTransactionAllocation::class, 'a')
            ->where('a.order = :order')
            ->andWhere('a.type = :type')
            ->setParameter('order', $order)
            ->setParameter('type', $type)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Money allocated to settling debts rather than to rent.
     *
     * Debt settlement deliberately creates no `Payment` row — it clears an
     * obligation, it is not rental revenue ({@see \App\Service\Onboarding\DebtPaymentService::confirmDebtPaid()}).
     * Any admin reconciliation that compares received bank money against Payment
     * rows must therefore discount this, or every order that settled a debt by
     * transfer looks like a pairing error.
     */
    public function sumDebtAllocationsForOrder(Order $order): int
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('SUM(a.amountInHaler)')
            ->from(BankTransactionAllocation::class, 'a')
            ->where('a.order = :order')
            ->andWhere('a.type IN (:debtTypes)')
            ->setParameter('order', $order)
            ->setParameter('debtTypes', [
                AllocationStepType::ONBOARDING_DEBT,
                AllocationStepType::CONTRACT_DEBT,
            ])
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Drop everything a transaction was previously allocated to.
     *
     * Needed before an admin re-pairs a partially-allocated row: replaying the
     * transaction's full amount while its earlier allocations still stand would
     * count the same money twice, and re-pointing it at a different order would
     * leave the old order permanently credited with money it no longer has.
     *
     * A bulk DQL delete (not remove() + flush) so the rows are gone before the
     * allocator's sumForOrderByType() queries run in the same transaction.
     */
    public function deleteForTransaction(BankTransaction $transaction): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->delete(BankTransactionAllocation::class, 'a')
            ->where('a.bankTransaction = :transaction')
            ->setParameter('transaction', $transaction)
            ->getQuery()
            ->execute();
    }

    /**
     * @return BankTransactionAllocation[]
     */
    public function findByOrder(Order $order): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(BankTransactionAllocation::class, 'a')
            ->where('a.order = :order')
            ->setParameter('order', $order)
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
