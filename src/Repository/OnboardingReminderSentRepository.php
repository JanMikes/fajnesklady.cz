<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OnboardingReminderSent;
use App\Entity\Order;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class OnboardingReminderSentRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(OnboardingReminderSent $row): void
    {
        $this->entityManager->persist($row);
    }

    public function findByOrderAndStage(Order $order, string $stage): ?OnboardingReminderSent
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(OnboardingReminderSent::class, 'r')
            ->where('r.order = :order')
            ->andWhere('r.stage = :stage')
            ->setParameter('order', $order)
            ->setParameter('stage', $stage)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Acquire a row lock for the per-stage idempotency check. Concurrent cron
     * runs serialise on the row; the second caller blocks until the first
     * commits, then sees the already-recorded stage and no-ops. Returns null
     * when the row doesn't exist yet — the unique constraint on
     * (order_id, stage) backstops any application-level race between two
     * creators.
     */
    public function findByOrderAndStageWithLock(Order $order, string $stage): ?OnboardingReminderSent
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(OnboardingReminderSent::class, 'r')
            ->where('r.order = :order')
            ->andWhere('r.stage = :stage')
            ->setParameter('order', $order)
            ->setParameter('stage', $stage)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }
}
