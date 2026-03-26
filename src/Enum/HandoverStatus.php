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
}
