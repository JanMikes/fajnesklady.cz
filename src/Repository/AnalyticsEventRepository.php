<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AnalyticsEvent;
use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AnalyticsEventRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(AnalyticsEvent $event): void
    {
        $this->entityManager->persist($event);
    }

    /**
     * Events for this order that no browser has received yet, oldest first so
     * order_created is pushed before first_payment_success when both are
     * waiting (a bank transfer confirmed before the customer ever reopened the
     * site leaves exactly that pair).
     *
     * @return list<AnalyticsEvent>
     */
    public function findUnpushedForOrder(Order $order): array
    {
        /** @var list<AnalyticsEvent> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(AnalyticsEvent::class, 'e')
            ->where('e.order = :order')
            ->andWhere('e.pushedAt IS NULL')
            ->setParameter('order', $order)
            ->orderBy('e.occurredAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function existsForOrderAndName(Order $order, string $name): bool
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(AnalyticsEvent::class, 'e')
            ->where('e.order = :order')
            ->andWhere('e.name = :name')
            ->setParameter('order', $order)
            ->setParameter('name', $name)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    public function find(\Symfony\Component\Uid\Uuid $id): ?AnalyticsEvent
    {
        return $this->entityManager->find(AnalyticsEvent::class, $id);
    }
}
