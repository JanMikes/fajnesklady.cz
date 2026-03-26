<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;
use Symfony\Component\Uid\Uuid;

#[WithHttpStatus(404)]
final class HandoverProtocolNotFound extends \RuntimeException
{
    public static function withId(Uuid $id): self
    {
        return new self(sprintf('Předávací protokol s ID "%s" nebyl nalezen.', $id->toRfc4122()));
    }

    public static function forContract(Uuid $contractId): self
    {
        return new self(sprintf('Předávací protokol pro smlouvu "%s" nebyl nalezen.', $contractId->toRfc4122()));
    }
}
