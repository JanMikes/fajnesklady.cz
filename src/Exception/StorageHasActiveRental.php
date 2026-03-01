<?php

declare(strict_types=1);

namespace App\Exception;

use App\Entity\Storage;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(409)]
final class StorageHasActiveRental extends \DomainException
{
    public static function cannotBlock(Storage $storage): self
    {
        return new self(sprintf(
            'Nelze zablokovat sklad "%s" – v požadovaném období má aktivní pronájem nebo rezervaci.',
            $storage->number,
        ));
    }
}
