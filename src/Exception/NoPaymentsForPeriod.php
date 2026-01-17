<?php

declare(strict_types=1);

namespace App\Exception;

use App\Entity\User;

final class NoPaymentsForPeriod extends \DomainException
{
    public function __construct(User $landlord, int $year, int $month)
    {
        parent::__construct(sprintf(
            'No unbilled payments found for landlord "%s" in period %02d/%d.',
            $landlord->fullName,
            $month,
            $year,
        ));
    }
}
