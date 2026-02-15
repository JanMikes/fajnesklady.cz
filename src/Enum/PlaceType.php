<?php

declare(strict_types=1);

namespace App\Enum;

enum PlaceType: string
{
    case FAJNE_SKLADY = 'fajne_sklady';
    case SAMOSTATNY_SKLAD = 'samostatny_sklad';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::FAJNE_SKLADY => 'Fajné Sklady',
            self::SAMOSTATNY_SKLAD => 'Samostatný sklad',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::FAJNE_SKLADY => '#d23233',
            self::SAMOSTATNY_SKLAD => '#9ca3af',
        };
    }
}
