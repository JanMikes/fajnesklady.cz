<?php

declare(strict_types=1);

namespace App\Value;

enum OverdueSeverity: string
{
    case WARNING = 'warning';
    case ERROR = 'error';
    case CRITICAL = 'critical';

    public function badgeClass(): string
    {
        return match ($this) {
            self::WARNING => 'badge-warning',
            self::ERROR => 'badge-error',
            self::CRITICAL => 'badge-error',
        };
    }

    public function rowClass(): string
    {
        return match ($this) {
            self::WARNING => '',
            self::ERROR => 'bg-red-50',
            self::CRITICAL => 'bg-red-100',
        };
    }

    public function sortRank(): int
    {
        return match ($this) {
            self::CRITICAL => 3,
            self::ERROR => 2,
            self::WARNING => 1,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::WARNING => 'Upozornění',
            self::ERROR => 'Chyba',
            self::CRITICAL => 'Kritické',
        };
    }
}
