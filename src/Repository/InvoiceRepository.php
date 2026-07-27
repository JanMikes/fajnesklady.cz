<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Fine;
use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\User;
use App\Exception\InvoiceNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class InvoiceRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Invoice $invoice): void
    {
        $this->entityManager->persist($invoice);
    }

    public function find(Uuid $id): ?Invoice
    {
        return $this->entityManager->find(Invoice::class, $id);
    }

    public function get(Uuid $id): Invoice
    {
        return $this->find($id) ?? throw InvoiceNotFound::withId($id);
    }

    public function findByOrder(Order $order): ?Invoice
    {
        return $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.order = :order')
            ->setParameter('order', $order)
            ->orderBy('i.issuedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Invoice[]
     */
    public function findAllByOrder(Order $order): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.order = :order')
            ->setParameter('order', $order)
            ->orderBy('i.issuedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<Fine> $fines
     *
     * @return array<string, Invoice> keyed by fine id (RFC 4122)
     */
    public function findByFines(array $fines): array
    {
        if ([] === $fines) {
            return [];
        }

        /** @var list<Invoice> $invoices */
        $invoices = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.fine IN (:fines)')
            ->setParameter('fines', $fines)
            ->getQuery()
            ->getResult();

        $byFineId = [];
        foreach ($invoices as $invoice) {
            if (null !== $invoice->fine) {
                $byFineId[$invoice->fine->id->toRfc4122()] = $invoice;
            }
        }

        return $byFineId;
    }

    /**
     * Invoices whose number matches a bank-transfer variable symbol. Customers
     * sometimes pay from an (already settled) invoice PDF using its number as
     * the VS — and banks strip the leading zeros of our zero-padded numbers
     * (0012202607 arrives as 12202607), so both sides compare zero-stripped.
     *
     * @return list<Invoice>
     */
    public function findByNumberMatchingVariableSymbol(string $variableSymbol): array
    {
        $stripped = ltrim($variableSymbol, '0');

        if ('' === $stripped) {
            return [];
        }

        return $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where("TRIM(LEADING '0' FROM i.invoiceNumber) = :stripped")
            ->setParameter('stripped', $stripped)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Invoice[]
     */
    public function findByUser(User $user): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.user = :user')
            ->setParameter('user', $user)
            ->orderBy('i.issuedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
