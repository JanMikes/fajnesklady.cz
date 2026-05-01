<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(503)]
final class AresUnavailable extends \RuntimeException
{
    public static function withStatus(int $status): self
    {
        return new self(sprintf('ARES returned unexpected status %d.', $status));
    }

    public static function wrap(\Throwable $previous): self
    {
        return new self('ARES lookup failed.', 0, $previous);
    }
}
