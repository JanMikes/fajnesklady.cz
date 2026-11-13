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
     * Rent invoices (not fines, not credit notes) of the order with exactly
     * this amount, issued within the window — the candidates for "the invoice
     * that documents this payment". Invoice has no Payment FK; issuance runs
     * right after the money arrives (or via the 5-minute backstop cron), so
     * order + amount + time is a reliable key when it yields exactly one row.
     *
     * @return list<Invoice>
     */
    public function findRentInvoicesForAmountIssuedBetween(Order $order, int $amount, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.order = :order')
            ->andWhere('i.amount = :amount')
            ->andWhere('i.fine IS NULL')
            ->andWhere('i.correctionOf IS NULL')
            ->andWhere('i.issuedAt BETWEEN :from AND :to')
            ->setParameter('order', $order)
            ->setParameter('amount', $amount)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('i.issuedAt', 'ASC')
            ->getQuery()
            ->getResult();
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

    /**
     * @return list<Invoice>
     */
    public function findAllOrderedByIssuedAt(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->orderBy('i.issuedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Invoices Fakturoid has not been given a GoPay payment id for — the
     * spec-093 backfill queue, oldest first.
     *
     * @return list<Invoice>
     */
    public function findWithoutGoPayPaymentId(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.goPayPaymentId IS NULL')
            ->orderBy('i.issuedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Whether a non-credit-note invoice already documents this GoPay payment
     * — one payment is invoiced once; only its credit notes share the id.
     */
    public function existsInvoiceForGoPayPaymentId(string $goPayPaymentId): bool
    {
        return null !== $this->entityManager->createQueryBuilder()
            ->select('i.id')
            ->from(Invoice::class, 'i')
            ->where('i.goPayPaymentId = :goPayPaymentId')
            ->andWhere('i.correctionOf IS NULL')
            ->setParameter('goPayPaymentId', $goPayPaymentId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
