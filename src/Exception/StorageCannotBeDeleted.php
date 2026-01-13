<?php

declare(strict_types=1);

namespace App\Exception;

use App\Entity\Storage;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(409)]
final class StorageCannotBeDeleted extends \DomainException
{
    public static function becauseItIsOccupied(Storage $storage): self
    {
        return new self(sprintf(
            'Nelze smazat sklad "%s" - je obsazený.',
            $storage->number,
        ));
    }

    public static function becauseItIsReserved(Storage $storage): self
    {
        return new self(sprintf(
            'Nelze smazat sklad "%s" - má aktivní rezervaci.',
            $storage->number,
        ));
    }
}
