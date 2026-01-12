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
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

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
                'rental_type' => $order->rentalType->value,
                'total_price' => $order->totalPrice,
                'start_date' => $order->startDate->format('Y-m-d'),
                'end_date' => $order->endDate?->format('Y-m-d'),
            ],
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
                'total_price' => $order->totalPrice,
            ],
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
                'rental_type' => $contract->rentalType->value,
                'start_date' => $contract->startDate->format('Y-m-d'),
                'end_date' => $contract->endDate?->format('Y-m-d'),
            ],
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
        );
    }

    public function logContractExpiringSoon(Contract $contract, int $daysRemaining): void
    {
        $this->log(
            entityType: 'contract',
            entityId: $contract->id->toRfc4122(),
            eventType: 'expiring_soon',
            payload: [
                'end_date' => $contract->endDate?->format('Y-m-d'),
                'days_remaining' => $daysRemaining,
            ],
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
    ): void {
        $auditLog = new AuditLog(
            id: $this->identityProvider->next(),
            entityType: $entityType,
            entityId: $entityId,
            eventType: $eventType,
            payload: $payload,
            user: $this->getCurrentUser(),
            ipAddress: $this->getClientIp(),
            createdAt: new \DateTimeImmutable(),
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
