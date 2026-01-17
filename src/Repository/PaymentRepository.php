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

    /**
     * Get monthly revenue totals for a landlord over the last N months.
     *
     * @return array<array{year: int, month: int, total: int}>
     */
    public function getMonthlyRevenueByLandlord(User $landlord, int $months, \DateTimeImmutable $now): array
    {
        $startDate = $now->modify("-{$months} months")->modify('first day of this month midnight');

        $result = $this->entityManager->createQueryBuilder()
            ->select('YEAR(p.paidAt) as year, MONTH(p.paidAt) as month, SUM(p.amount) as total')
            ->from(Payment::class, 'p')
            ->join('p.storage', 's')
            ->where('s.owner = :landlord')
            ->andWhere('p.paidAt >= :startDate')
            ->andWhere('p.paidAt < :endDate')
            ->setParameter('landlord', $landlord)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $now->modify('first day of next month midnight'))
            ->groupBy('year, month')
            ->orderBy('year', 'ASC')
            ->addOrderBy('month', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(fn(array $row) => [
            'year' => (int) $row['year'],
            'month' => (int) $row['month'],
            'total' => (int) ($row['total'] ?? 0),
        ], $result);
    }

    /**
     * Get monthly revenue totals across ALL landlords over the last N months.
     *
     * @return array<array{year: int, month: int, total: int}>
     */
    public function getMonthlyRevenueAll(int $months, \DateTimeImmutable $now): array
    {
        $startDate = $now->modify("-{$months} months")->modify('first day of this month midnight');

        $result = $this->entityManager->createQueryBuilder()
            ->select('YEAR(p.paidAt) as year, MONTH(p.paidAt) as month, SUM(p.amount) as total')
            ->from(Payment::class, 'p')
            ->where('p.paidAt >= :startDate')
            ->andWhere('p.paidAt < :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $now->modify('first day of next month midnight'))
            ->groupBy('year, month')
            ->orderBy('year', 'ASC')
            ->addOrderBy('month', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(fn(array $row) => [
            'year' => (int) $row['year'],
            'month' => (int) $row['month'],
            'total' => (int) ($row['total'] ?? 0),
        ], $result);
    }

    /**
     * Sum total payments across ALL landlords for a specific period.
     */
    public function sumAllByPeriod(int $year, int $month): int
    {
        $startDate = new \DateTimeImmutable(sprintf('%d-%02d-01 00:00:00', $year, $month));
        $endDate = $startDate->modify('first day of next month');

        $result = $this->entityManager->createQueryBuilder()
            ->select('SUM(p.amount)')
            ->from(Payment::class, 'p')
            ->where('p.paidAt >= :startDate')
            ->andWhere('p.paidAt < :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }
}
