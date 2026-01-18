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
            ? sprintf('od %s (neomezeně)', $startDate->format('d.m.Y'))
            : sprintf('od %s do %s', $startDate->format('d.m.Y'), $endDate->format('d.m.Y'));

        return new self(sprintf(
            'Žádný sklad typu "%s" není dostupný %s.',
            $storageType->name,
            $period,
        ));
    }

    public static function forPeriod(\DateTimeImmutable $startDate, ?\DateTimeImmutable $endDate): self
    {
        $period = null === $endDate
            ? sprintf('od %s (neomezeně)', $startDate->format('d.m.Y'))
            : sprintf('od %s do %s', $startDate->format('d.m.Y'), $endDate->format('d.m.Y'));

        return new self(sprintf('Žádný sklad není dostupný %s.', $period));
    }
}
