<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contract;
use App\Entity\HandoverProtocol;
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

    /**
     * Find incomplete handover protocols for sending reminders.
     * Only returns protocols where last reminder was sent more than 3 days ago (or never sent).
     *
     * @return HandoverProtocol[]
     */
    public function findIncompleteForReminders(\DateTimeImmutable $now): array
    {
        $reminderThreshold = $now->modify('-3 days');

        return $this->entityManager->createQueryBuilder()
            ->select('hp')
            ->from(HandoverProtocol::class, 'hp')
            ->where('hp.status != :completed')
            ->andWhere('hp.lastReminderSentAt IS NULL OR hp.lastReminderSentAt <= :threshold')
            ->setParameter('completed', 'completed')
            ->setParameter('threshold', $reminderThreshold)
            ->getQuery()
            ->getResult();
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
