<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
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
     * Create a new order with automatic or pre-selected storage assignment.
     *
     * @throws NoStorageAvailable        When no storage is available for the requested period
     * @throws \InvalidArgumentException When pre-selected storage is invalid
     */
    public function createOrder(
        User $user,
        StorageType $storageType,
        Place $place,
        RentalType $rentalType,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        \DateTimeImmutable $now,
        ?PaymentFrequency $paymentFrequency = null,
        ?Storage $preSelectedStorage = null,
        ?int $monthlyPriceOverride = null,
    ): Order {
        if (null !== $preSelectedStorage) {
            // Validate pre-selected storage
            if (!$preSelectedStorage->storageType->id->equals($storageType->id)) {
                throw new \InvalidArgumentException('Pre-selected storage does not belong to the specified storage type.');
            }
            if (!$preSelectedStorage->place->id->equals($place->id)) {
                throw new \InvalidArgumentException('Pre-selected storage does not belong to the specified place.');
            }
            if (!$preSelectedStorage->isAvailable()) {
                throw NoStorageAvailable::forStorageType($storageType, $startDate, $endDate);
            }
            $storage = $preSelectedStorage;
        } else {
            // Assign storage automatically (throws NoStorageAvailable if none available)
            $storage = $this->storageAssignment->assignStorage(
                $storageType,
                $place,
                $startDate,
                $endDate,
                $user,
            );
        }

        // Calculate first payment price (monthly recurring or full for short rentals)
        // Admin onboarding may pin a custom monthly that survives storage-price changes.
        $firstPaymentPrice = $monthlyPriceOverride
            ?? $this->priceCalculator->calculateFirstPaymentPrice($storage, $startDate, $endDate);

        // Create order
        $order = new Order(
            id: $this->identityProvider->next(),
            user: $user,
            storage: $storage,
            rentalType: $rentalType,
            paymentFrequency: $paymentFrequency,
            startDate: $startDate,
            endDate: $endDate,
            firstPaymentPrice: $firstPaymentPrice,
            expiresAt: $now->modify('+'.$place->orderExpirationDays.' days'),
            createdAt: $now,
        );

        $this->orderRepository->save($order);
        $this->auditLogger->logOrderCreated($order);

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
     *
     * @param ?int $explicitAmount Halere amount that should be recorded as the
     *                             initial Payment when it differs from the
     *                             order's locked-in monthly. Used by the admin
     *                             migrate flow (lump-sum prepayment); null
     *                             everywhere else.
     */
    public function confirmPayment(Order $order, \DateTimeImmutable $now = new \DateTimeImmutable(), ?int $explicitAmount = null): void
    {
        if (!$order->canBePaid()) {
            throw new \DomainException('Order cannot be paid in its current state.');
        }

        $order->markPaid($now, $explicitAmount);
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

        // Carry admin-onboarding billing terms onto the contract so the
        // recurring cron honours individual prices and external prepayment.
        if (null !== $order->individualMonthlyAmount) {
            $contract->applyIndividualMonthlyAmount($order->individualMonthlyAmount);
        }
        if (null !== $order->paidThroughDate) {
            $contract->markExternallyPrepaid($order->paidThroughDate);
        }

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

        $storageWasReserved = $order->storage->isReserved();
        $order->cancel($now);
        $this->auditLogger->logOrderCancelled($order);
        if ($storageWasReserved) {
            $this->auditLogger->logStorageReleased($order->storage, 'Order cancelled');
        }
    }

    /**
     * Expire an order (called by scheduled task).
     */
    public function expireOrder(Order $order, \DateTimeImmutable $now = new \DateTimeImmutable()): void
    {
        if (!$order->isExpired($now)) {
            throw new \DomainException('Order has not expired yet.');
        }

        $storageWasReserved = $order->storage->isReserved();
        $order->expire($now);
        $this->auditLogger->logOrderExpired($order);
        if ($storageWasReserved) {
            $this->auditLogger->logStorageReleased($order->storage, 'Order expired');
        }
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
            $storageWasReserved = $order->storage->isReserved();
            $order->expire($now);
            $this->auditLogger->logOrderExpired($order);
            if ($storageWasReserved) {
                $this->auditLogger->logStorageReleased($order->storage, 'Order expired');
            }
            ++$count;
        }

        return $count;
    }
}
