<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use App\Exception\NoStorageAvailable;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Service\Identity\ProvideIdentity;

/**
 * Service for managing order lifecycle.
 *
 * Handles: create, reserve, payment processing, completion, cancellation, expiration.
 */
final readonly class OrderService
{
    private const int RESERVATION_DAYS = 7;

    public function __construct(
        private ProvideIdentity $identityProvider,
        private OrderRepository $orderRepository,
        private ContractRepository $contractRepository,
        private StorageAssignment $storageAssignment,
        private PriceCalculator $priceCalculator,
        private AuditLogger $auditLogger,
    ) {
    }

    /**
     * Create a new order with automatic storage assignment.
     *
     * @throws NoStorageAvailable When no storage is available for the requested period
     */
    public function createOrder(
        User $user,
        StorageType $storageType,
        RentalType $rentalType,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        ?PaymentFrequency $paymentFrequency = null,
        \DateTimeImmutable $now = new \DateTimeImmutable(),
    ): Order {
        // Assign storage (throws NoStorageAvailable if none available)
        $storage = $this->storageAssignment->assignStorage(
            $storageType,
            $startDate,
            $endDate,
            $user,
        );

        // Calculate price
        $totalPrice = $this->priceCalculator->calculatePrice($storageType, $startDate, $endDate);

        // Create order
        $order = new Order(
            id: $this->identityProvider->next(),
            user: $user,
            storage: $storage,
            rentalType: $rentalType,
            paymentFrequency: $paymentFrequency,
            startDate: $startDate,
            endDate: $endDate,
            totalPrice: $totalPrice,
            expiresAt: $now->modify('+' . self::RESERVATION_DAYS . ' days'),
            createdAt: $now,
        );

        // Reserve the order and storage
        $order->reserve($now);

        $this->orderRepository->save($order);
        $this->auditLogger->logOrderCreated($order);
        $this->auditLogger->logOrderReserved($order);
        $this->auditLogger->logStorageReserved($storage, $order);

        return $order;
    }

    /**
     * Mark order as awaiting payment (user initiated payment).
     */
    public function processPayment(Order $order, \DateTimeImmutable $now = new \DateTimeImmutable()): void
    {
        if (!$order->canBePaid()) {
            throw new \DomainException('Order cannot be paid in its current state.');
        }

        $order->markAwaitingPayment($now);
    }

    /**
     * Confirm payment received.
     */
    public function confirmPayment(Order $order, \DateTimeImmutable $now = new \DateTimeImmutable()): void
    {
        if (!$order->canBePaid()) {
            throw new \DomainException('Order cannot be paid in its current state.');
        }

        $order->markPaid($now);
        $this->auditLogger->logOrderPaid($order);
    }

    /**
     * Complete the order and create a contract.
     */
    public function completeOrder(Order $order, \DateTimeImmutable $now = new \DateTimeImmutable()): Contract
    {
        if (!$order->canBeCompleted()) {
            throw new \DomainException('Order must be paid before it can be completed.');
        }

        // Create contract
        $contract = new Contract(
            id: $this->identityProvider->next(),
            order: $order,
            user: $order->user,
            storage: $order->storage,
            rentalType: $order->rentalType,
            startDate: $order->startDate,
            endDate: $order->endDate,
            createdAt: $now,
        );

        // Complete the order with contract reference
        $order->complete($contract->id, $now);

        $this->contractRepository->save($contract);
        $this->auditLogger->logOrderCompleted($order);
        $this->auditLogger->logContractCreated($contract);
        $this->auditLogger->logStorageOccupied($order->storage, $contract);

        return $contract;
    }

    /**
     * Cancel an order.
     */
    public function cancelOrder(Order $order, \DateTimeImmutable $now = new \DateTimeImmutable()): void
    {
        if (!$order->canBeCancelled()) {
            throw new \DomainException('Order cannot be cancelled in its current state.');
        }

        $order->cancel($now);
        $this->auditLogger->logOrderCancelled($order);
        $this->auditLogger->logStorageReleased($order->storage, 'Order cancelled');
    }

    /**
     * Expire an order (called by scheduled task).
     */
    public function expireOrder(Order $order, \DateTimeImmutable $now = new \DateTimeImmutable()): void
    {
        if (!$order->isExpired($now)) {
            throw new \DomainException('Order has not expired yet.');
        }

        $order->expire($now);
        $this->auditLogger->logOrderExpired($order);
        $this->auditLogger->logStorageReleased($order->storage, 'Order expired');
    }

    /**
     * Expire all orders that have passed their expiration date.
     *
     * @return int Number of orders expired
     */
    public function expireOverdueOrders(\DateTimeImmutable $now = new \DateTimeImmutable()): int
    {
        $expiredOrders = $this->orderRepository->findExpiredOrders($now);
        $count = 0;

        foreach ($expiredOrders as $order) {
            $order->expire($now);
            $this->auditLogger->logOrderExpired($order);
            $this->auditLogger->logStorageReleased($order->storage, 'Order expired');
            ++$count;
        }

        return $count;
    }
}
