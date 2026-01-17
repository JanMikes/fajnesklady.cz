<?php

declare(strict_types=1);

namespace App\Query;

use Symfony\Component\Uid\Uuid;

/**
 * @implements QueryMessage<GetLandlordDashboardStatsResult>
 */
final readonly class GetLandlordDashboardStats implements QueryMessage
{
    public function __construct(
        public Uuid $landlordId,
    ) {
    }
}
