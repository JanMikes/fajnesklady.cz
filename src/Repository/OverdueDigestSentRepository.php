<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OverdueDigestSent;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class OverdueDigestSentRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function wasSentForAdminOn(User $admin, \DateTimeImmutable $date): bool
    {
        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(OverdueDigestSent::class, 'd')
            ->where('d.admin = :admin')
            ->andWhere('d.date = :date')
            ->setParameter('admin', $admin)
            ->setParameter('date', $date->setTime(0, 0, 0))
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function save(OverdueDigestSent $row): void
    {
        $this->entityManager->persist($row);
    }
}
