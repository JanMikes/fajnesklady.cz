# 047 — Per-place operations split: Obsazenost / Finance / Smlouvy sub-pages + Mapa obsazenosti with date picker

**Status:** done
**Type:** feature (operations UX — daily-ops clarity for landlords + admins)
**Scope:** large (~28 files: 3 new controllers, 1 new Live Component + template, 3 new sub-page templates, 1 health-card partial reused, detail.html.twig refactor, both place-list templates aligned, storage_map_controller.js extensions, repo additions on Contract/Order/Payment, 1 new repo method on StorageOccupancyService usage, fixtures top-up, tests)
**Depends on:** 024 (per-place KPI plumbing, mirrored verbatim where applicable), 027 (`StorageOccupancyService` — extended use for arbitrary dates), 029 (TomSelect filter conventions on storage list)

## Problem

Today `/portal/places/{id}` is a single dense scroll-page (~550 lines of Twig) carrying everything: warnings, KPI tiles, **Brzy končící smlouvy** table, **Co se chystá** table, **Posledních 5 objednávek** table, **Obsazenost typů** table, **Tržby (12 měsíců)** chart, the **Správa místa** management hub, and the danger zone. QA admins report the page as "I don't know where to find the answer to X" — too much on one screen, no clear "go here to answer this question" entry points.

Two adjacent gaps:

1. **No point-in-time occupancy map.** The canvas at `/portal/places/{placeId}/canvas` shows live status only (`Storage.status` = current `OCCUPIED/AVAILABLE/...`). Daily questions like "Co bude volné 15.7.?" / "Kdo bude v A3 příští týden?" require trawling the Brzy končící / Co se chystá lists or the `/storage-types/{id}/obsazenost` planner. The map itself — the most natural surface — can't answer them.
2. **The two place lists diverge.** `/portal/places` shows `Název | Typ | Adresa | Popis | Vytvořeno | Akce` with the name **linked** to detail. `/portal/admin/places` shows `Název | Adresa | Stav | Vytvořeno | Akce` with the name **plain text** and "Detail" only under Akce. QA admins call out the inconsistency: "I understand the actions differ, but not the rest."

## Goal

Restructure per-place operations so each daily question lands on a dedicated, focused page:

1. **`/portal/places/{id}` (detail)** stays as the **orientation hub**. Above-the-fold: warnings (setup-health) → 3 grouped clickable KPI cards (Obsazenost / Tržby / Smlouvy) → Po splatnosti banner (admin) → co-owner disclaimer (landlord) → Správa místa hub + danger zone. **All tables and the chart move out.**
2. **`/portal/places/{id}/obsazenost`** — Mapa obsazenosti with a date picker (defaults to today). Reuses the existing Konva `storage_map_controller.js` (read-only mode) inside a new `PlaceOccupancyMap` Live Component. Per-storage status, tenant, and contract end-date are computed server-side via `StorageOccupancyService::currentViews($storages, $viewDate)` and baked into the JSON payload. Date changes → Live re-render → Stimulus value-change callback re-paints the canvas without recreating the stage. Hover/click reveal: nájemce, smlouva do (or "neomezeně"), link to objednávka. Below the map: the existing "Obsazenost typů" table + a "Sklady" table with current state filterable by status.
3. **`/portal/places/{id}/finance`** — Revenue page: the 12-month `RevenueChart` (already place-scoped per spec 024), KPI tiles for Tržby tento měsíc / Tržby minulý měsíc / Tržby YTD / Očekávané MRR, and a monthly-revenue table (12 rows, same data the chart uses).
4. **`/portal/places/{id}/smlouvy`** — Contracts & orders: filter-chip strip (Vše / Aktivní / Brzy končící / Nadcházející / Nedávné), four sections rendered conditionally based on the active chip — Aktivní smlouvy (paged at 50), Brzy končící (≤ 60 dní), Co se chystá (≤ 30 dní), Posledních 20 objednávek. Each row links to the role-appropriate order detail.
5. **Place lists aligned.** Both `/portal/places` and `/portal/admin/places` show the same columns: `Název (clickable) | Typ | Adresa | Stav | Vytvořeno | Akce`. Drop `Popis` from `/portal/places` (truncated noise — descriptions live on detail). Add `Typ` + `Stav` to `/portal/admin/places`.

Owner-scope rules unchanged from spec 024: landlord sees only own storages on every sub-page; admin sees the whole place. Voter gate stays at `PlaceVoter::VIEW`.

## Context (current state)

### Where things live

- **Place detail**: `src/Controller/Portal/PlaceDetailController.php` → `templates/portal/place/detail.html.twig` (550 lines). Carries the spec 024 layout.
- **Place lists**: `templates/portal/place/list.html.twig` (landlord-visible — Název clickable) vs. `templates/admin/place/list.html.twig` (admin — Název plain text, has `Stav` badge).
- **Canvas editor**: `src/Controller/Portal/StorageCanvasController.php` → `templates/portal/storage/canvas.html.twig` → `assets/controllers/storage_canvas_controller.js` (1322 lines). Editor mode, full r/w. NOT what we're reusing.
- **Public order map**: `templates/public/order_create.html.twig:33` mounts `data-controller="storage-map"` (`assets/controllers/storage_map_controller.js`, 724 lines). Read-only Konva. **This** is what we reuse.
- **Storage type occupancy planner (spec 027)**: `src/Controller/Portal/StorageTypeOccupancyController.php` → `templates/portal/storage_type/occupancy.html.twig`. Per-type rentals table + 90-day Gantt strip. We link to it from the new Obsazenost sub-page but don't change it.
- **Point-in-time service**: `src/Service/Storage/StorageOccupancyService::currentViews(array $storages, \DateTimeImmutable $now)` (verified at `src/Service/Storage/StorageOccupancyService.php:36`). Internally calls `findActiveByStorages($now)`, `findActiveByStoragesInDateRange($now, $now)`, `findByStoragesInDateRange($now, $now)`. The argument is named `$now` but is just a date threshold — the same code path resolves "active at date X" when X is past or future. **Implementation must verify with a non-today date in tests.**
- **`RevenueChart` Live Component**: `src/Twig/Components/RevenueChart.php` + `templates/components/RevenueChart.html.twig`. Already accepts `placeId` + `landlordId` LiveProps (spec 024). Reused unchanged on the Finance sub-page.

### Spec 024 dashboard data, still loaded for the detail page

The slimmed detail page still needs the 3 grouped KPI cards (Obsazenost / Tržby / Smlouvy), the Po splatnosti banner, setup-health alerts, and co-owner disclaimer. These come from `GetPlaceDashboardStats` (kept) — we do NOT pull `placeOverview` (`GetPlaceTypeOccupancyOverview`) here anymore, only on `/obsazenost`. We also drop `expiringContracts` / `upcomingOrders` / `recentOrders` loading from `PlaceDetailController` — they're owned by the new `/smlouvy` controller.

### Repository methods that already exist (verified)

`ContractRepository`:
- `countActiveRecurringAtPlace(Place, ?User)` (line 935)
- `countActiveContractsAtPlace(Place, ?User, \DateTimeImmutable)` (line 960)
- `sumExpectedRecurringAtPlace(Place, ?User)` (line 979)
- `findExpiringWithinDaysAtPlace(int, \DateTimeImmutable, Place, ?User)` (line 1008)

`OrderRepository`:
- `findRecentAtPlace(Place, int, ?User)` (line 568) — caps results via `setMaxResults`
- `findUpcomingAtPlace(Place, int, \DateTimeImmutable, ?User)` (line 595)

`PaymentRepository`:
- `sumAtPlaceAndPeriod(Place, int year, int month, ?User)` (line 286)
- `getMonthlyRevenueAtPlace(Place, int months, \DateTimeImmutable, ?User)` (line 312)

`StorageRepository` (from spec 024):
- `countAtPlace` / `countOccupiedAtPlace` / `countAvailableAtPlace` / `countBlockedAtPlace` / `hasCoOwners` / `findFreeCountByTypeAtPlace`

### Repository methods that are missing

- `ContractRepository::findActiveAtPlace(Place, ?User, \DateTimeImmutable $now)` → list (the *count* variant exists; we need the rows).
- `PaymentRepository::sumAtPlaceForRange(Place, \DateTimeImmutable $from, \DateTimeImmutable $to, ?User)` → for "tento měsíc so far" and "YTD". The existing `sumAtPlaceAndPeriod` is whole-month only.

### `storage_map_controller.js` shape today

Already a clean read-only Konva visualization with pan/zoom, minimap, tooltip, modal. The tooltip uses `storage.storageTypeName`, `storage.dimensions`, `storage.pricePerMonth` (lines 614–630). Modal uses the same plus `storage.photoUrls`, `storage.pricePerWeek` (lines 568–611). Status color via `getStorageColor()` (line 706) reads `storage.status`. **Already works for our use** — we just pre-compute `status` per the picked date server-side and pass enriched fields (`tenantName`, `rentedUntil`, `orderUrl`, …) only used in the new `viewMode: 'occupancy'` branch we add. Existing public-order flow uses `currentStorageTypeId` + `selectMode`; we use neither.

### Conventions worth restating

- `final readonly` for queries / results / DTOs. `final` on controllers.
- Single-action `__invoke` controllers; route at class level.
- `EntityManager` composition; never `flush()` outside fixtures.
- Live Component pattern: `RevenueChart` is the in-repo precedent.
- Czech UI text with full diacritics (`obsazenost` not `obsazenost`).
- `MockClock` pinned at `2025-06-15 12:00:00 UTC` in tests.

## Architecture

```
                                 ┌─────────────────────────────┐
                                 │  /portal/places/{id}        │
                                 │  detail.html.twig (slim)    │
                                 │  - warnings                 │
                                 │  - 3 KPI cards (clickable)──┼──┐
                                 │  - overdue / co-owner       │  │
                                 │  - Správa místa hub         │  │
                                 │  - danger zone (admin)      │  │
                                 └─────────────────────────────┘  │
                                                                  │
        ┌───────────────────────────────┬──────────────────────────┘
        ▼                               ▼                            ▼
  /obsazenost                     /finance                     /smlouvy
  PlaceOccupancyController        PlaceFinanceController       PlaceContractsController
                                                              
  ┌──────────────────────┐        ┌──────────────────────┐    ┌──────────────────────┐
  │ Date picker (FP)     │        │ 4 KPI tiles          │    │ Chip strip:          │
  │ + Dnes/+7/+30 chips  │        │ - tento měsíc        │    │ Vše/Akt./Konč./...   │
  │ ↓                    │        │ - minulý měsíc       │    │ ↓                    │
  │ <PlaceOccupancyMap>  │        │ - YTD                │    │ Aktivní (paged 50)   │
  │  (Live Component)    │        │ - Očekávané MRR      │    │ Brzy končící (60d)   │
  │  ├ date picker       │        │ ↓                    │    │ Co se chystá (30d)   │
  │  ├ Konva canvas      │        │ RevenueChart (Live)  │    │ Posledních 20 obj.   │
  │  ├ legend            │        │ ↓                    │    └──────────────────────┘
  │  └ side panel        │        │ Monthly table (12)   │
  │     (click → tenant) │        └──────────────────────┘
  ├──────────────────────┤
  │ Obsazenost typů      │
  │ (existing table)     │
  ├──────────────────────┤
  │ Sklady — current     │
  │ state, filter chips  │
  └──────────────────────┘

  ┌──────────────────────────────────────────────────────────────────────┐
  │  StorageOccupancyService::currentViews($storages, $viewDate)         │
  │    ← powers the map AT $viewDate (verified to work for non-today)    │
  │  ContractRepository::findActiveAtPlace($place, ?$owner, $now)   NEW  │
  │  PaymentRepository::sumAtPlaceForRange(...)                     NEW  │
  └──────────────────────────────────────────────────────────────────────┘
```

## Requirements

### 1. Slim down `/portal/places/{id}` (detail page)

#### Controller

Edit `src/Controller/Portal/PlaceDetailController.php`:

- Remove `ContractRepository`, `OrderRepository`, and `GetPlaceTypeOccupancyOverview` dependencies + their fetches (`$expiringContracts`, `$upcomingOrders`, `$recentOrders`, `$placeOverview`).
- Keep `GetPlaceDashboardStats`, `StorageRepository`, `StorageTypeRepository`, `PlaceAccessRepository`, `ClockInterface`.
- Keep all current template variables EXCEPT the four tables now moved to sub-pages.

The render call shrinks to:

```php
return $this->render('portal/place/detail.html.twig', [
    'place' => $place,
    'stats' => $stats,
    'storageTypeCount' => $storageTypeCount,
    'storageCount' => $storageCount,
    'hasAccess' => $hasAccess,
    'isAdmin' => $isAdmin,
    'canManageCodes' => $canManageCodes,
]);
```

#### Template `templates/portal/place/detail.html.twig`

Delete:
- The 4 KPI-tile grid (lines 158–267 in current state).
- "Brzy končící smlouvy" (lines 269–296).
- "Co se chystá" (lines 298–325).
- "Posledních 5 objednávek" (lines 327–361).
- "Obsazenost typů" (lines 363–394).
- "Tržby — chart (12 měsíců)" (lines 396–403).

Replace the KPI block with **three grouped clickable cards**, each wrapped in `<a href>` and hover-lifted. Same visual idiom as the existing tiles. Card payload:

```twig
{# 1. Obsazenost → /obsazenost #}
<a href="{{ path('portal_places_occupancy', {placeId: place.id}) }}"
   class="block bg-white overflow-hidden shadow rounded-lg hover:shadow-md transition-shadow">
    <div class="p-5">
        <div class="flex items-center justify-between">
            <dt class="text-sm font-medium text-gray-500 uppercase">Obsazenost</dt>
            <svg class="w-4 h-4 text-gray-400" …>{# chevron right #}</svg>
        </div>
        <dd class="mt-2 text-3xl font-bold text-gray-900">{{ stats.occupancyRate|number_format(1, ',', ' ') }}%</dd>
        <p class="mt-1 text-sm text-gray-500">
            {{ stats.occupiedStorages }}/{{ stats.totalStorages }} obsazených{% if stats.blockedStorages > 0 %} · {{ stats.blockedStorages }} blokovaných{% endif %}
        </p>
    </div>
    <div class="bg-gray-50 px-5 py-3">
        <div class="w-full bg-gray-200 rounded-full h-2">
            <div class="bg-green-600 h-2 rounded-full" style="width: {{ min(stats.occupancyRate, 100) }}%"></div>
        </div>
    </div>
</a>

{# 2. Tržby → /finance #}
<a href="{{ path('portal_places_finance', {placeId: place.id}) }}" class="…">
    <div class="p-5">
        <div class="flex items-center justify-between">
            <dt class="text-sm font-medium text-gray-500 uppercase">Tržby</dt>
            <svg …>{# chevron #}</svg>
        </div>
        <dd class="mt-2 text-3xl font-bold text-gray-900">{{ (stats.lastMonthRevenue / 100)|number_format(0, ',', ' ') }} Kč</dd>
        <p class="mt-1 text-sm text-gray-500">minulý měsíc</p>
        <p class="mt-1 text-sm text-gray-700">
            Očekávané MRR: <strong>{{ (stats.expectedThisMonthRevenue / 100)|number_format(0, ',', ' ') }} Kč / měsíc</strong>
        </p>
    </div>
</a>

{# 3. Smlouvy → /smlouvy #}
<a href="{{ path('portal_places_contracts', {placeId: place.id}) }}" class="…">
    <div class="p-5">
        <div class="flex items-center justify-between">
            <dt class="text-sm font-medium text-gray-500 uppercase">Smlouvy</dt>
            <svg …>{# chevron #}</svg>
        </div>
        <dd class="mt-2 text-3xl font-bold text-gray-900">{{ stats.activeContractsCount }}</dd>
        <p class="mt-1 text-sm text-gray-500">aktivních smluv</p>
        <p class="mt-1 text-sm text-gray-700">
            Z toho s opakovanou platbou: <strong>{{ stats.activeRecurringContracts }}</strong>
        </p>
    </div>
</a>
```

Wrap in `<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">`. The Po splatnosti banner above and the warnings above that stay exactly as today.

Final order on detail page:
1. Header card (unchanged).
2. Setup-health alerts (admin only, unchanged).
3. Co-owner disclaimer (landlord only, unchanged).
4. Po splatnosti banner (admin only, unchanged).
5. **3 grouped clickable KPI cards** (replacement).
6. **Správa místa** hub (unchanged).
7. **Nebezpečná zóna** (admin only, unchanged).

After deletion the file drops from ~550 lines to ~350.

### 2. New `/portal/places/{placeId}/obsazenost` — `PlaceOccupancyController`

#### Route

`/portal/places/{placeId}/obsazenost` (`placeId` UUID requirement), name `portal_places_occupancy`. `#[IsGranted('ROLE_LANDLORD')]`. Voter check `PlaceVoter::VIEW` on the resolved place.

#### Controller

`src/Controller/Portal/PlaceOccupancyController.php` (new). Single-action.

```php
final class PlaceOccupancyController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly QueryBus $queryBus,
        private readonly ClockInterface $clock,
    ) {}

    public function __invoke(string $placeId, Request $request): Response
    {
        $place = $this->placeRepository->get(Uuid::fromString($placeId));
        $this->denyAccessUnlessGranted(PlaceVoter::VIEW, $place);

        /** @var User $user */
        $user = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $ownerScope = $isAdmin ? null : $user;
        $now = $this->clock->now();

        $placeOverview = $this->queryBus->handle(new GetPlaceTypeOccupancyOverview(
            placeId: $place->id,
            landlordId: $isAdmin ? null : $user->id,
        ));

        // The "Sklady — current state" table at the bottom of the page;
        // independent of the map's $viewDate (which lives inside the Live Component).
        $storages = null === $ownerScope
            ? $this->storageRepository->findByPlace($place)
            : $this->storageRepository->findByOwnerAndPlace($user, $place);

        return $this->render('portal/place/occupancy.html.twig', [
            'place' => $place,
            'placeOverview' => $placeOverview,
            'storages' => $storages,
            'isAdmin' => $isAdmin,
            'now' => $now,
        ]);
    }
}
```

#### Template `templates/portal/place/occupancy.html.twig`

Sections in order:

1. **Header**: H1 "Obsazenost — {place.name}". Breadcrumb: Místa → place.name → Obsazenost.
2. **Mapa obsazenosti** — render the Live Component:
   ```twig
   {{ component('PlaceOccupancyMap', {
       placeId: place.id.toRfc4122,
       landlordId: isAdmin ? null : app.user.id.toRfc4122,
   }) }}
   ```
3. **Obsazenost typů** — port the existing table verbatim from `detail.html.twig` lines 363–394. Uses `placeOverview.rows`. Each row links to `portal_storage_type_occupancy`.
4. **Sklady — aktuální stav** — port the conventions from `templates/portal/storage/list.html.twig`'s table but read-only and place-scoped: `Číslo | Typ | Stav | Nájemce | Pronajato do | Akce`. Filter chips at top (`Vše / Volné / Obsazené / Blokované`) — pure client-side toggling via existing `tom-select` or anchor links (`?show=...`). Each row's "Akce" links to `portal_storages_edit` (for landlord/admin) so they can drill into the storage editor. Use `StorageOccupancyService::currentViews($storages, $now)` to enrich each row with `tenantName` + `rentedUntil` without N+1. Skip the section when `storages` is empty.

When `$storages` is empty (fresh place) the whole sub-page still renders the map shell with "Žádné sklady na tomto místě."

### 3. New `PlaceOccupancyMap` Live Component (the map with date picker)

#### `src/Twig/Components/PlaceOccupancyMap.php` (new)

```php
namespace App\Twig\Components;

use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\UserRepository;
use App\Service\Storage\StorageOccupancyService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class PlaceOccupancyMap
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $placeId = '';

    #[LiveProp]
    public ?string $landlordId = null;

    /** YYYY-MM-DD; defaults to today on initial render. */
    #[LiveProp(writable: true)]
    public string $viewDate = '';

    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly UserRepository $userRepository,
        private readonly StorageOccupancyService $occupancyService,
        private readonly ClockInterface $clock,
    ) {}

    public function getViewDateOrToday(): \DateTimeImmutable
    {
        if ('' === $this->viewDate) {
            return $this->clock->now()->setTime(0, 0, 0);
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->viewDate);
        return $parsed ?: $this->clock->now()->setTime(0, 0, 0);
    }

    /** @return array<string, mixed> */
    public function getMapData(): array
    {
        $place = $this->placeRepository->get(Uuid::fromString($this->placeId));
        $owner = null !== $this->landlordId
            ? $this->userRepository->get(Uuid::fromString($this->landlordId))
            : null;

        $storages = null === $owner
            ? $this->storageRepository->findByPlace($place)
            : $this->storageRepository->findByOwnerAndPlace($owner, $place);

        $viewDate = $this->getViewDateOrToday();
        $views = $this->occupancyService->currentViews($storages, $viewDate);

        $payload = [];
        foreach ($storages as $s) {
            $view = $views[$s->id->toRfc4122()] ?? null;

            $contract = $view?->currentContract;
            $order = $view?->currentOrder;
            $blocked = $view?->blockedBy;

            $status = match (true) {
                null !== $contract, null !== $order && $order->status->value === 'paid' => 'occupied',
                null !== $order => 'reserved',
                null !== $blocked => 'manually_unavailable',
                default => 'available',
            };

            // role-appropriate order link for the side panel
            $orderForLink = $contract?->order ?? $order;
            $orderUrl = null;
            if (null !== $orderForLink) {
                $orderUrl = $this->generateUrl(
                    null !== $owner ? 'portal_landlord_order_detail' : 'admin_order_detail',
                    ['id' => $orderForLink->id->toRfc4122()],
                );
            }

            $payload[] = [
                'id' => $s->id->toRfc4122(),
                'number' => $s->number,
                'storageTypeId' => $s->storageType->id->toRfc4122(),
                'storageTypeName' => $s->storageType->name,
                'dimensions' => $s->storageType->getDimensionsInMeters(),
                'coordinates' => $s->coordinates,
                'status' => $status,
                'lockCode' => $s->lockCode,
                'tenantName' => null !== $contract ? $contract->user->fullName
                    : (null !== $order ? $order->user->fullName : null),
                'rentedFrom' => $view?->rentedFrom?->format('Y-m-d'),
                'rentedUntil' => $view?->rentedUntil?->format('Y-m-d'),
                'isUnlimited' => null !== $contract && null === $contract->endDate && null === $contract->terminatesAt,
                'isTerminating' => null !== $contract?->terminatesAt,
                'startsOnViewDate' => null !== $view?->rentedFrom
                    && $view->rentedFrom->format('Y-m-d') === $viewDate->format('Y-m-d'),
                'endsOnViewDate' => null !== $view?->rentedUntil
                    && $view->rentedUntil->format('Y-m-d') === $viewDate->format('Y-m-d'),
                'orderUrl' => $orderUrl,
                'photoUrls' => [], // not used in occupancy mode but storage_map_controller expects the key
                'pricePerMonth' => $s->getEffectivePricePerMonthInCzk(),
                'pricePerWeek' => $s->getEffectivePricePerWeekInCzk(),
            ];
        }

        return [
            'place' => $place,
            'storagesJson' => json_encode($payload, JSON_THROW_ON_ERROR),
            'viewDate' => $viewDate,
            'hasMapImage' => null !== $place->mapImagePath,
        ];
    }
}
```

The component injects `UrlGeneratorInterface` via the trait (or via a thin private helper using `Symfony\Component\Routing\Generator\UrlGeneratorInterface`); fall back to standard injection in the constructor if the trait doesn't expose it.

#### `templates/components/PlaceOccupancyMap.html.twig` (new)

```twig
{% set data = this.mapData %}
<div {{ attributes.defaults({class: 'bg-white shadow rounded-lg overflow-hidden'}) }}>
    {# Date picker toolbar #}
    <div class="flex flex-wrap items-center gap-3 p-4 border-b border-gray-200">
        <label class="text-sm font-medium text-gray-700">Datum:</label>
        <input type="date"
               data-model="viewDate"
               data-controller="flatpickr"
               value="{{ data.viewDate|date('Y-m-d') }}"
               class="form-input text-sm">
        <div class="flex items-center gap-1 ml-2">
            <button type="button"
                    data-action="live#action"
                    data-live-action-param="setToday"
                    class="btn btn-ghost btn-xs">Dnes</button>
            <button type="button"
                    data-action="live#action"
                    data-live-action-param="shiftDays"
                    data-live-days-param="7"
                    class="btn btn-ghost btn-xs">+7 dní</button>
            <button type="button"
                    data-action="live#action"
                    data-live-action-param="shiftDays"
                    data-live-days-param="30"
                    class="btn btn-ghost btn-xs">+30 dní</button>
        </div>
        <span class="ml-auto text-sm text-gray-500">Stav k {{ data.viewDate|date('d.m.Y') }}</span>
    </div>

    {% if not data.hasMapImage %}
        <div class="p-8 text-center text-gray-500">
            Mapa není nahrána. Pro zobrazení mapy obsazenosti nahrajte obrázek půdorysu v
            <a href="{{ path('portal_places_edit', {id: data.place.id}) }}" class="link">nastavení místa</a>.
        </div>
    {% else %}
        <div class="p-4"
             data-controller="storage-map"
             data-storage-map-map-image-value="{{ asset('uploads/' ~ data.place.mapImagePath) }}"
             data-storage-map-storages-value="{{ data.storagesJson|e('html_attr') }}"
             data-storage-map-storage-types-value="[]"
             data-storage-map-place-id-value="{{ data.place.id }}"
             data-storage-map-view-mode-value="occupancy"
             data-storage-map-view-date-value="{{ data.viewDate|date('Y-m-d') }}">
            <div class="relative">
                <div data-storage-map-target="container"
                     data-live-ignore
                     class="border border-gray-300 rounded-lg w-full"
                     style="min-height: 500px;"></div>
                <div data-storage-map-target="minimap"
                     class="absolute bottom-2 right-2 border-2 border-gray-400 rounded shadow-lg bg-white overflow-hidden"
                     style="width: 180px; height: 120px; display: none; z-index: 10;"
                     data-live-ignore></div>
                <div data-storage-map-target="tooltip"
                     class="hidden absolute z-10 bg-white border border-gray-200 shadow-lg rounded-lg p-3 text-sm pointer-events-none max-w-xs"></div>
            </div>

            {# Legend #}
            <div class="flex flex-wrap gap-4 mt-4 text-sm text-gray-600">
                <div class="flex items-center gap-2"><span class="w-4 h-4 rounded bg-green-500"></span> Volný</div>
                <div class="flex items-center gap-2"><span class="w-4 h-4 rounded bg-yellow-500"></span> Rezervovaný</div>
                <div class="flex items-center gap-2"><span class="w-4 h-4 rounded bg-red-500"></span> Obsazený</div>
                <div class="flex items-center gap-2"><span class="w-4 h-4 rounded bg-gray-500"></span> Blokovaný</div>
            </div>

            {# Modal (click to expand) #}
            <dialog data-storage-map-target="modal" class="fixed inset-0 z-50 m-0 p-0 w-full h-full bg-transparent backdrop:bg-black/50">
                <div class="fixed inset-0 flex items-center justify-center p-4"
                     onclick="if(event.target===this)event.currentTarget.parentElement.close()">
                    <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 data-storage-map-target="modalTitle" class="font-bold text-lg"></h3>
                            <button type="button" onclick="this.closest('dialog').close()" class="text-gray-400 hover:text-gray-600">&times;</button>
                        </div>
                        <div data-storage-map-target="modalPhotos" class="hidden"></div>
                        <div data-storage-map-target="modalDetails" class="space-y-2 text-sm"></div>
                        <div class="mt-5 flex justify-end gap-2">
                            <a data-storage-map-target="modalOrderBtn" href="#" class="btn btn-primary btn-sm">Otevřít objednávku</a>
                            <button type="button" onclick="this.closest('dialog').close()" class="btn btn-ghost btn-sm">Zavřít</button>
                        </div>
                    </div>
                </div>
            </dialog>
        </div>
    {% endif %}
</div>
```

#### LiveActions on `PlaceOccupancyMap`

```php
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;

#[LiveAction]
public function setToday(): void
{
    $this->viewDate = $this->clock->now()->format('Y-m-d');
}

#[LiveAction]
public function shiftDays(#[LiveArg('days')] int $days): void
{
    $base = $this->getViewDateOrToday();
    $this->viewDate = $base->modify("+{$days} days")->format('Y-m-d');
}
```

#### `assets/controllers/storage_map_controller.js` — minimal additions

Three small changes (verified against current shape):

1. **New value**: `viewMode: { type: String, default: 'order' }` + `viewDate: String`. When `viewMode === 'occupancy'`, the controller skips public-order paths and routes status colors through the new branch already present in `getStorageColor` (no logic change — `currentStorageTypeIdValue` is empty, so it falls into the default `switch (storage.status)` branch which already maps `available/reserved/occupied/manually_unavailable` to the right colors).

2. **`storagesValueChanged()` callback** (mirrors existing `highlightStorageValueChanged()`):
   ```js
   storagesValueChanged() {
       if (!this.initialized) return;
       this.renderStorages();
       this.renderMinimap();
   }
   ```
   Live re-renders update the `data-storage-map-storages-value` attribute → Stimulus fires this → canvas repaints **without** recreating the Konva stage. The container is marked `data-live-ignore` in the template so DOM survives intact.

3. **Tooltip + modal occupancy branch** — in `updateTooltip(storage)` and `showStorageModal(storage)`, branch on `this.viewModeValue === 'occupancy'`:

   ```js
   // tooltip — occupancy branch
   if (this.viewModeValue === 'occupancy') {
       const tenantLine = storage.tenantName
           ? `<div class="text-gray-600">Nájemce: <span class="font-medium">${escapeHtml(storage.tenantName)}</span></div>`
           : '';
       const untilLine = storage.rentedUntil
           ? `<div class="text-gray-600">Pronajato do: ${formatCzDate(storage.rentedUntil)}${storage.isTerminating ? ' (výpověď)' : ''}</div>`
           : (storage.isUnlimited ? `<div class="text-gray-600">Pronajato: neomezeně</div>` : '');
       const badge = storage.endsOnViewDate
           ? `<span class="badge badge-warning text-xs ml-1">Končí dnes</span>`
           : (storage.startsOnViewDate ? `<span class="badge badge-info text-xs ml-1">Začíná dnes</span>` : '');
       this.tooltipTarget.innerHTML = `
           <div class="space-y-1">
               <div class="flex items-center gap-2">
                   <span class="font-bold text-gray-900">${storage.number}</span>
                   <span class="badge ${this.getStatusClass(storage.status, storage)} text-xs">${this.getStatusText(storage.status, storage)}</span>
                   ${badge}
               </div>
               <div class="text-gray-600">${escapeHtml(storage.storageTypeName)} · ${storage.dimensions}</div>
               ${tenantLine}
               ${untilLine}
           </div>`;
   } else {
       /* existing order-mode tooltip body — unchanged */
   }
   ```

   The modal gets the same treatment: in occupancy mode `modalDetailsTarget.innerHTML` shows tenant + rentedFrom + rentedUntil + status; `modalOrderBtnTarget.href = storage.orderUrl ?? '#'` and we hide the button when `orderUrl` is null.

   Add a tiny `escapeHtml(s)` helper at the top of the controller (`s == null` → `''`; else replace `& < > " '` with their entities). Tenant names come from `User.fullName` which is operator-input — never trust it.

`storage_canvas_controller.js` is **not** touched.

### 4. New `/portal/places/{placeId}/finance` — `PlaceFinanceController`

#### Route + controller

`/portal/places/{placeId}/finance`, name `portal_places_finance`. `final class PlaceFinanceController`, single-action, `PlaceVoter::VIEW`.

```php
public function __invoke(string $placeId): Response
{
    $place = $this->placeRepository->get(Uuid::fromString($placeId));
    $this->denyAccessUnlessGranted(PlaceVoter::VIEW, $place);

    /** @var User $user */
    $user = $this->getUser();
    $isAdmin = $this->isGranted('ROLE_ADMIN');
    $owner = $isAdmin ? null : $user;
    $now = $this->clock->now();

    $thisMonthRevenue = $this->paymentRepository->sumAtPlaceForRange(
        $place,
        $now->modify('first day of this month')->setTime(0, 0, 0),
        $now->modify('first day of next month')->setTime(0, 0, 0),
        $owner,
    );
    $lastMonth = $now->modify('first day of last month');
    $lastMonthRevenue = $this->paymentRepository->sumAtPlaceAndPeriod(
        $place, (int) $lastMonth->format('Y'), (int) $lastMonth->format('n'), $owner,
    );
    $yearStart = $now->modify('first day of January this year')->setTime(0, 0, 0);
    $ytdRevenue = $this->paymentRepository->sumAtPlaceForRange($place, $yearStart, $now->modify('first day of next month')->setTime(0, 0, 0), $owner);
    $expectedMonthly = $this->contractRepository->sumExpectedRecurringAtPlace($place, $owner);

    $monthlyRevenue = $this->paymentRepository->getMonthlyRevenueAtPlace($place, 12, $now, $owner);
    // sorted ASC by month already (verified by reading the existing helper)

    return $this->render('portal/place/finance.html.twig', [
        'place' => $place,
        'thisMonthRevenue' => $thisMonthRevenue,
        'lastMonthRevenue' => $lastMonthRevenue,
        'ytdRevenue' => $ytdRevenue,
        'expectedMonthly' => $expectedMonthly,
        'monthlyRevenue' => $monthlyRevenue,
        'isAdmin' => $isAdmin,
    ]);
}
```

#### Template `templates/portal/place/finance.html.twig`

1. Header + breadcrumb (Místa → place.name → Finance).
2. 4 KPI tiles (same idiom as detail tiles): Tržby tento měsíc · Tržby minulý měsíc · YTD · Očekávané MRR.
3. `{{ component('RevenueChart', { placeId: place.id.toRfc4122, landlordId: isAdmin ? null : app.user.id.toRfc4122 }) }}`.
4. Monthly revenue table (12 rows, ASC): `Měsíc | Tržby (Kč)`. Driven by `monthlyRevenue` (`array<array{year, month, total}>`).

### 5. New `/portal/places/{placeId}/smlouvy` — `PlaceContractsController`

#### Route + controller

`/portal/places/{placeId}/smlouvy`, name `portal_places_contracts`. Single-action. `PlaceVoter::VIEW`.

Accepts `?show=` chip query param, allowed values `all` (default), `active`, `expiring`, `upcoming`, `recent`.

```php
public function __invoke(string $placeId, Request $request): Response
{
    $place = $this->placeRepository->get(Uuid::fromString($placeId));
    $this->denyAccessUnlessGranted(PlaceVoter::VIEW, $place);

    /** @var User $user */
    $user = $this->getUser();
    $isAdmin = $this->isGranted('ROLE_ADMIN');
    $owner = $isAdmin ? null : $user;
    $now = $this->clock->now();
    $show = in_array($request->query->get('show', 'all'), ['all', 'active', 'expiring', 'upcoming', 'recent'], true)
        ? $request->query->get('show', 'all') : 'all';

    $activeContracts = ($show === 'all' || $show === 'active')
        ? $this->contractRepository->findActiveAtPlace($place, $owner, $now)
        : [];
    $expiringContracts = ($show === 'all' || $show === 'expiring')
        ? $this->contractRepository->findExpiringWithinDaysAtPlace(60, $now, $place, $owner)
        : [];
    $upcomingOrders = ($show === 'all' || $show === 'upcoming')
        ? $this->orderRepository->findUpcomingAtPlace($place, 30, $now, $owner)
        : [];
    $recentOrders = ($show === 'all' || $show === 'recent')
        ? $this->orderRepository->findRecentAtPlace($place, 20, $owner)
        : [];

    return $this->render('portal/place/contracts.html.twig', [
        'place' => $place,
        'show' => $show,
        'activeContracts' => $activeContracts,
        'expiringContracts' => $expiringContracts,
        'upcomingOrders' => $upcomingOrders,
        'recentOrders' => $recentOrders,
        'isAdmin' => $isAdmin,
    ]);
}
```

#### Template `templates/portal/place/contracts.html.twig`

1. Header + breadcrumb.
2. Chip strip (`Vše | Aktivní (N) | Brzy končící (N) | Co se chystá (N) | Nedávné`) — same idiom as `templates/portal/storage_type/occupancy.html.twig` chips. Count badges from each list `length`.
3. Active contracts section: table `Nájemce | Sklad | Pronajato OD | Pronajato DO | Cena/měsíc | Akce` (link → order detail by role).
4. Brzy končící: copy verbatim from current `detail.html.twig:270–296` block.
5. Co se chystá: copy verbatim from current `detail.html.twig:299–325` block.
6. Posledních 20 objednávek: copy verbatim from current `detail.html.twig:328–361` with limit bumped to 20 + add a footer link "Otevřít vše v seznamu objednávek →" pointing to `admin_orders_list?place={place.id}` (admin) or `portal_landlord_orders_list?place={place.id}` (landlord). **Note:** verify both list controllers accept a `place` query filter; if not, link to the unfiltered list — that's still useful nav. (Out-of-scope to wire missing filters.)

### 6. Repository additions

#### `src/Repository/ContractRepository.php`

```php
/**
 * Active (non-terminated) contracts at $place — current state at $now.
 * Mirrors {@see self::countActiveContractsAtPlace()} but returns the rows.
 *
 * @return Contract[]
 */
public function findActiveAtPlace(Place $place, ?User $owner, \DateTimeImmutable $now): array
{
    $qb = $this->entityManager->createQueryBuilder()
        ->select('c')
        ->from(Contract::class, 'c')
        ->join('c.storage', 's')
        ->where('s.place = :place')
        ->andWhere('c.terminatedAt IS NULL')
        ->andWhere('(c.endDate IS NULL OR c.endDate >= :now)')
        ->setParameter('place', $place)
        ->setParameter('now', $now)
        ->orderBy('c.startDate', 'DESC');

    if (null !== $owner) {
        $qb->andWhere('s.owner = :owner')->setParameter('owner', $owner);
    }

    return $qb->getQuery()->getResult();
}
```

#### `src/Repository/PaymentRepository.php`

```php
public function sumAtPlaceForRange(
    Place $place,
    \DateTimeImmutable $from,
    \DateTimeImmutable $to,
    ?User $owner,
): int {
    $qb = $this->entityManager->createQueryBuilder()
        ->select('SUM(p.amount)')
        ->from(Payment::class, 'p')
        ->join('p.storage', 's')
        ->where('s.place = :place')
        ->andWhere('p.paidAt >= :from')
        ->andWhere('p.paidAt < :to')
        ->setParameter('place', $place)
        ->setParameter('from', $from)
        ->setParameter('to', $to);

    if (null !== $owner) {
        $qb->andWhere('s.owner = :owner')->setParameter('owner', $owner);
    }

    return (int) ($qb->getQuery()->getSingleScalarResult() ?? 0);
}
```

### 7. `StorageOccupancyService` arbitrary-date verification

The implementer **must** add a unit test asserting `currentViews($storages, $someFutureDate)` returns the contract/order/blocked-by entries that overlap `$someFutureDate` rather than today. If the underlying repo methods turn out to scope to current state in a way the parameter name hides, fix the repo methods to honor the date threshold and add a regression test. The service is owned by spec 027 — touch surgically.

Quick read of the dependencies (`ContractRepository::findActiveByStorages`, `OrderRepository::findActiveByStoragesInDateRange`, `StorageUnavailabilityRepository::findByStoragesInDateRange`) suggests they already do "rows whose `[startDate, endDate]` overlaps the given timestamp(s)" — so the parameter rename is the only thing wrong. Implementer confirms by reading the queries; if they accidentally hard-code "now" anywhere, that's a bug to fix here.

### 8. Place lists alignment (`/portal/places` + `/portal/admin/places`)

Both tables become:

```
| Název (clickable) | Typ | Adresa | Stav | Vytvořeno | Akce |
```

Notes:
- **Název** column always wraps the place name in `<a href="{{ path('portal_places_detail', {id: place.id}) }}" class="link">…</a>` (when the viewer has access — for landlord with no PlaceAccess and no owned storage on `/portal/places`, the name stays plain text and the row's Akce shows "Požádat o přístup" — existing behavior).
- **Typ** column — port the badge dot from current `portal/place/list.html.twig:58–63` to admin.
- **Adresa** — both templates already render via `_address_inline` partial / inline; keep current call sites.
- **Stav** badge (Aktivní / Neaktivní) — currently admin-only at `admin/place/list.html.twig:46–52`. Port the same block to portal.
- **Drop**: the `Popis` column from `portal/place/list.html.twig` (truncated, redundant with name).
- Setup-health amber triangles (Chybí provozní řád / Chybí návod) stay next to the name on both templates — already mirrored.
- Actions diverge intentionally:
  - Portal (landlord): `Detail` (when they have access / own a storage there) OR `Žádost odeslána` OR `Požádat o přístup`.
  - Admin: `Detail | Upravit | Editor` — unchanged from spec 024's row addition.

Single shared partial **not** introduced — the two contexts diverge on access logic enough that the cleanest path is keeping two templates with matching headers and cells. (A shared `_place_row.html.twig` would carry too many flags.)

### 9. Routing summary

| Route name | Path | Controller |
|---|---|---|
| `portal_places_detail` (existing) | `/portal/places/{id}` | `PlaceDetailController` (slimmed) |
| `portal_places_occupancy` (new) | `/portal/places/{placeId}/obsazenost` | `PlaceOccupancyController` |
| `portal_places_finance` (new) | `/portal/places/{placeId}/finance` | `PlaceFinanceController` |
| `portal_places_contracts` (new) | `/portal/places/{placeId}/smlouvy` | `PlaceContractsController` |

Add UUID requirement on each `{placeId}` pattern, matching the existing convention.

### 10. Fixtures top-up

`fixtures/ContractFixtures.php` + neighbors — verify the dev DB has, at the place owned by `landlord@` (the one ID `019d6881-cf14-7c1a-80cf-7e2b19256cdf` the user references):

- ≥ 1 storage occupied AT today (`2025-06-15`) so the map shows red.
- ≥ 1 storage with a contract ending exactly today (badge "Končí dnes" lights up).
- ≥ 1 storage with a contract starting at today + 7 days (visible when picker advances).
- ≥ 1 storage blocked via `StorageUnavailability` covering a future window.
- ≥ 1 unlimited contract (so the "neomezeně" tooltip branch renders).
- The existing co-owner fixture from spec 024 stays in place.

If most coverage already exists, top up gaps — don't break spec 023's overdue / spec 027's planner tests.

### 11. Tests

#### Unit — `tests/Unit/Twig/Components/PlaceOccupancyMapTest.php` (new)

- Construct the component with a fixture place's UUID, `viewDate = '2025-06-15'`. Call `getMapData()`. Assert payload's `storagesJson` decodes to an array whose entries carry `status`, `tenantName`, `rentedUntil`, `orderUrl`.
- Same component, `viewDate = '2025-06-22'`. Assert that for a fixture contract ending `2025-06-20`, the storage's payload entry has `status='available'` and `tenantName=null`. **This is the regression-anchor for the arbitrary-date guarantee.**
- LiveAction `setToday()` resets `viewDate` to MockClock's `2025-06-15`.
- LiveAction `shiftDays(7)` from base `2025-06-15` → `viewDate='2025-06-22'`.

#### Integration — `tests/Integration/Repository/ContractRepositoryTest.php`

Add: `findActiveAtPlace` returns active contracts at the place at `$now`; excludes terminated and past-endDate; respects owner scope.

#### Integration — `tests/Integration/Repository/PaymentRepositoryTest.php`

Add: `sumAtPlaceForRange` honors `[from, to)` half-open range; respects owner scope.

#### Integration — `tests/Integration/Service/Storage/StorageOccupancyServiceTest.php`

Extend the existing spec-027 test with: `currentViews([$s], $futureDate)` where `$futureDate` is 30 days past today and the storage's contract ends in 14 days → returned view has `currentContract=null` (and `nextBookedFrom` reflects whatever booking comes next, if any). **This is the regression-anchor for the service-level arbitrary-date guarantee.**

#### Integration — `tests/Integration/Controller/Portal/PlaceOccupancyControllerTest.php` (new)

- Login `admin@`, GET `/portal/places/{id}/obsazenost` → 200; response contains "Mapa obsazenosti" / "Obsazenost typů" / "Sklady — aktuální stav".
- Login `landlord@`, same URL → 200; KPI counts scoped to their storages.
- Login `tenant@` → 403 (or redirect to login).
- Visit `?viewDate=` is not a URL param (it's a LiveProp), so this controller doesn't need to validate it — the Live Component does.

#### Integration — `tests/Integration/Controller/Portal/PlaceFinanceControllerTest.php` (new)

- 200 as admin + landlord; chart renders; assert all 4 KPI tile values appear on the page.

#### Integration — `tests/Integration/Controller/Portal/PlaceContractsControllerTest.php` (new)

- 200 with each `show=` value; sections render or hide correctly.
- Landlord scope: tenant from a co-owned storage doesn't appear.

#### Integration — `tests/Integration/Controller/Portal/PlaceDetailControllerTest.php` (existing)

Update assertions: the page should NO LONGER contain `Brzy končící smlouvy` / `Posledních 5 objednávek` / `Obsazenost typů` / `Tržby — chart`. It SHOULD contain the 3 grouped KPI card titles linking to the three new sub-routes. Existing assertions for warnings / Po splatnosti / Správa místa / Nebezpečná zóna stay.

#### Integration — `tests/Integration/Controller/Admin/AdminPlaceListControllerTest.php` (existing, if present) or new

Assert the row contains an `<a href="…/portal/places/{id}">{{ place.name }}</a>` (clickable name) and the new `Typ` + `Stav` columns are rendered.

#### Manual walk-through (Czech, full diacritics)

After `docker compose exec web composer db:reset`:

1. Login `admin@`. Open `/portal/places/{landlordPlaceId}` → detail page is now slim (warnings + 3 KPI cards + Správa místa + Nebezpečná zóna). No tables.
2. Click **Obsazenost** card → map loads with today's status. Hover a red sklad → tooltip shows nájemce + Pronajato do … . Click → modal with "Otevřít objednávku". Change date picker to +7 dní → map repaints (no flicker, no scroll jump). Klikni "Dnes" → returns to today.
3. Click **Tržby** card → 4 KPI tiles + chart + 12-row table. Numbers align with admin dashboard `/portal/dashboard`.
4. Click **Smlouvy** card → 4 sections render. Klikni chip "Brzy končící" → only that section visible.
5. `/portal/admin/places` → name clickable to detail; `Typ` + `Stav` columns present.
6. `/portal/places` (as `landlord@`) → name clickable; columns match admin.
7. As `landlord@` on a place with co-owner: dashboard tiles + map filter to their storages; "Vidíte pouze své sklady" disclaimer present.
8. As `tenant@`: `/portal/places/{id}/obsazenost` → 403/redirect.

## Acceptance

- [ ] `docker compose exec web composer quality` green.
- [ ] `docker compose exec web composer test` green (1104+ tests).
- [ ] Manual walk-through above passes for `admin@`, `landlord@`, `landlord2@`, `tenant@`.
- [ ] `templates/portal/place/detail.html.twig` no longer contains any of: "Brzy končící smlouvy", "Co se chystá", "Posledních 5 objednávek", "Obsazenost typů", "Tržby — chart". Setup-health / Po splatnosti / Správa místa / Nebezpečná zóna unchanged.
- [ ] The three new routes resolve and pass `PlaceVoter::VIEW`.
- [ ] `StorageOccupancyService::currentViews($storages, $futureDate)` returns the rental state AT `$futureDate`, not today (asserted by test).
- [ ] Live Component date-change re-renders the map's colors + tooltip data without recreating the Konva stage (container is preserved via `data-live-ignore`).
- [ ] Tooltip + modal at the picked date show tenant name + smlouva DO + link to the role-appropriate order detail.
- [ ] Both place lists have matching headers `Název | Typ | Adresa | Stav | Vytvořeno | Akce`, with the name linked to detail wherever the viewer has access.
- [ ] BACKLOG.md row added.

## Out of scope

- **Calendar widget for upcoming starts/expirations on the place dashboard.** A list + a date-picker map cover the daily-ops need; full calendar lives at `/portal/calendar`.
- **Pre-filtering admin/landlord order list pages by place** when the user clicks "Otevřít vše v seznamu objednávek →". Useful but adds query-string filters to two controllers — separate spec.
- **Per-storage-type revenue breakdown** on `/finance`. The chart + monthly table cover MRR / revenue; per-type slicing is a future increment.
- **Excel export of per-place pages.** Spec 028 covers the list pages; per-place dashboard exports are deferred.
- **Multi-place comparison** (overlay two places' revenue charts). Not requested.
- **Animations / transitions on date picker change.** Stimulus re-render is enough; smooth animation would need extra Konva work.
- **Storage modal photos in occupancy mode.** Current canvas/storage-map modal shows photos for the public order flow; on the occupancy map we hide them (no operational value at a glance). Hidden via `if (viewMode === 'occupancy') modalPhotosTarget.classList.add('hidden')`.
- **Real-time updates (WebSocket / SSE).** When a contract is signed, the map won't auto-refresh. Daily-ops; manual refresh acceptable.
- **Per-storage occupancy timeline on hover** (e.g. mini-Gantt). The /storage-types/{id}/obsazenost planner already owns this view.
- **Mobile-first design of the occupancy map.** The canvas pans/zooms on touch but the side panel + chips assume desktop. Mobile users see a "Pro plánování přepněte na desktop." notice mirrored from spec 027's planner.
- **Refactoring `storage_map_controller.js` into separate order/occupancy/canvas controllers.** Bounded duplication; the `viewMode` branch is the cheapest cut.

## Open questions

None — proceed.
