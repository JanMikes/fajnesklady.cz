<?php

declare(strict_types=1);

namespace App\Enum;

enum HandoverStatus: string
{
    case PENDING = 'pending';
    case TENANT_COMPLETED = 'tenant_completed';
    case LANDLORD_COMPLETED = 'landlord_completed';
    case COMPLETED = 'completed';

    public function isCompleted(): bool
    {
        return self::COMPLETED === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Čeká na vyplnění',
            self::TENANT_COMPLETED => 'Čeká na pronajímatele',
            self::LANDLORD_COMPLETED => 'Čeká na nájemce',
            self::COMPLETED => 'Vyplněno',
        };
    }

    public function isWaitingOn(string $actor): bool
    {
        return match (true) {
            self::PENDING === $this && ('tenant' === $actor || 'landlord' === $actor) => true,
            self::TENANT_COMPLETED === $this && 'landlord' === $actor => true,
            self::LANDLORD_COMPLETED === $this && 'tenant' === $actor => true,
            default => false,
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::PENDING => 'badge-warning',
            self::TENANT_COMPLETED, self::LANDLORD_COMPLETED => 'badge-info',
            self::COMPLETED => 'badge-success',
        };
    }
}
