<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contract;
use App\Entity\Order;
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

    public function findByOrder(Order $order): ?Payment
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Payment::class, 'p')
            ->where('p.order = :order')
            ->setParameter('order', $order)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByContractAndPaidAt(Contract $contract, \DateTimeImmutable $paidAt): ?Payment
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Payment::class, 'p')
            ->where('p.contract = :contract')
            ->andWhere('p.paidAt = :paidAt')
            ->setParameter('contract', $contract)
            ->setParameter('paidAt', $paidAt)
            ->getQuery()
            ->getOneOrNullResult();
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

        /** @var Payment[] $payments */
        $payments = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Payment::class, 'p')
            ->join('p.storage', 's')
            ->where('s.owner = :landlord')
            ->andWhere('p.paidAt >= :startDate')
            ->andWhere('p.paidAt < :endDate')
            ->setParameter('landlord', $landlord)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $now->modify('first day of next month midnight'))
            ->getQuery()
            ->getResult();

        return $this->groupPaymentsByMonth($payments);
    }

    /**
     * Get monthly revenue totals across ALL landlords over the last N months.
     *
     * @return array<array{year: int, month: int, total: int}>
     */
    public function getMonthlyRevenueAll(int $months, \DateTimeImmutable $now): array
    {
        $startDate = $now->modify("-{$months} months")->modify('first day of this month midnight');

        /** @var Payment[] $payments */
        $payments = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Payment::class, 'p')
            ->where('p.paidAt >= :startDate')
            ->andWhere('p.paidAt < :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $now->modify('first day of next month midnight'))
            ->getQuery()
            ->getResult();

        return $this->groupPaymentsByMonth($payments);
    }

    /**
     * Group payments by year and month.
     *
     * @param Payment[] $payments
     * @return array<array{year: int, month: int, total: int}>
     */
    private function groupPaymentsByMonth(array $payments): array
    {
        $grouped = [];

        foreach ($payments as $payment) {
            $year = (int) $payment->paidAt->format('Y');
            $month = (int) $payment->paidAt->format('n');
            $key = sprintf('%d-%02d', $year, $month);

            if (!isset($grouped[$key])) {
                $grouped[$key] = ['year' => $year, 'month' => $month, 'total' => 0];
            }

            $grouped[$key]['total'] += $payment->amount;
        }

        usort($grouped, static fn(array $a, array $b): int => ($a['year'] <=> $b['year']) ?: ($a['month'] <=> $b['month']));

        return $grouped;
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
