<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;
use Symfony\Component\Uid\Uuid;

#[WithHttpStatus(409)]
final class StorageCannotBeReassigned extends \RuntimeException
{
    public static function hasOrdersOrContracts(Uuid $storageId): self
    {
        return new self(sprintf(
            'Sklad "%s" nelze přeřadit, protože má existující objednávky nebo smlouvy.',
            $storageId->toRfc4122()
        ));
    }
}
