<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Service\Identity\ProvideIdentity;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Service for logging audit events.
 *
 * Events to log:
 * - Order: created, reserved, paid, completed, cancelled, expired
 * - Contract: created, signed, terminated, expiring_soon
 * - Storage: reserved, occupied, released, manually_blocked, manually_released
 */
final readonly class AuditLogger
{
    public function __construct(
        private AuditLogRepository $auditLogRepository,
        private ProvideIdentity $identityProvider,
        private Security $security,
        private RequestStack $requestStack,
        private ClockInterface $clock,
    ) {
    }

    // Order events
    public function logOrderCreated(Order $order): void
    {
        $this->log(
            entityType: 'order',
            entityId: $order->id->toRfc4122(),
            eventType: 'created',
            payload: [
                'user_id' => $order->user->id->toRfc4122(),
                'storage_id' => $order->storage->id->toRfc4122(),
                'billing_mode' => $order->billingMode->value,
                'total_price' => $order->firstPaymentPrice,
                'start_date' => $order->startDate->format('Y-m-d'),
                'end_date' => $order->endDate?->format('Y-m-d'),
            ],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );
    }

    /**
     * Spec 088: the customer chose the payment method + frequency for a deferred
     * onboarding, locking billing mode + price + VS.
     */
    public function logOnboardingPaymentChosen(Order $order): void
    {
        $this->log(
            entityType: 'order',
            entityId: $order->id->toRfc4122(),
            eventType: 'payment_chosen',
            payload: [
                'payment_method' => $order->paymentMethod?->value,
                'payment_frequency' => $order->paymentFrequency?->value,
                'billing_mode' => $order->billingMode->value,
                'first_payment_price' => $order->firstPaymentPrice,
            ],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );
    }

    public function logOrderReserved(Order $order): void
    {
        $this->log(
            entityType: 'order',
            entityId: $order->id->toRfc4122(),
            eventType: 'reserved',
            payload: [
                'storage_id' => $order->storage->id->toRfc4122(),
                'storage_number' => $order->storage->number,
            ],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );
    }

    public function logOrderPaid(Order $order): void
    {
        $this->log(
            entityType: 'order',
            entityId: $order->id->toRfc4122(),
            eventType: 'paid',
            payload: [
                'paid_at' => $order->paidAt?->format('Y-m-d H:i:s'),
                'total_price' => $order->firstPaymentPrice,
            ],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );
    }

    /**
     * The order passed through PAID as a state-machine formality — settled
     * externally (admin onboarding, "mark externally paid") or free/prepaid —
     * with no money moving through the platform. Distinct from logOrderPaid()
     * so the timeline never claims a payment was "received".
     */
    public function logOrderPaidExternally(Order $order, ?int $amountInHaler): void
    {
        $this->log(
            entityType: 'order',
            entityId: $order->id->toRfc4122(),
            eventType: 'paid_externally',
            payload: [
                'paid_at' => $order->paidAt?->format('Y-m-d H:i:s'),
                'amount' => $amountInHaler,
                'paid_through' => $order->paidThroughDate?->format('Y-m-d'),
                'payment_method' => $order->paymentMethod?->value,
            ],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );
    }

    public function logOrderCompleted(Order $order): void
    {
        $this->log(
            entityType: 'order',
            entityId: $order->id->toRfc4122(),
            eventType: 'completed',
            payload: [
                'storage_id' => $order->storage->id->toRfc4122(),
            ],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );
    }

    public function logOrderCancelled(Order $order): void
    {
        $this->log(
            entityType: 'order',
            entityId: $order->id->toRfc4122(),
            eventType: 'cancelled',
            payload: [
                'cancelled_at' => $order->cancelledAt?->format('Y-m-d H:i:s'),
            ],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );
    }

    /**
     * A GoPay payment SESSION died (customer abandoned the gateway, or the
     * ~1h session timed out) — the order itself stays payable. System event,
     * no acting user.
     */
    public function logOrderPaymentSessionExpired(Order $order, string $goPayState, string $goPayPaymentId): void
    {
        $this->log(
            entityType: 'order',
            entityId: $order->id->toRfc4122(),
            eventType: 'payment_session_expired',
            payload: [
                'gopay_state' => $goPayState,
                'gopay_payment_id' => $goPayPaymentId,
            ],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );
    }

    /**
     * GoPay reported the order's first payment as REFUNDED. Refunds only ever
     * originate from a manual GoPay-console action — recorded for the admin,
     * never acted on automatically.
     */
    public function logOrderPaymentRefunded(Order $order, string $goPayPaymentId): void
    {
        $this->log(
            entityType: 'order',
            entityId: $order->id->toRfc4122(),
            eventType: 'payment_refunded',
            payload: [
                'gopay_payment_id' => $goPayPaymentId,
            ],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );
    }

    public function logOrderSigned(Order $order): void
    {
        $this->log(
            entityType: 'order',
            entityId: $order->id->toRfc4122(),
            eventType: 'signed',
            payload: [
                'signed_at' => $order->signedAt?->format('Y-m-d H:i:s'),
                'signing_method' => $order->signingMethod?->value,
            ],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );
    }

    public function logOrderExpired(Order $order): void
    {
        $this->log(
            entityType: 'order',
            entityId: $order->id->toRfc4122(),
            eventType: 'expired',
            payload: [
                'expires_at' => $order->expiresAt->format('Y-m-d H:i:s'),
            ],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );
    }

    // Contract events
    public function logContractCreated(Contract $contract): void
    {
        $this->log(
            entityType: 'contract',
            entityId: $contract->id->toRfc4122(),
            eventType: 'created',
            payload: [
                'order_id' => $contract->order->id->toRfc4122(),
                'user_id' => $contract->user->id->toRfc4122(),
                'storage_id' => $contract->storage->id->toRfc4122(),
                'billing_mode' => $contract->billingMode->value,
                'start_date' => $contract->startDate->format('Y-m-d'),
                'end_date' => $contract->endDate->format('Y-m-d'),
            ],
            orderId: $contract->order->id,
            userIdContext: $contract->user->id,
        );
    }

    public function logContractSigned(Contract $contract): void
    {
        $this->log(
            entityType: 'contract',
            entityId: $contract->id->toRfc4122(),
            eventType: 'signed',
            payload: [
                'signed_at' => $contract->signedAt?->format('Y-m-d H:i:s'),
            ],
            orderId: $contract->order->id,
            userIdContext: $contract->user->id,
        );
    }

    public function logContractTerminated(Contract $contract): void
    {
        $this->log(
            entityType: 'contract',
            entityId: $contract->id->toRfc4122(),
            eventType: 'terminated',
            payload: [
                'terminated_at' => $contract->terminatedAt?->format('Y-m-d H:i:s'),
            ],
            orderId: $contract->order->id,
            userIdContext: $contract->user->id,
        );
    }

    public function logContractExpiringSoon(Contract $contract, int $daysRemaining): void
    {
        $this->log(
            entityType: 'contract',
            entityId: $contract->id->toRfc4122(),
            eventType: 'expiring_soon',
            payload: [
                'end_date' => $contract->endDate->format('Y-m-d'),
                'days_remaining' => $daysRemaining,
            ],
            orderId: $contract->order->id,
            userIdContext: $contract->user->id,
        );
    }

    // Storage events
    public function logStorageReserved(Storage $storage, Order $order): void
    {
        $this->log(
            entityType: 'storage',
            entityId: $storage->id->toRfc4122(),
            eventType: 'reserved',
            payload: [
                'order_id' => $order->id->toRfc4122(),
                'status' => $storage->status->value,
            ],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );
    }

    public function logStorageOccupied(Storage $storage, Contract $contract): void
    {
        $this->log(
            entityType: 'storage',
            entityId: $storage->id->toRfc4122(),
            eventType: 'occupied',
            payload: [
                'contract_id' => $contract->id->toRfc4122(),
                'status' => $storage->status->value,
            ],
            orderId: $contract->order->id,
            userIdContext: $contract->user->id,
        );
    }

    public function logStorageReleased(Storage $storage, ?string $reason = null): void
    {
        $this->log(
            entityType: 'storage',
            entityId: $storage->id->toRfc4122(),
            eventType: 'released',
            payload: [
                'status' => $storage->status->value,
                'reason' => $reason,
            ],
        );
    }

    public function logStorageManuallyBlocked(Storage $storage, string $reason): void
    {
        $this->log(
            entityType: 'storage',
            entityId: $storage->id->toRfc4122(),
            eventType: 'manually_blocked',
            payload: [
                'status' => $storage->status->value,
                'reason' => $reason,
            ],
        );
    }

    public function logStorageManuallyReleased(Storage $storage): void
    {
        $this->log(
            entityType: 'storage',
            entityId: $storage->id->toRfc4122(),
            eventType: 'manually_released',
            payload: [
                'status' => $storage->status->value,
            ],
        );
    }

    public function logManualPaymentReceived(\App\Entity\ManualPaymentRequest $request): void
    {
        $this->log(
            entityType: 'manual_payment_request',
            entityId: $request->id->toRfc4122(),
            eventType: 'received',
            payload: [
                'contract_id' => $request->contract->id->toRfc4122(),
                'period_start' => $request->periodStart->format('Y-m-d'),
                'amount' => $request->amount,
                'gopay_payment_id' => $request->goPayPaymentId,
            ],
            orderId: $request->contract->order->id,
            userIdContext: $request->contract->user->id,
        );
    }

    public function logContractProlonged(Contract $contract, \DateTimeImmutable $previousEndDate): void
    {
        $this->log(
            entityType: 'contract',
            entityId: $contract->id->toRfc4122(),
            eventType: 'prolonged',
            payload: [
                'previous_end_date' => $previousEndDate->format('Y-m-d'),
                'new_end_date' => $contract->endDate->format('Y-m-d'),
                'billing_mode' => $contract->billingMode->value,
                'payment_method' => $contract->order->paymentMethod?->value,
            ],
            orderId: $contract->order->id,
            userIdContext: $contract->user->id,
        );
    }

    public function logContractRecurringCancelled(Contract $contract): void
    {
        $this->log(
            entityType: 'contract',
            entityId: $contract->id->toRfc4122(),
            eventType: 'recurring_cancelled',
            payload: [
                'billing_mode' => $contract->billingMode->value,
                'end_date' => $contract->endDate->format('Y-m-d'),
            ],
            orderId: $contract->order->id,
            userIdContext: $contract->user->id,
        );
    }

    public function logBillingModeSetOnOrder(Order $order): void
    {
        $this->log(
            entityType: 'order',
            entityId: $order->id->toRfc4122(),
            eventType: 'billing_mode_set',
            payload: ['billing_mode' => $order->billingMode->value],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );
    }

    // User events
    public function logUserPasswordChangedByAdmin(User $targetUser): void
    {
        $this->log(
            entityType: 'user',
            entityId: $targetUser->id->toRfc4122(),
            eventType: 'password_changed_by_admin',
            payload: ['target_email' => $targetUser->email],
        );
    }

    /**
     * Generic log method for custom events.
     *
     * @param array<string, mixed> $payload
     */
    public function log(
        string $entityType,
        string $entityId,
        string $eventType,
        array $payload = [],
        ?Uuid $orderId = null,
        ?Uuid $userIdContext = null,
    ): void {
        $auditLog = new AuditLog(
            id: $this->identityProvider->next(),
            entityType: $entityType,
            entityId: $entityId,
            eventType: $eventType,
            payload: $payload,
            user: $this->getCurrentUser(),
            ipAddress: $this->getClientIp(),
            createdAt: $this->clock->now(),
            orderId: $orderId,
            userIdContext: $userIdContext,
        );

        $this->auditLogRepository->save($auditLog);
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    private function getClientIp(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request?->getClientIp();
    }
}
