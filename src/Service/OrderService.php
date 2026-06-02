<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\ExpectedDuration;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use App\Exception\NoStorageAvailable;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Repository\StorageRepository;
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
        private StorageAvailabilityChecker $availabilityChecker,
        private StorageRepository $storageRepository,
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
        PaymentFrequency $paymentFrequency = PaymentFrequency::MONTHLY,
        ?Storage $preSelectedStorage = null,
        ?int $monthlyPriceOverride = null,
        ?ExpectedDuration $expectedDuration = null,
    ): Order {
        // A per-customer monthly price override is a *monthly* figure and is not
        // supported for yearly billing: accepting a positive override would
        // charge a single month as the "first payment" and then silently ignore
        // it on every recurring yearly charge (Contract::getEffectiveRecurringAmount
        // reads the storage yearly rate). The admin onboarding form blocks the
        // 'custom' price mode for YEARLY — this is the defence-in-depth backstop.
        // 0 is the "free" sentinel and is explicitly allowed: a free yearly
        // contract issues no recurring charge at all, so there is nothing to drop.
        if (null !== $monthlyPriceOverride && 0 !== $monthlyPriceOverride && PaymentFrequency::YEARLY === $paymentFrequency) {
            throw new \InvalidArgumentException('Per-customer monthly price override is not supported for yearly billing.');
        }

        if (null !== $preSelectedStorage) {
            // Validate pre-selected storage
            if (!$preSelectedStorage->storageType->id->equals($storageType->id)) {
                throw new \InvalidArgumentException('Pre-selected storage does not belong to the specified storage type.');
            }
            if (!$preSelectedStorage->place->id->equals($place->id)) {
                throw new \InvalidArgumentException('Pre-selected storage does not belong to the specified place.');
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

        // Serialise concurrent bookings of THIS storage. There is no DB-level
        // exclusion constraint on bookings, so without a lock two requests can
        // both pass the availability check and both persist a blocking order on
        // the same unit. Take a row-level write lock, then (re-)verify
        // availability UNDER the lock and before persisting. The order is saved
        // below as status CREATED — which findOverlappingByStorage treats as
        // blocking — within this same command-bus transaction, so a competing
        // booking blocks on the lock until we commit and then sees our order.
        // The date-window check (not entity status) is deliberate: Storage.status
        // drifts (e.g. OCCUPIED from a future booking) and would reject free windows.
        $this->storageRepository->lockForBooking($storage);

        if (!$this->availabilityChecker->isAvailable($storage, $startDate, $endDate)) {
            throw NoStorageAvailable::forStorageType($storageType, $startDate, $endDate);
        }

        // Calculate first payment price. For YEARLY this is the yearly amount;
        // for MONTHLY it's the monthly figure (or a one-shot for short rentals).
        // Admin onboarding may pin a custom monthly that survives storage-price
        // changes — yearly contracts don't support per-customer overrides today.
        $firstPaymentPrice = $monthlyPriceOverride
            ?? $this->priceCalculator->calculateFirstPaymentPrice($storage, $startDate, $endDate, $paymentFrequency);

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

        // Snapshot the Place's manual-billing schedule onto the Order so any
        // future MANUAL_RECURRING contract derived from it keeps the cadence
        // it was placed under (operator edits to Place's offsets later must
        // NOT retroactively shift schedules of running rentals).
        $order->setManualBillingSchedule(
            $place->manualBillingOffsetInitial,
            $place->manualBillingOffsetReminder,
            $place->manualBillingOffsetFinalDue,
            $place->manualBillingOffsetOverdueFirst,
            $place->manualBillingOffsetOverdueFinal,
        );

        // Drop a non-null expectedDuration on the floor when the rental is LIMITED —
        // the field only applies to UNLIMITED orders and must never poison the column.
        if (RentalType::UNLIMITED === $rentalType) {
            $order->setExpectedDuration($expectedDuration);
        }

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

        // VOP §IV: every contract is "doba určitá". UNLIMITED orders have null
        // endDate (customer intent = indefinite), but the contract gets a concrete
        // period that advances on each successful recurring charge.
        $paymentFrequency = $order->paymentFrequency ?? PaymentFrequency::MONTHLY;
        $cadenceStep = PaymentFrequency::YEARLY === $paymentFrequency ? '+1 year' : '+1 month';
        $endDate = $order->endDate ?? $order->startDate->modify($cadenceStep);

        $contract = new Contract(
            id: $this->identityProvider->next(),
            order: $order,
            user: $order->user,
            storage: $order->storage,
            rentalType: $order->rentalType,
            startDate: $order->startDate,
            endDate: $endDate,
            createdAt: $now,
        );

        $contract->applyBillingMode($order->billingMode);
        $contract->applyPaymentFrequency($order->paymentFrequency ?? PaymentFrequency::MONTHLY);

        // Carry admin-onboarding billing terms onto the contract so the
        // recurring cron honours individual prices and external prepayment.
        if (null !== $order->individualMonthlyAmount) {
            $contract->applyIndividualMonthlyAmount(
                amount: $order->individualMonthlyAmount,
                changedBy: $order->createdByAdmin,
                reason: 'Initial value (admin onboarding)',
                now: $now,
            );
        }
        if (null !== $order->paidThroughDate) {
            $contract->markExternallyPrepaid($order->paidThroughDate);
        }

        if (null !== $order->uploadedContractDocumentPath) {
            $contract->attachDocument($order->uploadedContractDocumentPath, $now);
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
