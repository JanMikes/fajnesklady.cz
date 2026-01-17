<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SelfBillingInvoice;
use App\Entity\User;
use App\Exception\SelfBillingInvoiceNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class SelfBillingInvoiceRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(SelfBillingInvoice $invoice): void
    {
        $this->entityManager->persist($invoice);
    }

    public function find(Uuid $id): ?SelfBillingInvoice
    {
        return $this->entityManager->find(SelfBillingInvoice::class, $id);
    }

    public function get(Uuid $id): SelfBillingInvoice
    {
        return $this->find($id) ?? throw SelfBillingInvoiceNotFound::withId($id);
    }

    /**
     * Find self-billing invoice for a landlord in a specific period.
     */
    public function findByLandlordAndPeriod(User $landlord, int $year, int $month): ?SelfBillingInvoice
    {
        return $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(SelfBillingInvoice::class, 'i')
            ->where('i.landlord = :landlord')
            ->andWhere('i.year = :year')
            ->andWhere('i.month = :month')
            ->setParameter('landlord', $landlord)
            ->setParameter('year', $year)
            ->setParameter('month', $month)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all self-billing invoices for a landlord.
     *
     * @return SelfBillingInvoice[]
     */
    public function findByLandlord(User $landlord): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(SelfBillingInvoice::class, 'i')
            ->where('i.landlord = :landlord')
            ->setParameter('landlord', $landlord)
            ->orderBy('i.year', 'DESC')
            ->addOrderBy('i.month', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all self-billing invoices paginated.
     *
     * @return SelfBillingInvoice[]
     */
    public function findAllPaginated(int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;

        return $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(SelfBillingInvoice::class, 'i')
            ->orderBy('i.year', 'DESC')
            ->addOrderBy('i.month', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countTotal(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(SelfBillingInvoice::class, 'i')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
