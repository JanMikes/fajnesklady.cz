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
use App\Enum\PaymentMethod;
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
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        \DateTimeImmutable $now,
        PaymentFrequency $paymentFrequency = PaymentFrequency::MONTHLY,
        ?Storage $preSelectedStorage = null,
        ?int $monthlyPriceOverride = null,
    ): Order {
        // Spec 076: every new order is fixed-term. The nullable signature only
        // survives so form-layer callers can pass through without asserting.
        if (null === $endDate) {
            throw new \InvalidArgumentException('Every order must have an end date; open-ended rentals no longer exist.');
        }

        // A per-customer price override is a per-billing-period figure: monthly
        // for MONTHLY, yearly for YEARLY, the whole-rental total for a single
        // upfront payment. An upfront rental longer than 12 monthly periods
        // pays in yearly tranches (spec 078) whose math recovers the locked
        // monthly rate as firstPaymentPrice / 12 — an arbitrary total would
        // corrupt every follow-up tranche, so that combination stays blocked.
        // The admin onboarding form rejects it too — this is the
        // defence-in-depth backstop. 0 is the "free" sentinel and is always
        // allowed: a free contract issues no charge at all.
        if (null !== $monthlyPriceOverride && 0 !== $monthlyPriceOverride
            && PaymentFrequency::ONE_TIME === $paymentFrequency
            && PriceCalculator::isUpfrontSplitIntoTranches($startDate, $endDate)) {
            throw new \InvalidArgumentException('Per-customer price override is not supported for upfront rentals longer than 12 monthly periods (yearly tranches).');
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
        // for MONTHLY it's the monthly figure (or a one-shot for short rentals);
        // for a single upfront payment it's the whole-rental total. Admin
        // onboarding may pin a custom per-period price that survives
        // storage-price changes — the override carries the same per-frequency
        // meaning, so it slots straight in.
        $firstPaymentPrice = $monthlyPriceOverride
            ?? $this->priceCalculator->calculateFirstPaymentPrice($storage, $startDate, $endDate, $paymentFrequency);

        // Create order
        $order = new Order(
            id: $this->identityProvider->next(),
            user: $user,
            storage: $storage,
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

        // EXTERNAL settle (or an explicit zero — the free/prepaid formality)
        // is a state-machine transition, not received money: it must never
        // read as "Platba přijata" in the audit trail.
        if (PaymentMethod::EXTERNAL === $order->paymentMethod || 0 === $explicitAmount) {
            $this->auditLogger->logOrderPaidExternally($order, $explicitAmount);
        } else {
            $this->auditLogger->logOrderPaid($order);
        }
    }

    /**
     * Complete the order and create a contract.
     */
    public function completeOrder(Order $order, \DateTimeImmutable $now = new \DateTimeImmutable()): Contract
    {
        if (!$order->canBeCompleted()) {
            throw new \DomainException('Order must be paid before it can be completed.');
        }

        // Spec 076: orders always carry an endDate, so the contract period is
        // exactly the ordered one. Legacy open-ended orders were all completed
        // long before this path can run for them again.
        $endDate = $order->endDate;
        \assert(null !== $endDate);

        $contract = new Contract(
            id: $this->identityProvider->next(),
            order: $order,
            user: $order->user,
            storage: $order->storage,
            startDate: $order->startDate,
            endDate: $endDate,
            createdAt: $now,
        );

        $contract->applyBillingMode($order->billingMode);
        $contract->applyPaymentFrequency($order->paymentFrequency ?? PaymentFrequency::MONTHLY);

        // Carry admin-onboarding billing terms onto the contract so the
        // recurring cron honours individual prices and external prepayment.
        // EXCEPT a positive upfront (ONE_TIME) total: that figure is the
        // whole-rental price already paid via firstPaymentPrice, and the
        // contract's individual amount is a per-billing-cycle figure —
        // prolongation flips ONE_TIME to the monthly MANUAL track (spec 077),
        // which would then bill the entire rental total every month. The free
        // sentinel (0) still transfers so Contract::isFree() holds.
        $isUpfrontTotal = PaymentFrequency::ONE_TIME === $order->paymentFrequency
            && 0 !== $order->individualMonthlyAmount;
        if (null !== $order->individualMonthlyAmount && !$isUpfrontTotal) {
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
