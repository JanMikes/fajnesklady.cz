<?php

declare(strict_types=1);

namespace App\Enum;

enum SigningMethod: string
{
    case DRAW = 'draw';
    case TYPED = 'typed';
}
