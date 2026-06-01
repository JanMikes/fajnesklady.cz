# 066 — User list: free-text search + full sortable enriched grid

**Status:** done
**Type:** feature (admin UX) + refactor
**Scope:** large (~6–8 files: criteria VO + row DTO, 1 big repo query + count, controller, template, export path)
**Depends on:** none

## Problem

Finding a customer in `/portal/users` is painful: there is **no search box** (only filter chips), and the grid is **fixed-ordered** by `createdAt DESC`. An admin who knows a name or email must page through. Sorting by MRR, contract count, etc. is impossible. The current architecture fights this: the controller fetches `User` entities per filter via 6 ad-hoc repo methods, then enriches each page separately with MRR/overdue/onboarded — so global sorting by those derived values can't work.

## Goal

The user list gets (1) a **free-text search** over name / email / phone, (2) **click-to-sort** column headers that sort the *entire* dataset (not just the page) — name, email, registered date, active contracts, MRR, YRR, and (3) keeps the existing **filter chips**, all composable (search + filter + sort + pagination together). Search combines with the active filter (AND).

## Goal architecture

Replace "fetch entities, then enrich per page" with **one enriched SQL query** that returns rows already carrying the derived columns, so `WHERE`/`ORDER BY`/`LIMIT` operate over the full, enriched dataset.

## Context (current state)

- `src/Controller/Portal/UserListController.php` — parses `page` + `filter ∈ {overdue, onboarded, active, inactive, unverified}`; calls per-filter `UserRepository::find*Paginated` + `count*`; pre-computes `debtorIdSet` (`OverdueChecker::filterOverdueUserIds`), `onboardedIdSet` (`UserRepository::findOnboardedUserIds`), `customerStats` (`ContractRepository::loadCustomerStatsByUserIds`). Passes all to `templates/portal/user/list.html.twig`.
- `templates/portal/user/list.html.twig` — filter-chip bar + table (Jméno, Email, Role, Stav, Smlouvy, MRR, YRR, Vytvořeno, Akce) + `components/pagination.html.twig`. No search input, no sortable headers.
- `ContractRepository::loadCustomerStatsByUserIds()` — the **MRR/YRR/active/total SQL already exists** (haléř, 28-day threshold, yearly excluded from MRR). Reuse its `FILTER (...)` aggregate as a grouped sub-select joined into the list query.
- `ContractRepository::findOverdueUserIds()` / `findActiveContractUserIdsSubquery()` and `UserRepository::findOnboardedUserIds()` — the WHERE logic to convert into `EXISTS (...)` predicates inside the unified query.
- `UserExportController` + `UserRepository::findIdsForExport` / `streamForExport` — must honor the same filter **and** search so the export matches the on-screen view.

## Requirements

### 1. Criteria value object + row DTO

`src/Value/UserListCriteria.php` (`final readonly`):
```php
public function __construct(
    public ?string $search,          // trimmed; null when empty
    public ?string $filter,          // overdue|onboarded|active|inactive|unverified|null
    public string $sortColumn,       // whitelisted key, default 'created'
    public string $sortDirection,    // 'asc'|'desc', default 'desc'
    public int $page,
    public int $limit = 20,
) {}
```
`src/Value/UserListRow.php` (`final readonly`): `Uuid $id`, `string $fullName`, `string $email`, `?string $phone`, `array $roles`, `bool $isVerified`, `bool $isDeactivated`, `\DateTimeImmutable $createdAt`, `int $activeCount`, `int $totalCount`, `int $mrrInHaler`, `int $yrrInHaler`, `bool $isOverdue`, `bool $isOnboarded`.

### 2. One enriched query + matching count

`UserRepository::findForAdminList(UserListCriteria $c, \DateTimeImmutable $now): list<UserListRow>` and `countForAdminList(UserListCriteria $c, \DateTimeImmutable $now): int` — DBAL (raw SQL, like `loadCustomerStatsByUserIds`). Sketch:
```sql
SELECT u.id, u.full_name, u.email, u.phone, u.roles, u.is_verified, u.deactivated_at, u.created_at,
       COALESCE(agg.active_count,0) AS active_count, COALESCE(agg.total_count,0) AS total_count,
       COALESCE(agg.mrr,0) AS mrr, COALESCE(agg.yrr,0) AS yrr,
       (EXISTS (<overdue predicate for u.id>)) AS is_overdue,
       (EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id AND o.is_admin_created = true)) AS is_onboarded
FROM users u
LEFT JOIN (
    SELECT c.user_id,
           COUNT(*) AS total_count,
           COUNT(*) FILTER (WHERE c.terminated_at IS NULL AND (c.end_date IS NULL OR c.end_date >= :now)) AS active_count,
           COALESCE(SUM(...) FILTER (... non-yearly, active, >=28d ...),0) AS mrr,
           COALESCE(SUM(o.total_price) FILTER (... active yearly ...),0) AS yrr
    FROM contract c INNER JOIN orders o ON o.id = c.order_id
    GROUP BY c.user_id
) agg ON agg.user_id = u.id
WHERE (:search IS NULL OR u.full_name ILIKE :search OR u.email ILIKE :search OR u.phone ILIKE :search)
  AND <filter predicate>            -- see below
ORDER BY <whitelisted column> <dir>, u.id ASC
LIMIT :limit OFFSET :offset
```
- **Filter predicate** by `$c->filter`: `overdue` → the overdue EXISTS; `active` → active-contract EXISTS; `inactive` → NOT active-contract EXISTS; `onboarded` → onboarded EXISTS; `unverified` → `u.is_verified = false`; null → `TRUE`.
- **Search param**: bind `:search = '%'||trim||'%'` (ILIKE, case-insensitive) or `NULL` when empty.
- **Sort whitelist** (reject anything else, fall back to `created`): `name→u.full_name`, `email→u.email`, `created→u.created_at`, `contracts→active_count`, `mrr→mrr`, `yrr→yrr`. `dir ∈ {asc,desc}` (validate). Always append `, u.id ASC` for a stable order.
- `countForAdminList` runs the same `FROM`/`JOIN`/`WHERE` with `SELECT COUNT(*)` (no ORDER/LIMIT).
- Map result rows → `UserListRow` (cast haléř ints, hydrate `Uuid::fromString`, `deactivated_at` → `isDeactivated`).

### 3. Controller

`UserListController` parses `q` (search), `filter`, `sort`, `dir`, `page`; builds `UserListCriteria` (validating sort/dir against the whitelist); calls `findForAdminList` + `countForAdminList`; computes `totalPages`. Filter-chip counts stay **global** (independent of search) via the existing `count*` methods (note this in the template: chips = totals, search narrows the table). Pass `rows`, `currentPage`, `totalPages`, `filter`, `search`, `sort`, `dir`, and the chip counts.

### 4. Template — search box + sortable headers

`templates/portal/user/list.html.twig`:
- A `GET` search form (input name `q`, placeholder "Hledat jméno, e-mail, telefon…", a submit button + a "×" clear link), with the current `filter` carried as a hidden field so searching keeps the active chip. Keep the filter-chip bar; chips link with `{q: search}` preserved.
- Sortable `<th>` for Jméno/Email/Smlouvy/MRR/YRR/Vytvořeno: each is a link to `portal_users_list` with `{q, filter, sort: <col>, dir: (sort==col and dir=='asc' ? 'desc' : 'asc'), page: 1}` and an ▲/▼ indicator on the active column. Role/Stav/Akce stay non-sortable (Stav is multi-dimensional — covered by the filter chips; note this).
- Render rows from `UserListRow`: Stav badges derived from `row.isDeactivated` / `row.isVerified` / `row.isOverdue` (Dlužník) / `row.isOnboarded` (Onboardovaný); MRR/YRR/Smlouvy from the row; "Zobrazit" → user detail.
- `pagination.html.twig` `routeParams` must include `{q, filter, sort, dir}` so paging preserves state. Empty-results state: "Žádní uživatelé neodpovídají hledání."

### 5. Export honors search + filter

`UserExportController` / `UserRepository::findIdsForExport` + `streamForExport` accept the same `filter` **and** `search` so a searched/filtered export matches the screen. (Sorting irrelevant for export.) Add `search` to those query paths; keep the streaming + 200-row clear pattern.

## Acceptance

- [ ] Typing a name/email/phone fragment and submitting filters the table to matching users (case-insensitive, partial), preserving the active filter chip.
- [ ] Clicking a sortable header sorts the **whole** dataset (verified across pages), toggling asc/desc, with an arrow on the active column; default remains newest-first.
- [ ] Sorting by MRR / Smlouvy / YRR orders correctly including users with no contracts (treated as 0).
- [ ] Filter chips still work and combine with search (AND); chip counts show global totals.
- [ ] Pagination preserves q + filter + sort + dir.
- [ ] Export reflects the current filter + search.
- [ ] One enriched query backs the list (no per-page N+1 enrichment); `composer quality` green; `composer test` green (list renders + search/sort params).

## Out of scope

- **Sorting by "status"** as a single column — status is multi-dimensional (overdue/active/onboarded/verified) and is handled by the filter chips, not a sort column. (Stated in the UI.)
- **Per-search chip counts.** Chips remain global totals; recomputing them per search query is unnecessary overhead.
- **Removing the old per-filter repo methods** if still used elsewhere — leave them unless clearly dead after the controller switch; the list path uses the new unified query.
- **Fuzzy / typo-tolerant search.** Plain `ILIKE` substring is sufficient.

## Open questions

None — proceed.
