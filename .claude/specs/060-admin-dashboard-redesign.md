# 060 — Redesign admin dashboard: clickable cards, remove redundancy, add recent orders

**Status:** done
**Type:** UX
**Scope:** small (~6 modified files, 1 new repo method)
**Depends on:** none

## Problem

The admin dashboard at `/portal/dashboard` has useful KPIs but none of the stat cards are clickable — the admin sees "Celkem míst: 5" but can't click through to the places list. The "Nejvíc po splatnosti" table is redundant with the already-clickable Po splatnosti alert card at the top. The "Správa uživatelů" quick-action section adds little value since the sidebar already links to the user list. There's no at-a-glance view of recent order activity (the landlord dashboard has this, admin doesn't).

## Goal

1. Every KPI card is a clickable `<a>` linking to the most relevant admin list page.
2. The redundant "Nejvíc po splatnosti" top-5 table is removed.
3. The "Správa uživatelů" quick-action card is removed.
4. The 4 user-stats cards are replaced with a compact single-row summary: total users count + prominent "Neověření" badge linking to a filtered user list.
5. A "Poslední objednávky" section (last 5 orders) is added below the revenue chart, mirroring the landlord dashboard pattern.

## Context (current state)

### Admin dashboard template
- `templates/portal/dashboard_admin.html.twig` — 390 lines.
- **Alert row** (lines 9–62): Po splatnosti + Operace — already clickable `<a>` tags. Keep as-is.
- **Revenue row** (lines 65–151): 4 `<div>` cards (Tržby minulý měsíc / Provize / Očekávané tržby / Obsazenost). Not clickable.
- **Platform row** (lines 154–239): 4 `<div>` cards (Celkem míst / Celkem skladů / Pronajímatelů / Aktivních smluv). Not clickable.
- **Revenue chart** (lines 242–244): `{{ component('RevenueChart') }}`. Keep as-is.
- **User stats** (lines 247–340): "Uživatelé" heading + 4 cards (Celkem / Ověření / Neověření / Administrátoři). Replace with compact row.
- **"Nejvíc po splatnosti"** (lines 343–371): Top-5 table shown when `overdueCount > 0`. **Remove.**
- **"Správa uživatelů"** (lines 374–389): Quick-action card linking to user list. **Remove.**

### Controller
- `src/Controller/Portal/DashboardController.php` — admin branch (line 33) dispatches `GetDashboardStats` query, passes `stats` to template. Does NOT load recent orders (landlord branch does via `$this->orderRepository->findByLandlord()`).

### Query result DTO
- `src/Query/GetDashboardStatsResult.php` — has `overdueTop` (array of `OverdueContractView`) which will no longer be needed in the template. Keep in DTO (removing it is a breaking change to the query handler for no benefit; it's just unused in the template now).

### OrderRepository
- `src/Repository/OrderRepository.php:616` — has `findRecentAtPlace()` but no global `findRecent()` method.

### User list controller
- `src/Controller/Portal/UserListController.php` — filters: `overdue`, `onboarded`, `active`, `inactive`. No `unverified` filter.
- `src/Repository/UserRepository.php` — has `countTotal()`, `countVerified()`. No `findUnverifiedPaginated()` / `countUnverified()`.

### Place detail KPI pattern (reference)
- `templates/portal/place/detail.html.twig:140–200` — 3 grouped clickable KPI cards with `hover:shadow-md transition-shadow` + chevron arrow icon. This is the visual pattern to mirror for the dashboard cards.

### Landlord dashboard recent orders (reference)
- `templates/portal/dashboard_landlord.html.twig:169–226` — "Poslední objednávky" section with status badges, storage info, customer name, price. Mirror this layout.

## Requirements

### 1. Make revenue row cards clickable

**File:** `templates/portal/dashboard_admin.html.twig`

Convert each `<div>` in the revenue row to `<a>` with the place-detail hover pattern (`hover:shadow-md transition-shadow`). Add a chevron `→` or arrow icon at the top-right of each card (matching `portal/place/detail.html.twig:147`).

| Card | Link target |
|---|---|
| Tržby minulý měsíc | `{{ path('admin_bank_payments') }}` |
| Provize minulý měsíc | `{{ path('admin_orders_list') }}` |
| Očekávané tržby | `{{ path('admin_orders_list') }}` |
| Obsazenost | `{{ path('admin_places_list') }}` |

### 2. Make platform row cards clickable

Same visual treatment as requirement 1.

| Card | Link target |
|---|---|
| Celkem míst | `{{ path('admin_places_list') }}` |
| Celkem skladů | `{{ path('admin_places_list') }}` |
| Pronajímatelů | `{{ path('portal_users_list') }}` |
| Aktivních smluv | `{{ path('admin_orders_list') }}` |

### 3. Replace user stats section with compact row

Remove the "Uživatelé" `<h2>` heading and the 4-card grid (lines 247–340). Replace with a single compact row:

```twig
<div class="flex items-center justify-between bg-white shadow rounded-lg px-5 py-4 mb-8">
    <div class="flex items-center gap-6">
        <div class="text-sm text-gray-500">
            Celkem uživatelů: <span class="font-semibold text-gray-900">{{ stats.totalUsers }}</span>
        </div>
        <div class="text-sm text-gray-500">
            Ověřených: <span class="font-semibold text-gray-900">{{ stats.verifiedUsers }}</span>
        </div>
    </div>
    <a href="{{ path('portal_users_list', {filter: 'unverified'}) }}"
       class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium
              {% if stats.unverifiedUsers > 0 %}bg-amber-100 text-amber-800 hover:bg-amber-200{% else %}bg-green-100 text-green-700{% endif %}">
        {% if stats.unverifiedUsers > 0 %}
            {{ stats.unverifiedUsers }} {{ stats.unverifiedUsers == 1 ? 'neověřený' : (stats.unverifiedUsers < 5 ? 'neověření' : 'neověřených') }}
        {% else %}
            Všichni ověření
        {% endif %}
    </a>
</div>
```

Place this row **below the platform stats row**, before the revenue chart.

### 4. Add `unverified` filter to user list

**File:** `src/Controller/Portal/UserListController.php`

Add `'unverified'` to the filter match:

```php
$filter = match ($filterParam) {
    'overdue', 'onboarded', 'active', 'inactive', 'unverified' => $filterParam,
    default => null,
};
```

Add the switch case:

```php
case 'unverified':
    $users = $this->userRepository->findUnverifiedPaginated($page, $limit);
    $totalUsers = $this->userRepository->countUnverified();
    break;
```

Pass `unverifiedUserCount` to the template for the filter chip badge:

```php
$unverifiedUserCount = $this->userRepository->countUnverified();
```

**File:** `src/Repository/UserRepository.php`

Add two methods:

```php
public function countUnverified(): int
{
    return (int) $this->entityManager->createQueryBuilder()
        ->select('COUNT(u.id)')
        ->from(User::class, 'u')
        ->where('u.emailVerified = false')
        ->getQuery()
        ->getSingleScalarResult();
}

/**
 * @return User[]
 */
public function findUnverifiedPaginated(int $page, int $limit): array
{
    return $this->entityManager->createQueryBuilder()
        ->select('u')
        ->from(User::class, 'u')
        ->where('u.emailVerified = false')
        ->orderBy('u.createdAt', 'DESC')
        ->setFirstResult(($page - 1) * $limit)
        ->setMaxResults($limit)
        ->getQuery()
        ->getResult();
}
```

**File:** `templates/portal/user/list.html.twig`

Add a "Neověření (N)" filter chip to the existing chip strip, wired to `?filter=unverified`. Use the same pattern as the existing chips (check the template for exact markup).

### 5. Add recent orders section

**File:** `src/Repository/OrderRepository.php`

Add a global `findRecent()` method:

```php
/**
 * @return Order[]
 */
public function findRecent(int $limit): array
{
    return $this->entityManager->createQueryBuilder()
        ->select('o')
        ->from(Order::class, 'o')
        ->orderBy('o.createdAt', 'DESC')
        ->setMaxResults($limit)
        ->getQuery()
        ->getResult();
}
```

**File:** `src/Controller/Portal/DashboardController.php`

In the admin branch (line 33), load recent orders:

```php
$recentOrders = $this->orderRepository->findRecent(5);

return $this->render('portal/dashboard_admin.html.twig', [
    'stats' => $stats,
    'recentOrders' => $recentOrders,
]);
```

**File:** `templates/portal/dashboard_admin.html.twig`

Add a "Poslední objednávky" section below the revenue chart, mirroring the landlord dashboard pattern (`templates/portal/dashboard_landlord.html.twig:169–226`). Differences from landlord version:

- "Zobrazit vše" links to `{{ path('admin_orders_list') }}` (not `portal_landlord_orders`).
- Each order row's customer name is a link to `{{ path('admin_order_detail', {id: order.id}) }}`.
- Include the place name in the subtitle: `{{ order.storage.storageType.place.name }} · {{ order.storage.storageType.name }} - {{ order.storage.number }}`.

### 6. Remove redundant sections

**File:** `templates/portal/dashboard_admin.html.twig`

- **Remove** the "Nejvíc po splatnosti" block (lines 343–371, the `{% if stats.overdueCount > 0 %}` conditional with the top-5 list).
- **Remove** the "Správa uživatelů" quick-actions grid (lines 374–389).

## Acceptance

- [ ] All 8 KPI cards (4 revenue + 4 platform) are `<a>` tags with hover effect and link to the correct admin list page.
- [ ] Clicking "Tržby minulý měsíc" → `/portal/admin/bankovni-platby`, "Celkem míst" → `/portal/admin/mista`, "Pronajímatelů" → `/portal/users`, "Aktivních smluv" → `/portal/admin/objednavky`, etc.
- [ ] The "Nejvíc po splatnosti" top-5 table is gone. The Po splatnosti alert card at top still works.
- [ ] The "Správa uživatelů" quick-action card is gone.
- [ ] User stats are a single compact row: total count + verified count + unverified badge.
- [ ] The unverified badge links to `/portal/users?filter=unverified` and shows filtered results.
- [ ] The user list page has a "Neověření (N)" filter chip in the chip strip.
- [ ] "Poslední objednávky" shows the 5 most recent orders with customer name (linked to admin order detail), place + storage info, price, and status badge.
- [ ] `composer quality` is green.

## Out of scope

- **Restructuring the card layout** into 3 grouped KPI cards (place detail pattern) — would require new admin sub-pages (finance, occupancy, contracts at platform level). The current 4+4 grid with links is sufficient for now.
- **Revenue chart redesign** — stays as-is.
- **Adding role-based filters** (`?filter=landlord`, `?filter=admin`) to the user list — only `unverified` is added; other role filters are a separate task.
- **Admin user detail page** — the `unverified` filter links to the list, not individual user pages.
- **Removing `overdueTop` from `GetDashboardStatsResult`** — the field stays in the DTO (it's still computed by `OverdueChecker::summarise()`); only the template stops rendering it. Removing it from the DTO would require changing the query handler for zero benefit.

## Open questions

None — proceed.
