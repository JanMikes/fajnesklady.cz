<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contract;
use App\Entity\ManualPaymentRequest;
use App\Exception\ManualPaymentRequestNotFound;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class ManualPaymentRequestRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ManualPaymentRequest $request): void
    {
        $this->entityManager->persist($request);
    }

    public function get(Uuid $id): ManualPaymentRequest
    {
        return $this->entityManager->find(ManualPaymentRequest::class, $id)
            ?? throw ManualPaymentRequestNotFound::withId($id);
    }

    public function findByContractAndPeriod(Contract $contract, \DateTimeImmutable $periodStart): ?ManualPaymentRequest
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ManualPaymentRequest::class, 'r')
            ->where('r.contract = :contract')
            ->andWhere('r.periodStart = :periodStart')
            ->setParameter('contract', $contract)
            ->setParameter('periodStart', $periodStart->setTime(0, 0, 0))
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByGoPayPaymentId(string $paymentId): ?ManualPaymentRequest
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ManualPaymentRequest::class, 'r')
            ->where('r.goPayPaymentId = :paymentId')
            ->setParameter('paymentId', $paymentId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Acquire a row lock for the per-stage idempotency check. Concurrent cron
     * runs serialise on the row; the second caller blocks until the first
     * commits, then sees the already-recorded stage and no-ops. Returns null
     * when the row doesn't exist yet — the caller must then create one, and
     * the unique constraint on (contract_id, period_start) backstops any
     * application-level race between two creators.
     */
    public function lockForUpdate(Contract $contract, \DateTimeImmutable $periodStart): ?ManualPaymentRequest
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ManualPaymentRequest::class, 'r')
            ->where('r.contract = :contract')
            ->andWhere('r.periodStart = :periodStart')
            ->setParameter('contract', $contract)
            ->setParameter('periodStart', $periodStart->setTime(0, 0, 0))
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /**
     * Pending (not paid, not cancelled, not expired) request whose period
     * spans $now. Used by the view-model to surface a "Zaplatit nyní" link
     * on the customer's order detail / status pages.
     */
    public function findPendingForCurrentCycle(Contract $contract, \DateTimeImmutable $now): ?ManualPaymentRequest
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ManualPaymentRequest::class, 'r')
            ->where('r.contract = :contract')
            ->andWhere('r.status = :pending')
            ->andWhere('r.periodEnd >= :now')
            ->setParameter('contract', $contract)
            ->setParameter('pending', ManualPaymentRequest::STATUS_PENDING)
            ->setParameter('now', $now)
            ->orderBy('r.periodStart', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
