<?php

declare(strict_types=1);

namespace App\Query;

use Symfony\Component\Uid\Uuid;

/**
 * @implements QueryMessage<GetLandlordRevenueChartResult>
 */
final readonly class GetLandlordRevenueChart implements QueryMessage
{
    public function __construct(
        public Uuid $landlordId,
        public int $months = 12,
    ) {
    }
}
