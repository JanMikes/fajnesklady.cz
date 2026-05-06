<?php

declare(strict_types=1);

namespace App\Exception;

use App\Entity\Place;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(409)]
final class StorageCodeRangeExhausted extends \DomainException
{
    public static function forPlace(Place $place): self
    {
        return new self(sprintf(
            'V rozsahu %d–%d na místě "%s" již nejsou volné kódy. Resetujte historii nebo rozšiřte rozsah.',
            $place->storageCodeFrom,
            $place->storageCodeTo,
            $place->name,
        ));
    }
}
