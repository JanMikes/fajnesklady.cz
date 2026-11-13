<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * GoPay did not return the money (declined, or the API call failed). Thrown
 * inside the refund command — its transaction rolls back, so the dispatcher
 * records the failure separately (MESSENGER.md §3).
 */
final class PaymentRefundFailed extends \RuntimeException
{
    private function __construct(
        string $message,
        public readonly int $amount,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function declined(int $amount, string $result): self
    {
        return new self(sprintf('GoPay vrácení odmítl (výsledek %s).', $result), $amount);
    }

    public static function apiError(int $amount, \Throwable $previous): self
    {
        return new self(sprintf('Volání GoPay selhalo: %s', $previous->getMessage()), $amount, $previous);
    }
}
