<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Why we're sending a 7-business-day advance notice for a recurring charge
 * (Podmínky opakovaných plateb čl. V).
 */
enum AdvanceNoticeReason: string
{
    case SIX_MONTH_GAP = 'six_month_gap';
    case PARAMETER_CHANGE = 'parameter_change';

    public function label(): string
    {
        return match ($this) {
            self::SIX_MONTH_GAP => 'Více než 6 měsíců od poslední platby',
            self::PARAMETER_CHANGE => 'Změna parametrů opakované platby',
        };
    }
}
