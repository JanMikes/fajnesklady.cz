<?php

declare(strict_types=1);

namespace App\Value\Address;

final readonly class AddressValidationResult
{
    private const string VERIFIED = 'verified';
    private const string NOT_FOUND = 'not_found';
    private const string SKIPPED = 'skipped';

    private function __construct(public string $state)
    {
    }

    public static function verified(): self
    {
        return new self(self::VERIFIED);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }

    public static function skipped(): self
    {
        return new self(self::SKIPPED);
    }

    public function isVerified(): bool
    {
        return self::VERIFIED === $this->state;
    }

    public function isNotFound(): bool
    {
        return self::NOT_FOUND === $this->state;
    }
}
