<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contract;
use App\Entity\Fine;
use App\Entity\User;
use App\Service\Payment\VariableSymbolGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\Uid\Uuid;

final class FineRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Fine $fine): void
    {
        $this->entityManager->persist($fine);
    }

    public function findById(Uuid $id): ?Fine
    {
        return $this->entityManager->find(Fine::class, $id);
    }

    public function findByVariableSymbol(string $vs): ?Fine
    {
        $normalized = VariableSymbolGenerator::normalize($vs);

        if (null === $normalized) {
            return null;
        }

        return $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(Fine::class, 'f')
            // Compare numerically-equivalent symbols: we historically issued
            // zero-padded values that the bank returns unpadded (spec 090).
            ->where("TRIM(LEADING '0' FROM f.variableSymbol) = :vs")
            ->setParameter('vs', $normalized)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByGoPayPaymentId(string $paymentId): ?Fine
    {
        return $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(Fine::class, 'f')
            ->where('f.goPayPaymentId = :paymentId')
            ->setParameter('paymentId', $paymentId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Fine[]
     */
    public function findByContract(Contract $contract): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(Fine::class, 'f')
            ->where('f.contract = :contract')
            ->setParameter('contract', $contract)
            ->orderBy('f.issuedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Fine[]
     */
    public function findUnpaidByUser(User $user): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(Fine::class, 'f')
            ->where('f.user = :user')
            ->andWhere('f.paidAt IS NULL')
            ->andWhere('f.cancelledAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('f.issuedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Fine[]
     */
    public function findUnpaidForReminder(int $daysSinceIssued, bool $isFirstReminder): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(Fine::class, 'f')
            ->where('f.paidAt IS NULL')
            ->andWhere('f.cancelledAt IS NULL')
            ->andWhere('f.issuedAt <= :threshold')
            ->setParameter('threshold', new \DateTimeImmutable(sprintf('-%d days', $daysSinceIssued)));

        if ($isFirstReminder) {
            $qb->andWhere('f.reminder1SentAt IS NULL');
        } else {
            $qb->andWhere('f.reminder2SentAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    public function countUnpaid(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(f.id)')
            ->from(Fine::class, 'f')
            ->where('f.paidAt IS NULL')
            ->andWhere('f.cancelledAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Paginator<Fine>
     */
    public function findAllFiltered(?string $status, ?string $search, int $page, int $limit = 20): Paginator
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(Fine::class, 'f')
            ->join('f.user', 'u')
            ->orderBy('f.issuedAt', 'DESC');

        if ('unpaid' === $status) {
            $qb->andWhere('f.paidAt IS NULL')->andWhere('f.cancelledAt IS NULL');
        } elseif ('paid' === $status) {
            $qb->andWhere('f.paidAt IS NOT NULL');
        } elseif ('cancelled' === $status) {
            $qb->andWhere('f.cancelledAt IS NOT NULL');
        }

        if (null !== $search && '' !== $search) {
            $qb->andWhere('LOWER(u.email) LIKE :search OR LOWER(u.firstName) LIKE :search OR LOWER(u.lastName) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($qb->getQuery());
    }

    /**
     * Variable symbol uniqueness check. Currently unused — VariableSymbolGenerator
     * runs its own probe — but kept normalised so a future caller cannot
     * reintroduce the leading-zero mismatch this comparison used to have (spec 090).
     */
    public function existsByVariableSymbol(string $vs): bool
    {
        $normalized = VariableSymbolGenerator::normalize($vs);

        if (null === $normalized) {
            return false;
        }

        return null !== $this->entityManager->createQueryBuilder()
            ->select('1')
            ->from(Fine::class, 'f')
            ->where("TRIM(LEADING '0' FROM f.variableSymbol) = :vs")
            ->setParameter('vs', $normalized)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Fine[]
     */
    public function findAllForExport(?string $status): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(Fine::class, 'f')
            ->join('f.user', 'u')
            ->join('f.issuedBy', 'admin')
            ->orderBy('f.issuedAt', 'DESC');

        if ('unpaid' === $status) {
            $qb->andWhere('f.paidAt IS NULL')->andWhere('f.cancelledAt IS NULL');
        } elseif ('paid' === $status) {
            $qb->andWhere('f.paidAt IS NOT NULL');
        } elseif ('cancelled' === $status) {
            $qb->andWhere('f.cancelledAt IS NOT NULL');
        }

        return $qb->getQuery()->getResult();
    }
}
