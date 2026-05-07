# 026 — Admin customer list: per-row rentals + MRR + active/inactive filters

**Status:** done
**Type:** feature (admin UX + read-only aggregate query)
**Scope:** small (~7 files: 1 controller, 1 template, repo additions on `ContractRepository` + `UserRepository`, 2 integration tests)
**Depends on:** none (does not block on spec 025; both edit the same template + controller — see "Coexistence with spec 025" below)

## Problem

`/portal/users` (admin-only) is the operational customer list, but it carries no commercial signal beyond name/email/role/verified/dlužník. To answer "how active is this customer, how much do they pay me" an admin has to click into each user detail one by one. There is no way to slice the list to "customers who currently rent something" vs "customers who don't" — the two cohorts the admin actually treats differently in support and outreach.

## Goal

The same `/portal/users` table, on the same page-load, surfaces **per row**:

1. How many storages the customer **currently rents** (active contracts) and how many they've **ever rented** (historic total).
2. The customer's **monthly recurring revenue (MRR)** in CZK.

…plus two new filter chips alongside the existing `Vše` / `Pouze dlužníci`:

3. `S aktivními smlouvami ({N})` — users with ≥1 active contract.
4. `Bez aktivních smluv ({N})` — users with zero active contracts (churned, or never rented; includes non-tenant users like landlords/admins, by design — see "Out of scope").

The aggregate columns are display-only (no sorting in v1, mirroring every other column on this page).

## Context (current state)

### Surfaces being edited

- `src/Controller/Portal/UserListController.php` — admin user list controller. Currently handles `filter=overdue`, looks up `debtorIdSet` for the visible page via `OverdueChecker::filterOverdueUserIds()`, and renders `templates/portal/user/list.html.twig` with `users`, `totalUsers`, `overdueUserCount`, `debtorIdSet`.
- `templates/portal/user/list.html.twig` — DaisyUI table with columns: Jméno · Email · Role · Stav · Vytvořeno · Akce. Filter chip strip at lines 10–15.

### Per-page lookup pattern (mirror this)

`UserListController::__invoke()` already follows the right pattern for "compute extra column data for the visible page only":

```php
$pageUserIds = array_map(static fn (User $u) => $u->id, $users);
$debtorIdSet = array_flip($this->overdueChecker->filterOverdueUserIds($now, $pageUserIds));
```

The new stats lookup mirrors this: take the 20 user UUIDs of the current page, run **one** SQL aggregate, return a per-user-id map. No N+1.

### Source of truth for the numbers

- `Contract` entity (`src/Entity/Contract.php`) — `terminatedAt`, `endDate`, `startDate`, `user`, `order`. Active = `terminatedAt IS NULL AND (endDate IS NULL OR endDate >= now)`. This is the same predicate already used in `ContractRepository::findActiveByUser()`.
- `Order.firstPaymentPrice` (`src/Entity/Order.php:114`) — locked-in monthly for recurring orders, lump-sum total for short LIMITED (<28 days) orders. We must include it in MRR **only** when the contract is recurring; otherwise we'd report a 3-week one-shot rental as monthly revenue.
- "Recurring shape" in DQL: `c.endDate IS NULL OR (c.endDate - c.startDate) >= 28`. This captures every recurring contract (UNLIMITED + LIMITED ≥28 days), excludes short LIMITED. Already matches `PriceCalculator::WEEKLY_THRESHOLD_DAYS` (28).
- Free contracts (post-spec-025) have `Order.firstPaymentPrice = 0` so they naturally contribute 0 — no special case needed.
- External-prepaid contracts (post-spec-025) have an `endDate` and a recurring shape — they are included in MRR via the same predicate. Per Q2 of the discovery: include them, on the basis that the locked-in monthly is reliably stored on `Order.firstPaymentPrice` for both pre- and post-025 worlds.

### Repo precedents

- `ContractRepository::findOverdueUserIds()` — RFC-4122 string lookup keyed by user. Same return shape as the new `findUserIdsWithActiveContracts()`.
- `ContractRepository::sumExpectedRecurringByLandlord()` (`:530`) — landlord MRR via `SUM(o.firstPaymentPrice)` over active recurring contracts joined to orders. The new per-user MRR uses the same approach but groups by `c.user_id` and broadens the recurring predicate (see "Source of truth" above).
- `UserRepository::findOverduePaginated()` / `countOverdueUsers()` (`:179`/`:196`) — pagination filtered by an in-clause subquery. The new `findWithActiveContractsPaginated()` / `findWithoutActiveContractsPaginated()` use the identical pattern with `IN` / `NOT IN`.

### Coexistence with spec 025

Spec 025 (in-progress) edits the same controller + template to add an `Onboardovaný` chip. The two specs touch overlapping but non-conflicting surfaces:

- 025 adds chip `Pouze onboardovaní` + `onboardedIdSet` and a "Onboardovaný" badge in the Stav column.
- 026 adds chips `S aktivními smlouvami` + `Bez aktivních smluv`, two new columns (Smlouvy, MRR), and `customerStats` per-page lookup.

Implementation order doesn't matter — whichever lands second rebases the other's filter-chip array and adds its own. Both follow the established `filter` query param + `array_flip(...)` pattern; conflict surface is only the chip strip and the controller's filter-branch.

## Requirements

### 1. `ContractRepository::loadCustomerStatsByUserIds()`

Add to `src/Repository/ContractRepository.php`. **Single** DBAL native query — keep it tight, this is hot-path on every page render.

```php
/**
 * @param Uuid[] $userIds
 *
 * @return array<string, array{activeCount: int, totalCount: int, mrrInHaler: int}>
 *         Keyed by RFC-4122 user UUID string. Users with no contracts are absent
 *         from the result; the controller defaults missing entries to zeros.
 */
public function loadCustomerStatsByUserIds(array $userIds, \DateTimeImmutable $now): array
{
    if ([] === $userIds) {
        return [];
    }

    $idStrings = array_map(static fn (Uuid $id): string => (string) $id, $userIds);

    $rows = $this->entityManager->getConnection()->executeQuery(
        <<<'SQL'
            SELECT
                c.user_id::text AS user_id,
                COUNT(*) AS total_count,
                COUNT(*) FILTER (
                    WHERE c.terminated_at IS NULL
                      AND (c.end_date IS NULL OR c.end_date >= :now)
                ) AS active_count,
                COALESCE(SUM(o.first_payment_price) FILTER (
                    WHERE c.terminated_at IS NULL
                      AND (c.end_date IS NULL OR c.end_date >= :now)
                      AND (c.end_date IS NULL OR (c.end_date - c.start_date) >= 28)
                ), 0) AS mrr
            FROM contract c
            INNER JOIN orders o ON o.id = c.order_id
            WHERE c.user_id = ANY(:userIds)
            GROUP BY c.user_id
        SQL,
        ['now' => $now, 'userIds' => $idStrings],
        ['now' => \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE, 'userIds' => \Doctrine\DBAL\ArrayParameterType::STRING]
    )->fetchAllAssociative();

    $stats = [];
    foreach ($rows as $row) {
        $stats[(string) $row['user_id']] = [
            'activeCount' => (int) $row['active_count'],
            'totalCount' => (int) $row['total_count'],
            'mrrInHaler' => (int) $row['mrr'],
        ];
    }

    return $stats;
}
```

Notes:
- `c.end_date - c.start_date` returns a Postgres `interval` when both are timestamps, or an integer (days) when both are `date` columns. `Contract::$startDate` and `Contract::$endDate` are `Types::DATE_IMMUTABLE` → integer days → `>= 28` works directly.
- `o.first_payment_price` already holds the locked-in monthly for recurring orders. Pre-spec-025: storage default at order creation. Post-spec-025: same, plus admin-onboarded individual override. Both are correct as MRR.
- The `c.user_id = ANY(:userIds)` form requires `\Doctrine\DBAL\ArrayParameterType::STRING` — do **not** switch to `IN (:userIds)` with the same binding type, that maps to a single-value param.
- The actual table name for `Order` is `orders` (the entity uses `#[ORM\Table(name: 'orders')]` because `order` is a reserved word). Verify by inspecting `src/Entity/Order.php`; if the table name differs, swap the join.

### 2. `ContractRepository::findUserIdsWithActiveContracts()`

```php
/**
 * @return string[] RFC-4122 user UUID strings of users with ≥1 active contract.
 *                  Returns ['00000000-0000-0000-0000-000000000000'] sentinel when
 *                  no users qualify — empty arrays in `IN (:ids)` blow up at the
 *                  DBAL layer (mirrors the pattern in
 *                  UserRepository::overdueUserIdsSubquery()).
 */
public function findActiveContractUserIdsSubquery(\DateTimeImmutable $now): array
{
    /** @var array<int, array{userId: string}> $rows */
    $rows = $this->entityManager->createQueryBuilder()
        ->select('DISTINCT IDENTITY(c.user) AS userId')
        ->from(Contract::class, 'c')
        ->where('c.terminatedAt IS NULL')
        ->andWhere('c.endDate IS NULL OR c.endDate >= :now')
        ->setParameter('now', $now)
        ->getQuery()
        ->getArrayResult();

    if ([] === $rows) {
        return ['00000000-0000-0000-0000-000000000000'];
    }

    return array_map(static fn (array $r): string => (string) $r['userId'], $rows);
}
```

### 3. `UserRepository::findWithActiveContractsPaginated()` + counts + complement

Add to `src/Repository/UserRepository.php`. Mirror `findOverduePaginated()` exactly.

```php
public function __construct(
    private readonly EntityManagerInterface $entityManager,
    private readonly ContractRepository $contractRepository,  // NEW
) {
}

/** @return User[] */
public function findWithActiveContractsPaginated(int $page, int $limit, \DateTimeImmutable $now): array
{
    $offset = ($page - 1) * $limit;

    return $this->entityManager->createQueryBuilder()
        ->select('u')
        ->from(User::class, 'u')
        ->where('u.id IN (:activeIds)')
        ->setParameter('activeIds', $this->contractRepository->findActiveContractUserIdsSubquery($now))
        ->orderBy('u.createdAt', 'DESC')
        ->addOrderBy('u.id', 'DESC')
        ->setFirstResult($offset)
        ->setMaxResults($limit)
        ->getQuery()
        ->getResult();
}

public function countWithActiveContracts(\DateTimeImmutable $now): int
{
    return (int) $this->entityManager->createQueryBuilder()
        ->select('COUNT(u.id)')
        ->from(User::class, 'u')
        ->where('u.id IN (:activeIds)')
        ->setParameter('activeIds', $this->contractRepository->findActiveContractUserIdsSubquery($now))
        ->getQuery()
        ->getSingleScalarResult();
}

/** @return User[] */
public function findWithoutActiveContractsPaginated(int $page, int $limit, \DateTimeImmutable $now): array
{
    $offset = ($page - 1) * $limit;

    return $this->entityManager->createQueryBuilder()
        ->select('u')
        ->from(User::class, 'u')
        ->where('u.id NOT IN (:activeIds)')
        ->setParameter('activeIds', $this->contractRepository->findActiveContractUserIdsSubquery($now))
        ->orderBy('u.createdAt', 'DESC')
        ->addOrderBy('u.id', 'DESC')
        ->setFirstResult($offset)
        ->setMaxResults($limit)
        ->getQuery()
        ->getResult();
}

public function countWithoutActiveContracts(\DateTimeImmutable $now): int
{
    return (int) $this->entityManager->createQueryBuilder()
        ->select('COUNT(u.id)')
        ->from(User::class, 'u')
        ->where('u.id NOT IN (:activeIds)')
        ->setParameter('activeIds', $this->contractRepository->findActiveContractUserIdsSubquery($now))
        ->getQuery()
        ->getSingleScalarResult();
}
```

The sentinel zero-UUID approach means `NOT IN` works trivially when nobody has an active contract (the sentinel never matches a real user, so all users are "without active").

### 4. `UserListController` — wire up new filter + per-page stats

`src/Controller/Portal/UserListController.php`:

```php
public function __construct(
    private readonly UserRepository $userRepository,
    private readonly ContractRepository $contractRepository,  // NEW
    private readonly OverdueChecker $overdueChecker,
    private readonly ClockInterface $clock,
) {
}

public function __invoke(Request $request): Response
{
    $page = max(1, (int) $request->query->get('page', '1'));
    $limit = 20;
    $now = $this->clock->now();
    $filter = $request->query->get('filter');
    $filter = in_array($filter, ['overdue', 'active', 'inactive'], true) ? $filter : null;

    [$users, $totalUsers] = match ($filter) {
        'overdue' => [
            $this->userRepository->findOverduePaginated($page, $limit, $now),
            $this->userRepository->countOverdueUsers($now),
        ],
        'active' => [
            $this->userRepository->findWithActiveContractsPaginated($page, $limit, $now),
            $this->userRepository->countWithActiveContracts($now),
        ],
        'inactive' => [
            $this->userRepository->findWithoutActiveContractsPaginated($page, $limit, $now),
            $this->userRepository->countWithoutActiveContracts($now),
        ],
        default => [
            $this->userRepository->findAllPaginated($page, $limit),
            $this->userRepository->countTotal(),
        ],
    };

    $totalPages = (int) ceil($totalUsers / $limit);
    $overdueUserCount = $this->userRepository->countOverdueUsers($now);
    $activeUserCount = $this->userRepository->countWithActiveContracts($now);
    $inactiveUserCount = $this->userRepository->countWithoutActiveContracts($now);

    $pageUserIds = array_map(static fn (User $u) => $u->id, $users);
    $debtorIdSet = array_flip($this->overdueChecker->filterOverdueUserIds($now, $pageUserIds));
    $customerStats = $this->contractRepository->loadCustomerStatsByUserIds($pageUserIds, $now);

    return $this->render('portal/user/list.html.twig', [
        'users' => $users,
        'currentPage' => $page,
        'totalPages' => $totalPages,
        'totalUsers' => $totalUsers,
        'filter' => $filter,
        'overdueUserCount' => $overdueUserCount,
        'activeUserCount' => $activeUserCount,
        'inactiveUserCount' => $inactiveUserCount,
        'debtorIdSet' => $debtorIdSet,
        'customerStats' => $customerStats,
    ]);
}
```

### 5. Template — chips + columns

`templates/portal/user/list.html.twig`:

**Chips (replace lines 10–15):**

```twig
<div class="mb-4 flex items-center gap-2 flex-wrap">
    <a href="{{ path('portal_users_list') }}" class="btn btn-sm {{ filter ? 'btn-ghost' : 'btn-primary' }}">Vše</a>
    <a href="{{ path('portal_users_list', {filter: 'overdue'}) }}" class="btn btn-sm {{ filter == 'overdue' ? 'btn-error' : 'btn-ghost' }}">
        Pouze dlužníci ({{ overdueUserCount }})
    </a>
    <a href="{{ path('portal_users_list', {filter: 'active'}) }}" class="btn btn-sm {{ filter == 'active' ? 'btn-success' : 'btn-ghost' }}">
        S aktivními smlouvami ({{ activeUserCount }})
    </a>
    <a href="{{ path('portal_users_list', {filter: 'inactive'}) }}" class="btn btn-sm {{ filter == 'inactive' ? 'btn-warning' : 'btn-ghost' }}">
        Bez aktivních smluv ({{ inactiveUserCount }})
    </a>
</div>
```

**Header (insert two new `<th>` between `Stav` and `Vytvořeno`):**

```twig
<th>Smlouvy</th>
<th>MRR</th>
```

**Row cells (insert two new `<td>` between Stav and Vytvořeno):**

```twig
{% set stats = customerStats[user.id.toRfc4122()]|default({activeCount: 0, totalCount: 0, mrrInHaler: 0}) %}
<td class="text-sm">
    {% if stats.totalCount == 0 %}
        <span class="text-gray-400">—</span>
    {% else %}
        <span class="font-medium text-gray-900">{{ stats.activeCount }}</span>
        <span class="text-gray-500">aktivních</span>
        <span class="text-gray-400 text-xs">· {{ stats.totalCount }} celkem</span>
    {% endif %}
</td>
<td class="text-sm">
    {% if stats.mrrInHaler == 0 %}
        <span class="text-gray-400">—</span>
    {% else %}
        <span class="font-medium text-gray-900">{{ (stats.mrrInHaler / 100)|number_format(0, ',', ' ') }}</span>
        <span class="text-gray-500">Kč/měs</span>
    {% endif %}
</td>
```

**Pagination — pass filter through (already does, just verify):** the existing `routeParams: filter ? {filter: filter} : {}` covers the new filter values. **Update the empty-state row's `colspan="6"` → `colspan="8"`** to account for the two new columns.

### 6. Tests

#### Integration

`tests/Integration/Repository/ContractRepositoryTest.php` (extend) — add cases:

- `loadCustomerStatsByUserIds_givenUserWithMixedContracts_returnsActiveAndTotalCountsAndMrr` — fixture user with: 1 active UNLIMITED contract @ 1500 Kč, 1 active LIMITED ≥28d contract @ 800 Kč, 1 short LIMITED <28d contract @ 1200 Kč (must NOT count toward MRR), 1 terminated contract. Expected: activeCount=3, totalCount=4, mrr=230_000 (halere).
- `loadCustomerStatsByUserIds_givenUserWithoutContracts_omitsFromResult` — caller defaults to zeros.
- `loadCustomerStatsByUserIds_givenEmptyUserIdsArray_returnsEmptyArray` — guard against accidental full-table scan.
- `findActiveContractUserIdsSubquery_givenNoActiveContracts_returnsSentinelUuid` — pagination's `IN`/`NOT IN` must not crash.

`tests/Integration/Repository/UserRepositoryTest.php` (extend or create):

- `findWithActiveContractsPaginated_returnsOnlyUsersWithActiveContracts`
- `findWithoutActiveContractsPaginated_returnsUsersWithZeroActiveContracts_includingNeverRented`
- `countWithActiveContracts` + `countWithoutActiveContracts` sum to `countTotal()`.

#### Controller / smoke

`tests/Integration/Controller/Portal/UserListControllerTest.php` (create if absent, or extend):

- `index_unauthenticated_redirectsToLogin`
- `index_asNonAdmin_returns403`
- `index_asAdmin_rendersChipsAndColumns` — assert the new chip labels appear and the new `<th>Smlouvy</th>` / `<th>MRR</th>` are present.
- `index_filterActive_filtersUsers` — fixture has admin@ (no contracts), tenant@ (has contracts) — assert `?filter=active` lists tenant only.
- `index_filterInactive_filtersUsers` — same fixture — assert `?filter=inactive` excludes tenant.
- `index_filterUnknown_treatedAsAll` — `?filter=garbage` → falls back to "Vše".

Use `tests/Integration` conventions (DAMA DoctrineTestBundle + the standard test users from `UserFixtures`).

#### Notes

- `MockClock` is fixed at `2025-06-15 12:00:00 UTC` (per CLAUDE.md). All `now`-dependent assertions must be relative to this.
- Reuse fixture data over creating dynamic test data when possible — see `.claude/FIXTURES.md`.
- After spec 025 lands, the integration test fixtures will already contain at least one free contract (`individualMonthlyAmount = 0`); this contract must NOT inflate MRR. If 026 lands first, write a small guard test that fixture-creates a `firstPaymentPrice = 0` contract and asserts MRR contribution is 0 (sanity check).

## Acceptance

- [ ] `docker compose exec web composer quality` is green.
- [ ] Visiting `/portal/users` as admin shows two new columns ("Smlouvy", "MRR") and four chip buttons (Vše · Pouze dlužníci · S aktivními smlouvami · Bez aktivních smluv) with live counts in parentheses. **Verified manually in the browser.**
- [ ] Per-row "Smlouvy" cell shows `{active} aktivních · {total} celkem`; em-dash for users with zero contracts. "MRR" cell shows `{X} Kč/měs`; em-dash when zero.
- [ ] `?filter=active` lists only users whose `findActiveContractUserIdsSubquery` set membership is true; `?filter=inactive` lists the complement; counts in chip badges match list lengths in both cases.
- [ ] MRR includes UNLIMITED contracts at storage default and LIMITED ≥28d contracts at locked-in `Order.firstPaymentPrice`; **excludes** short LIMITED <28d contracts; excludes terminated contracts; excludes contracts whose `endDate < now`.
- [ ] One SQL query per page render for `loadCustomerStatsByUserIds` (verified with `symfony/doctrine` profiler — no N+1).
- [ ] No existing controller test for `/portal/users` regresses; the existing `?filter=overdue` chip + behaviour still works.
- [ ] Pagination preserves the new filter values (`?filter=active&page=2` keeps showing active users).

## Out of scope

- **Sortable columns.** No column on this page is sortable today; adding sort to two of them would be inconsistent and triple the controller's query branching. Defer to a "table sorting pass" spec if/when the operational pain is real.
- **MRR threshold filters** ("MRR > 1 000 Kč", etc.). Practitioner asks for cohort cuts ("active vs not"), not bucket cuts. If a need surfaces later, add chips at that point.
- **Hiding new columns for landlords/admins** in the list. Their stats naturally render as "—" because they have no contracts; the columns stay aligned. Adding role-conditional cells would clutter the template for no UX gain.
- **Tenant-only scope for the `Bez aktivních smluv` chip.** The chip will surface admins / landlords / unverified users that have never rented — by design, because that's literally the question the chip answers ("who, on this list, has zero active rentals?"). If admins want a "non-tenant" hide-toggle, separate spec.
- **Per-row CTA to "view contracts"** in the new column. The existing "Zobrazit" link goes to the user detail page where contracts already render. Don't duplicate.
- **Live-update of MRR after contract changes.** Page is rendered per request; refresh suffices. No turbo-stream / live component.
- **MRR breakdown by place / by storage type / per-month chart** on this row. The dashboard already covers global MRR; spec 024 covers per-place MRR. The customer list is a flat tally.
- **Sync with landlord MRR formula** (`ContractRepository::sumExpectedRecurringByLandlord`). Landlord MRR uses `goPayParentPaymentId IS NOT NULL` (excludes external-prepaid post-spec-025). Per-customer MRR here uses the broader recurring-shape predicate (includes external-prepaid). This divergence is intentional and called out in Q2 of discovery; harmonising both is a separate spec when external-prepaid is common enough to matter for landlord payouts.
- **`/portal/admin/orders` row data.** Spec 025 already adds onboarding badges to the order list; spec 026 stays out of that surface.

## Open questions

None — proceed.
