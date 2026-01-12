<?php

declare(strict_types=1);

namespace App\Enum;

enum UserRole: string
{
    case USER = 'ROLE_USER';
    case LANDLORD = 'ROLE_LANDLORD';
    case ADMIN = 'ROLE_ADMIN';

    /**
     * Get all role values as an array of strings.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }

    /**
     * Check if a role value is valid.
     */
    public static function isValid(string $value): bool
    {
        return null !== self::tryFrom($value);
    }
}
