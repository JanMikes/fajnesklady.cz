<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentMethod: string
{
    case GOPAY = 'gopay';
    case EXTERNAL = 'external';
}
