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
        return new self(sprintf('Samofakturace s ID "%s" nebyla nalezena.', $id));
    }
}
