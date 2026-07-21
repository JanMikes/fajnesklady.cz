<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BankAccountMapping;
use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class BankAccountMappingRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(BankAccountMapping $mapping): void
    {
        $this->entityManager->persist($mapping);
    }

    public function find(Uuid $id): ?BankAccountMapping
    {
        return $this->entityManager->find(BankAccountMapping::class, $id);
    }

    public function delete(BankAccountMapping $mapping): void
    {
        $this->entityManager->remove($mapping);
    }

    /**
     * Every mapping registered for a payer account, newest first.
     *
     * Replaces a `setMaxResults(1)` lookup that had no ORDER BY: once one account
     * funded several orders it routed money to an arbitrary one. Callers must
     * decide what to do with more than one (spec 091 requirement 6).
     *
     * @return BankAccountMapping[]
     */
    public function findAllByAccountNumber(string $accountNumber): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(BankAccountMapping::class, 'm')
            ->where('m.accountNumber = :accountNumber')
            ->setParameter('accountNumber', $accountNumber)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function existsForAccountAndOrder(string $accountNumber, Order $order): bool
    {
        return null !== $this->entityManager->createQueryBuilder()
            ->select('1')
            ->from(BankAccountMapping::class, 'm')
            ->where('m.accountNumber = :accountNumber')
            ->andWhere('m.order = :order')
            ->setParameter('accountNumber', $accountNumber)
            ->setParameter('order', $order)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByAccountNumber(string $accountNumber): ?BankAccountMapping
    {
        return $this->entityManager->createQueryBuilder()
            ->select('bam')
            ->from(BankAccountMapping::class, 'bam')
            ->where('bam.accountNumber = :accountNumber')
            ->setParameter('accountNumber', $accountNumber)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return BankAccountMapping[]
     */
    public function findByOrder(Order $order): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('bam')
            ->from(BankAccountMapping::class, 'bam')
            ->where('bam.order = :order')
            ->setParameter('order', $order)
            ->orderBy('bam.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
