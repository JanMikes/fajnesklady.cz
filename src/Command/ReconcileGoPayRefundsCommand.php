<?php

declare(strict_types=1);

namespace App\Command;

/**
 * Bring the app's refund records for one GoPay payment in line with GoPay's
 * refund history (pending confirmations + refunds issued in the GoPay console).
 */
final readonly class ReconcileGoPayRefundsCommand
{
    public function __construct(
        public string $goPayPaymentId,
    ) {
    }
}
