<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;
use Symfony\Component\Uid\Uuid;

#[WithHttpStatus(404)]
final class SelfBillingInvoiceNotFound extends \DomainException
{
    public static function withId(Uuid $id): self
    {
        return new self(sprintf('Self-billing invoice with ID "%s" was not found.', $id));
    }
}
