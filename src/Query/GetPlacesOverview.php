<?php

declare(strict_types=1);

namespace App\Query;

/**
 * Places overview for the public homepage and the portal browse page
 * (/portal/pobocky): every active place with its publicly orderable storage
 * types, availability for the next 30 days (starting tomorrow), and the
 * lowest advertised price/area. Sorted by availability ratio DESC, storage
 * count DESC, name ASC — callers wanting a different order re-sort the rows.
 *
 * @implements QueryMessage<GetPlacesOverviewResult>
 */
final readonly class GetPlacesOverview implements QueryMessage
{
}
