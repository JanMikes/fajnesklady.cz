<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contract;
use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\PaymentRefund;
use App\Enum\PaymentRefundStatus;
use App\Enum\RefundMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class PaymentRefundRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(PaymentRefund $refund): void
    {
        $this->entityManager->persist($refund);
    }

    public function find(Uuid $id): ?PaymentRefund
    {
        return $this->entityManager->find(PaymentRefund::class, $id);
    }

    /**
     * Every refund of the payment, oldest first.
     *
     * @return list<PaymentRefund>
     */
    public function findByPayment(Payment $payment): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(PaymentRefund::class, 'r')
            ->where('r.payment = :payment')
            ->setParameter('payment', $payment)
            ->orderBy('r.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Refunds across an order's whole lifecycle (the first payment is linked to
     * the order, recurring ones to the contract — PaymentRefund carries both).
     *
     * @return list<PaymentRefund>
     */
    public function findByOrder(Order $order): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(PaymentRefund::class, 'r')
            ->where('r.order = :order')
            ->setParameter('order', $order)
            ->orderBy('r.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByCreditNote(Invoice $creditNote): ?PaymentRefund
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(PaymentRefund::class, 'r')
            ->where('r.creditNote = :creditNote')
            ->setParameter('creditNote', $creditNote)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function hasActiveRefundForContract(Contract $contract): bool
    {
        return null !== $this->entityManager->createQueryBuilder()
            ->select('1')
            ->from(PaymentRefund::class, 'r')
            ->where('r.contract = :contract')
            ->andWhere('r.status <> :failed')
            ->andWhere('r.mode <> :external')
            ->setParameter('contract', $contract)
            ->setParameter('failed', PaymentRefundStatus::FAILED)
            ->setParameter('external', RefundMode::EXTERNAL)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<PaymentRefund>
     */
    public function findPendingRequestedBefore(\DateTimeImmutable $before): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(PaymentRefund::class, 'r')
            ->where('r.status = :pending')
            ->andWhere('r.requestedAt < :before')
            ->setParameter('pending', PaymentRefundStatus::PENDING)
            ->setParameter('before', $before)
            ->orderBy('r.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
