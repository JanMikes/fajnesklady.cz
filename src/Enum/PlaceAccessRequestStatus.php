<?php

declare(strict_types=1);

namespace App\Enum;

enum PlaceAccessRequestStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case DENIED = 'denied';
}
