<?php

declare(strict_types=1);

namespace App\Repository;

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
