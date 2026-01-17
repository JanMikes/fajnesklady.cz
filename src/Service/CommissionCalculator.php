<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Storage;

final readonly class CommissionCalculator
{
    private const string DEFAULT_RATE = '0.90';

    /**
     * Get commission rate for a storage using priority:
     * 1. Storage-specific rate (highest priority)
     * 2. Landlord-specific rate (fallback)
     * 3. Default 90% (system fallback).
     */
    public function getRate(Storage $storage): string
    {
        // Priority 1: Storage-specific rate
        if (null !== $storage->commissionRate) {
            return $storage->commissionRate;
        }

        // Priority 2: Landlord-specific rate
        if (null !== $storage->owner?->commissionRate) {
            return $storage->owner->commissionRate;
        }

        // Priority 3: Default 90%
        return self::DEFAULT_RATE;
    }

    /**
     * Calculate net amount (amount to pay to landlord) based on gross amount and rate.
     *
     * @param int    $grossAmount Gross amount in haléře
     * @param string $rate        Commission rate as decimal string (e.g., "0.90" for 90%)
     *
     * @return int Net amount in haléře
     */
    public function calculateNetAmount(int $grossAmount, string $rate): int
    {
        return (int) round($grossAmount * (float) $rate);
    }

    /**
     * Get the default commission rate.
     */
    public function getDefaultRate(): string
    {
        return self::DEFAULT_RATE;
    }
}
