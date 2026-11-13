<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The requested refund cannot be made — the message is shown to the admin
 * verbatim (Czech), nothing was sent to GoPay.
 */
final class RefundNotPossible extends \DomainException
{
}
