<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Payment;
use App\Entity\SelfBillingInvoice;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class PaymentRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Payment $payment): void
    {
        $this->entityManager->persist($payment);
    }

    public function find(Uuid $id): ?Payment
    {
        return $this->entityManager->find(Payment::class, $id);
    }

    /**
     * Find all payments for storages owned by a landlord in a specific period.
     *
     * @return Payment[]
     */
    public function findByStorageOwnerAndPeriod(User $landlord, int $year, int $month): array
    {
        $startDate = new \DateTimeImmutable(sprintf('%d-%02d-01 00:00:00', $year, $month));
        $endDate = $startDate->modify('first day of next month');

        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Payment::class, 'p')
            ->join('p.storage', 's')
            ->where('s.owner = :landlord')
            ->andWhere('p.paidAt >= :startDate')
            ->andWhere('p.paidAt < :endDate')
            ->setParameter('landlord', $landlord)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('p.paidAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find unbilled payments for storages owned by a landlord in a specific period.
     *
     * @return Payment[]
     */
    public function findUnbilledByStorageOwner(User $landlord, int $year, int $month): array
    {
        $startDate = new \DateTimeImmutable(sprintf('%d-%02d-01 00:00:00', $year, $month));
        $endDate = $startDate->modify('first day of next month');

        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Payment::class, 'p')
            ->join('p.storage', 's')
            ->where('s.owner = :landlord')
            ->andWhere('p.paidAt >= :startDate')
            ->andWhere('p.paidAt < :endDate')
            ->andWhere('p.selfBillingInvoice IS NULL')
            ->setParameter('landlord', $landlord)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('p.paidAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Sum total amount of payments for storages owned by a landlord in a specific period.
     */
    public function sumByStorageOwnerAndPeriod(User $landlord, int $year, int $month): int
    {
        $startDate = new \DateTimeImmutable(sprintf('%d-%02d-01 00:00:00', $year, $month));
        $endDate = $startDate->modify('first day of next month');

        $result = $this->entityManager->createQueryBuilder()
            ->select('SUM(p.amount)')
            ->from(Payment::class, 'p')
            ->join('p.storage', 's')
            ->where('s.owner = :landlord')
            ->andWhere('p.paidAt >= :startDate')
            ->andWhere('p.paidAt < :endDate')
            ->setParameter('landlord', $landlord)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Find all payments linked to a self-billing invoice.
     *
     * @return Payment[]
     */
    public function findBySelfBillingInvoice(SelfBillingInvoice $invoice): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Payment::class, 'p')
            ->where('p.selfBillingInvoice = :invoice')
            ->setParameter('invoice', $invoice)
            ->orderBy('p.paidAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
