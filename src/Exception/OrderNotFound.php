<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;
use Symfony\Component\Uid\Uuid;

#[WithHttpStatus(404)]
final class OrderNotFound extends \RuntimeException
{
    public static function withId(Uuid $id): self
    {
        return new self(sprintf('Order with ID "%s" not found.', $id->toRfc4122()));
    }
}
