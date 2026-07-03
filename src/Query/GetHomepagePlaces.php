<?php

declare(strict_types=1);

namespace App\Query;

/**
 * Public homepage places overview: every active place with its publicly
 * orderable storage types, availability for the next 30 days (starting
 * tomorrow), and the lowest advertised price/area. Sorted by availability
 * ratio DESC, storage count DESC, name ASC.
 *
 * @implements QueryMessage<GetHomepagePlacesResult>
 */
final readonly class GetHomepagePlaces implements QueryMessage
{
}
