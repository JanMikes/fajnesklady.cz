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
