<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(422)]
final class InvalidStorageCode extends \DomainException
{
    public static function wrongLength(int $expected): self
    {
        return new self(sprintf('Kód musí mít přesně %d číslic.', $expected));
    }

    public static function notNumeric(): self
    {
        return new self('Kód musí obsahovat pouze číslice.');
    }

    public static function outOfRange(int $from, int $to): self
    {
        return new self(sprintf('Kód musí být v rozsahu %d až %d.', $from, $to));
    }

    public static function alreadyUsedByAnotherStorage(string $code): self
    {
        return new self(sprintf('Kód "%s" je již přiřazen jinému skladu.', $code));
    }

    public static function inHistory(string $code): self
    {
        return new self(sprintf('Kód "%s" byl v minulosti použit. Vyberte jiný.', $code));
    }

    public static function excluded(string $code): self
    {
        return new self(sprintf('Kód "%s" je vyloučen (systémový kód) a nelze jej přiřadit.', $code));
    }
}
