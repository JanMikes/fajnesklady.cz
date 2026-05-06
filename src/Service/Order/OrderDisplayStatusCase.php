<?php

declare(strict_types=1);

namespace App\Service\Order;

enum OrderDisplayStatusCase: string
{
    case AWAITING_PAYMENT = 'awaiting_payment';
    case PROCESSING = 'processing';
    case ACTIVE = 'active';
    case ACTIVE_BILLING_FAILED = 'active_billing_failed';
    case ACTIVE_TERMINATION_PENDING = 'active_termination_pending';
    case COMPLETED_ENDED = 'completed_ended';
    case SUSPENDED_PAYMENT_FAILURE = 'suspended_payment_failure';
    case TERMINATED = 'terminated';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
}
