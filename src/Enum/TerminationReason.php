<?php

declare(strict_types=1);

namespace App\Enum;

enum TerminationReason: string
{
    /** Contract reached its endDate (doba určitá) */
    case EXPIRED = 'expired';

    /** User requested termination with notice period (doba neurčitá) */
    case TENANT_NOTICE = 'tenant_notice';

    /** Recurring payment failed 3 times — tenant owes outstanding charges */
    case PAYMENT_FAILURE = 'payment_failure';

    /** Admin terminated the contract manually */
    case ADMIN = 'admin';
}
