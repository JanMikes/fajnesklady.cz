<?php

declare(strict_types=1);

namespace App\Value;

final readonly class UserListCriteria
{
    /**
     * Maps a whitelisted sort key to the SQL column / alias it sorts by.
     * Anything outside this map is rejected and falls back to 'created'.
     *
     * @var array<string, string>
     */
    public const array SORT_COLUMNS = [
        'name' => 'u.first_name, u.last_name',
        'email' => 'u.email',
        'created' => 'u.created_at',
        'contracts' => 'active_count',
        'mrr' => 'mrr',
        'yrr' => 'yrr',
    ];

    public const string DEFAULT_SORT = 'created';
    public const string DEFAULT_DIRECTION = 'desc';

    public function __construct(
        public ?string $search,
        public ?string $filter,
        public string $sortColumn,
        public string $sortDirection,
        public int $page,
        public int $limit = 20,
    ) {
    }

    /**
     * Build a validated criteria from raw query parameters, whitelisting the
     * sort column / direction and normalising the search + filter values.
     */
    public static function fromRequest(
        ?string $search,
        ?string $filter,
        ?string $sort,
        ?string $direction,
        int $page,
        int $limit = 20,
    ): self {
        $trimmedSearch = null !== $search ? trim($search) : '';

        $normalisedFilter = match ($filter) {
            'overdue', 'onboarded', 'active', 'inactive', 'unverified' => $filter,
            default => null,
        };

        $sortColumn = (null !== $sort && isset(self::SORT_COLUMNS[$sort])) ? $sort : self::DEFAULT_SORT;
        $sortDirection = 'asc' === $direction ? 'asc' : self::DEFAULT_DIRECTION;

        return new self(
            search: '' === $trimmedSearch ? null : $trimmedSearch,
            filter: $normalisedFilter,
            sortColumn: $sortColumn,
            sortDirection: $sortDirection,
            page: max(1, $page),
            limit: $limit,
        );
    }

    public function sortExpression(): string
    {
        return self::SORT_COLUMNS[$this->sortColumn];
    }
}
