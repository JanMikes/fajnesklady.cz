<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentRefundStatus: string
{
    /** GoPay accepted the refund for asynchronous processing — not money back yet. */
    case PENDING = 'pending';

    /** GoPay confirmed the money went back; the ledger carries the storno row. */
    case SUCCEEDED = 'succeeded';

    /** GoPay declined the refund; nothing was returned. */
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Čeká na potvrzení GoPay',
            self::SUCCEEDED => 'Vráceno',
            self::FAILED => 'Vrácení selhalo',
        };
    }
}
