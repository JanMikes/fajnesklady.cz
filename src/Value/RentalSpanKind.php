<?php

declare(strict_types=1);

namespace App\Value;

enum RentalSpanKind: string
{
    case CONTRACT = 'contract';
    case ORDER = 'order';
    case BLOCK = 'block';
}
