<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contract;
use App\Entity\HandoverProtocol;
use App\Enum\HandoverStatus;
use App\Exception\HandoverProtocolNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class HandoverProtocolRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(HandoverProtocol $handoverProtocol): void
    {
        $this->entityManager->persist($handoverProtocol);
    }

    public function get(Uuid $id): HandoverProtocol
    {
        return $this->entityManager->find(HandoverProtocol::class, $id)
            ?? throw HandoverProtocolNotFound::withId($id);
    }

    public function findByContract(Contract $contract): ?HandoverProtocol
    {
        return $this->entityManager->createQueryBuilder()
            ->select('hp')
            ->from(HandoverProtocol::class, 'hp')
            ->where('hp.contract = :contract')
            ->setParameter('contract', $contract)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function remove(HandoverProtocol $handoverProtocol): void
    {
        $this->entityManager->remove($handoverProtocol);
    }

    /**
     * Incomplete protocols due for a reminder: 3+ days since the last
     * reminder — or, for the first one, since the protocol was created (the
     * creation itself already e-mailed the request; reminding in the same
     * run is a duplicate).
     *
     * Only protocols inside the handover window qualify: a contract that was
     * prolonged (or otherwise runs on) past it keeps its unit, so nagging the
     * tenant to hand it over would be wrong. Mirrors
     * {@see Contract::isWithinHandoverWindow()}.
     *
     * @return HandoverProtocol[]
     */
    public function findIncompleteForReminders(\DateTimeImmutable $now): array
    {
        $reminderThreshold = $now->modify('-3 days');
        $windowEnd = $now->modify(sprintf('+%d days', HandoverProtocol::WINDOW_DAYS_BEFORE_END));

        return $this->entityManager->createQueryBuilder()
            ->select('hp')
            ->from(HandoverProtocol::class, 'hp')
            ->join('hp.contract', 'c')
            ->where('hp.status != :completed')
            ->andWhere('(hp.lastReminderSentAt IS NULL AND hp.createdAt <= :threshold) OR hp.lastReminderSentAt <= :threshold')
            ->andWhere('c.terminatedAt IS NOT NULL OR COALESCE(c.terminatesAt, c.endDate) <= :windowEnd')
            ->setParameter('completed', HandoverStatus::COMPLETED->value)
            ->setParameter('threshold', $reminderThreshold)
            ->setParameter('windowEnd', $windowEnd)
            ->getQuery()
            ->getResult();
    }

    /**
     * Non-completed protocols across the whole portfolio. Drives the
     * admin Operations hub list and the count badge.
     *
     * @return HandoverProtocol[]
     */
    public function findPending(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('hp')
            ->from(HandoverProtocol::class, 'hp')
            ->where('hp.status != :completed')
            ->setParameter('completed', HandoverStatus::COMPLETED->value)
            ->orderBy('hp.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countPending(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(hp.id)')
            ->from(HandoverProtocol::class, 'hp')
            ->where('hp.status != :completed')
            ->setParameter('completed', HandoverStatus::COMPLETED->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find incomplete handover protocols where the contract has been terminated
     * for more than the given number of days (for force-release).
     *
     * @return HandoverProtocol[]
     */
    public function findExpiredForForceRelease(\DateTimeImmutable $now, int $daysAfterTermination = 14): array
    {
        $threshold = $now->modify("-{$daysAfterTermination} days");

        return $this->entityManager->createQueryBuilder()
            ->select('hp')
            ->from(HandoverProtocol::class, 'hp')
            ->join('hp.contract', 'c')
            ->join('c.storage', 's')
            ->where('hp.status != :completed')
            ->andWhere('c.terminatedAt IS NOT NULL')
            ->andWhere('c.terminatedAt <= :threshold')
            ->andWhere('s.status = :occupied')
            ->setParameter('completed', 'completed')
            ->setParameter('threshold', $threshold)
            ->setParameter('occupied', 'occupied')
            ->getQuery()
            ->getResult();
    }
}
