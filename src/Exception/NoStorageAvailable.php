<?php

declare(strict_types=1);

namespace App\Exception;

use App\Entity\StorageType;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(409)]
final class NoStorageAvailable extends \DomainException
{
    public static function forStorageType(
        StorageType $storageType,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
    ): self {
        $period = null === $endDate
            ? sprintf('from %s (unlimited)', $startDate->format('Y-m-d'))
            : sprintf('from %s to %s', $startDate->format('Y-m-d'), $endDate->format('Y-m-d'));

        return new self(sprintf(
            'No storage of type "%s" is available %s.',
            $storageType->name,
            $period,
        ));
    }

    public static function forPeriod(\DateTimeImmutable $startDate, ?\DateTimeImmutable $endDate): self
    {
        $period = null === $endDate
            ? sprintf('from %s (unlimited)', $startDate->format('Y-m-d'))
            : sprintf('from %s to %s', $startDate->format('Y-m-d'), $endDate->format('Y-m-d'));

        return new self(sprintf('No storage is available %s.', $period));
    }
}
