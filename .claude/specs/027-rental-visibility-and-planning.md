# 027 — Rental visibility & planning ("od kdy / do kdy") across portal

**Status:** done
**Type:** feature (UX + planning visibility)
**Scope:** large (~18 files: 2 new value objects, 1 new service, 2 new queries × 3 files, 1 new controller, 1 new template, 4 modified templates, 2 modified controllers, repository additions, fixtures top-up, tests)
**Depends on:** 024 (per-place dashboard — extends the "Volné sklady (per typ)" panel)

## Problem

A landlord (and admin) opening any planning surface today cannot answer the everyday question **"jak je to s tímhle typem skladu — kolik je pronajato, kolik volných, do kdy?"** without clicking through individual orders.

Concrete gaps observed in the codebase (verified in this spec):

- **`/portal/calendar`** (`templates/portal/calendar/index.html.twig:88–158`) shows daily aggregates `X volných / Y obsazených / Z blokovaných` and a list of storages with their **current** status — but never the date a rental ends. There's no signal in the day cell when a contract finishes that day, no per-storage timeline, and the table at the bottom (`lines 162–212`) doesn't show `pronajato do`.
- **Storage list `/portal/places/{placeId}/storages?storage_type=…`** (`templates/portal/storage/list.html.twig:138–189`) shows `Číslo · Stav · Vytvořeno · Akce` — no nájemce, no `pronajato OD–DO`, no "příští volný". This is the natural surface for "tell me about all units of this type" and the most useful single click for the landlord, yet it carries the least planning info.
- **Storage type list `/portal/places/{placeId}/storage-types`** (`templates/portal/storage_type/list.html.twig:30–86`) is pure CRUD: `Název · rozměry · cena · Akce`. No `Celkem / Obsazeno / Volných / Nejbližší volný`.
- **Place detail `/portal/places/{id}`** has "Volné sklady (per typ)" (`templates/portal/place/detail.html.twig:335–349`) which gives a count only — never "do kdy je obsazeno" or "kdy bude volný další".

The signal **does** live in data already (`Contract.startDate`, `Contract.endDate`, `Contract.terminatesAt`, `Order.startDate`, `Order.endDate`, `StorageUnavailability`). It is just not surfaced.

## Goal

Give every landlord and admin a one-glance answer to **"jak je na tom tenhle typ skladu / místo?"** — and a dedicated planning page per storage type that lets them see who finishes when over the next 90 days.

User-visible outcomes:

1. **Calendar grid** carries day-cell badges when units finish that day (`X končí`) or start (`Y začíná`); clicking a day expands an inline detail panel listing exact storages + nájemci + dates.
2. **Calendar gains a "Časová osa" view** (Gantt-style horizontal strip per storage, current month, occupied/blocked windows colored, hover = nájemce + endDate) toggleable next to the existing month grid.
3. **Storage list (when type selected)** gains columns `Nájemce · Pronajato OD · Pronajato DO · Příští volný`. `null` endDate of a recurring rental renders as `neomezeně` with a small icon. `Contract.terminatesAt` set ⇒ shown as `do dd.mm.yyyy (ukončuje se)` with a warning icon.
4. **Storage type list** gains columns `Celkem · Obsazeno · Volných · Nejbližší volný` and a `Plánování` link to a new per-type page.
5. **Place detail's "Volné sklady (per typ)"** is replaced by a richer **"Obsazenost typů"** block: per row → totals, free count, `Nejbližší uvolnění do dd.mm.yyyy`, link to `Plánování`.
6. **New per-type planning page** at `/portal/places/{placeId}/storage-types/{id}/obsazenost`: KPI tiles, sortable rentals table, 90-day mini-timeline strip per storage (CSS-grid, no JS).

Across all surfaces, a single rule for "do kdy":

| Source (in this order)                              | Display                                              |
|-----------------------------------------------------|------------------------------------------------------|
| `Contract.terminatesAt`                             | `do dd.mm.yyyy (ukončuje se)` + ⚠ icon               |
| `Contract.endDate` (if signed contract exists)      | `do dd.mm.yyyy`                                      |
| `Order.endDate` (if no contract yet)                | `do dd.mm.yyyy`                                      |
| recurring & both above are null & rental is active  | `neomezeně` + ∞ icon                                 |
| no active rental                                    | `—` (and `Volný` badge, plus `Příští rezervace` if any) |

## Context (current state)

### Existing surfaces touched

- `src/Controller/Portal/CalendarController.php` — line-by-line walked: builds `calendarData` per day from orders (`OrderRepository::findActiveByStoragesInDateRange`) + unavailabilities (`StorageUnavailabilityRepository::findByStoragesInDateRange`). Renders aggregates per day; per-storage dates **not** kept.
- `src/Controller/Portal/StorageListController.php` — `IsGranted('ROLE_LANDLORD')`, applies `PlaceVoter::VIEW`, owner-scopes via `findFiltered($owner, $place, $selectedStorageType)`. We extend it without changing the security gate.
- `src/Controller/Portal/StorageTypeListController.php` — **`IsGranted('ROLE_ADMIN')`**. The storage-type list as a whole is admin-only. We **only** extend it (more columns + planning link). We do **not** loosen the gate; the new per-type planning page (`StorageTypeOccupancyController`) is independently `ROLE_LANDLORD` so landlords reach it from elsewhere (storage list "Plánování" link, place detail "Obsazenost typů" block).
- `src/Controller/Portal/PlaceDetailController.php` — already injects multiple repositories (spec 024). We add one new repository call.

### Existing data model

- `Contract` (`src/Entity/Contract.php`) — `startDate`, `endDate (nullable)`, `terminatesAt (nullable)`, `terminatedAt (nullable)`. Active = `terminatedAt IS NULL AND (endDate IS NULL OR endDate >= now)`.
- `Order` (`src/Entity/Order.php`) — `startDate`, `endDate (nullable)`, `RentalType (UNLIMITED|FIXED_TERM)`. Storage-blocking statuses: `CREATED, RESERVED, AWAITING_PAYMENT, PAID`.
- `StorageUnavailability` — manual blocks with `startDate / endDate (nullable)`.
- `Storage.status` (`StorageStatus`) — coarse current state only; `OCCUPIED / RESERVED / AVAILABLE / MANUALLY_UNAVAILABLE`. We **never** rely on this alone for "do kdy"; we always fetch the live Contract/Order.

### Existing helpers we reuse

- `App\Service\StorageAvailabilityChecker::isAvailable($storage, $start, $end)` — point-in-time boolean per storage. Useful for ad-hoc checks; we do **not** use it for the bulk listing — too N+1 for table rendering.
- `App\Repository\OrderRepository::findActiveByStoragesInDateRange(Storage[], $from, $to)` (line 271) — already used by Calendar.
- `App\Repository\StorageUnavailabilityRepository::findByStoragesInDateRange(Storage[], $from, $to)` (line 133).
- `App\Repository\ContractRepository::findActiveByStorage(Storage, $now)` (line 99) — per-storage. Bulk equivalent missing — added below.
- `App\Value\OverdueContractView` is the established "view object" pattern in `src/Value/`. We follow that.

### Conventions reminder

- `final readonly` for value objects, queries, query results.
- `EntityManager` composition; never `flush()` outside fixtures.
- Single-action `__invoke` controllers; route at class level; `final` modifier.
- `MockClock` pinned at `2025-06-15 12:00:00 UTC` in tests; never `new \DateTimeImmutable()`.
- Czech UI text MUST have full diacritics ("nájemce", "obsazenost", "nejbližší", "ukončuje se").
- Turbo is disabled globally; opt-in with `data-turbo="true"` per-element when needed (we don't here).

## Architecture

```
                        ┌────────────────────────────────────────────────────┐
                        │  ContractRepository::findActiveByStorages(...)     │
                        │  OrderRepository::findActiveByStoragesInDateRange  │
                        │  StorageUnavailabilityRepository::findByStoragesInDateRange │
                        └────────────────────────────────────────────────────┘
                                              ▲
                                              │ batch, 0 N+1
                        ┌────────────────────────────────────────────────────┐
                        │  Service\Storage\StorageOccupancyService           │
                        │    + currentViews(array<Storage>, now)             │
                        │        → array<storageId, StorageRentalView>       │
                        │    + spansInRange(array<Storage>, from, to)        │
                        │        → array<storageId, RentalSpan[]>            │
                        └────────────────────────────────────────────────────┘
                                              ▲
                                              │
   ┌──────────────────────────┬───────────────┼──────────────────┬─────────────────────┐
   ▼                          ▼               ▼                  ▼                     ▼
StorageList (table)   StorageTypeList    PlaceDetail       Calendar              StorageTypeOccupancy
  + extra columns     (admin-only)       "Obsazenost      (month + Časová osa     (NEW page)
                       + extra columns    typů" panel      + per-day badges +     + KPI + sortable
                       + Plánování link   replaces         expandable detail)     table + 90d strip
                                          "Volné sklady"
```

`StorageRentalView` is the snapshot for "tell me about this storage RIGHT NOW". `RentalSpan` is one window in a date range used by the Gantt strip and per-day calendar drill-down.

## Requirements

### 1. Value objects — `src/Value/StorageRentalView.php`, `src/Value/RentalSpan.php`

#### `src/Value/StorageRentalView.php`

```php
namespace App\Value;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\StorageUnavailability;

final readonly class StorageRentalView
{
    public function __construct(
        public Storage $storage,
        // exactly one of these two is non-null when storage is occupied/reserved by a paying customer:
        public ?Contract $currentContract,
        public ?Order $currentOrder,
        // resolved per the table in "Goal" — null when storage is fully free today
        public ?\DateTimeImmutable $rentedFrom,
        public ?\DateTimeImmutable $rentedUntil,
        // active manual block covering today, when applicable
        public ?StorageUnavailability $blockedBy,
        // "kdy bude volný" — null if currently free (i.e. today)
        public ?\DateTimeImmutable $availableFrom,
        // earliest future start of any other reservation/contract (after rentedUntil) — null if none
        public ?\DateTimeImmutable $nextBookedFrom,
    ) {}

    public bool $isOccupied { get => null !== $this->currentContract || null !== $this->currentOrder; }
    public bool $isBlocked  { get => null !== $this->blockedBy; }
    public bool $isFree     { get => !$this->isOccupied && !$this->isBlocked; }
    public bool $isUnlimited { get => $this->isOccupied && null === $this->rentedUntil; }
    public bool $isTerminating { get => null !== $this->currentContract && null !== $this->currentContract->terminatesAt; }

    public ?string $tenantName {
        get => $this->currentContract?->user->fullName ?? $this->currentOrder?->user->fullName;
    }
}
```

PHP 8.4 property hooks are already in use across entities (`CLAUDE.md`); same syntax fits in value objects.

#### `src/Value/RentalSpan.php`

```php
namespace App\Value;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\StorageUnavailability;

enum RentalSpanKind: string
{
    case CONTRACT = 'contract';
    case ORDER = 'order';
    case BLOCK = 'block';
}

final readonly class RentalSpan
{
    public function __construct(
        public Storage $storage,
        public RentalSpanKind $kind,
        public \DateTimeImmutable $startDate,
        // null = open-ended (recurring unlimited contract or open-ended block)
        public ?\DateTimeImmutable $endDate,
        public ?string $tenantName,           // null for BLOCK
        public Contract|Order|StorageUnavailability $source,
    ) {}
}
```

### 2. `src/Service/Storage/StorageOccupancyService.php`

```php
namespace App\Service\Storage;

use App\Entity\Storage;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Repository\StorageUnavailabilityRepository;
use App\Value\RentalSpan;
use App\Value\RentalSpanKind;
use App\Value\StorageRentalView;

final readonly class StorageOccupancyService
{
    public function __construct(
        private ContractRepository $contractRepository,
        private OrderRepository $orderRepository,
        private StorageUnavailabilityRepository $unavailabilityRepository,
    ) {}

    /**
     * Today snapshot per storage.
     *
     * Resolution per storage:
     *   - currentContract = active contract spanning $now (terminatedAt IS NULL AND startDate <= now AND (endDate IS NULL OR endDate >= now))
     *   - currentOrder    = if no contract: order with status in CREATED/RESERVED/AWAITING_PAYMENT/PAID and startDate <= now AND (endDate IS NULL OR endDate >= now)
     *   - rentedUntil     = currentContract?.terminatesAt ?? currentContract?.endDate ?? currentOrder?.endDate
     *   - rentedFrom      = currentContract?.startDate ?? currentOrder?.startDate
     *   - blockedBy       = unavailability spanning $now (only when no current contract/order)
     *   - availableFrom   = rentedUntil + 1 day; null when storage is currently free
     *   - nextBookedFrom  = earliest future startDate among (orders + contracts of this storage) > rentedUntil ?? $now
     *
     * @param Storage[] $storages
     * @return array<string, StorageRentalView>  keyed by Storage->id->toRfc4122()
     */
    public function currentViews(array $storages, \DateTimeImmutable $now): array { /* … */ }

    /**
     * All occupied/blocked spans intersecting [$from, $to] per storage.
     * Used by the calendar Gantt strip and per-day detail panel.
     *
     * @param Storage[] $storages
     * @return array<string, RentalSpan[]>  keyed by Storage->id->toRfc4122()
     */
    public function spansInRange(array $storages, \DateTimeImmutable $from, \DateTimeImmutable $to): array { /* … */ }
}
```

Implementation rules:

- Both methods accept up to a few hundred storages and execute at most three SQL queries (contracts, orders, unavailabilities). No per-storage round trips.
- For `currentViews`, after the bulk fetches, group results by storageId in PHP and pick the one currentContract/currentOrder/blockedBy per the priority above.
- For `spansInRange`, a single contract/order with `endDate IS NULL` becomes a `RentalSpan` with `endDate = null` (renderer caps at `to`).

### 3. Repository additions

#### `src/Repository/ContractRepository.php`

```php
/**
 * @param Storage[] $storages
 * @return Contract[]
 */
public function findActiveByStorages(array $storages, \DateTimeImmutable $now): array
{
    if ([] === $storages) {
        return [];
    }

    return $this->entityManager->createQueryBuilder()
        ->select('c')
        ->from(Contract::class, 'c')
        ->where('c.storage IN (:storages)')
        ->andWhere('c.terminatedAt IS NULL')
        ->andWhere('c.startDate <= :now')
        ->andWhere('c.endDate IS NULL OR c.endDate >= :now')
        ->setParameter('storages', $storages)
        ->setParameter('now', $now)
        ->getQuery()
        ->getResult();
}

/**
 * Contracts overlapping [$from, $to] for the given storages, excluding the
 * "currentlyActive" filter — used by the spansInRange path.
 *
 * @param Storage[] $storages
 * @return Contract[]
 */
public function findOverlappingByStorages(
    array $storages,
    \DateTimeImmutable $from,
    \DateTimeImmutable $to,
): array {
    if ([] === $storages) {
        return [];
    }

    return $this->entityManager->createQueryBuilder()
        ->select('c')
        ->from(Contract::class, 'c')
        ->where('c.storage IN (:storages)')
        ->andWhere('c.terminatedAt IS NULL')
        ->andWhere('c.startDate <= :to')
        ->andWhere('c.endDate IS NULL OR c.endDate >= :from')
        ->setParameter('storages', $storages)
        ->setParameter('from', $from)
        ->setParameter('to', $to)
        ->orderBy('c.startDate', 'ASC')
        ->getQuery()
        ->getResult();
}

/**
 * Earliest future startDate of any active or upcoming contract for storages
 * (excluding contracts whose endDate ≤ $strictlyAfter). Used to compute
 * "nextBookedFrom" for the storages currently free.
 *
 * @param Storage[] $storages
 * @return array<string, \DateTimeImmutable>  keyed by Storage->id->toRfc4122()
 */
public function findNextStartByStorages(array $storages, \DateTimeImmutable $strictlyAfter): array { /* GROUP BY storage_id, MIN(startDate) */ }
```

#### `src/Repository/OrderRepository.php`

```php
/**
 * Earliest future startDate per storage among storage-blocking orders
 * (status in CREATED/RESERVED/AWAITING_PAYMENT/PAID) where startDate > $strictlyAfter.
 *
 * @param Storage[] $storages
 * @return array<string, \DateTimeImmutable>
 */
public function findNextStartByStorages(array $storages, \DateTimeImmutable $strictlyAfter): array { /* … */ }
```

`findActiveByStoragesInDateRange` (line 271) already covers the order-overlap case and is reused as-is.

### 4. Queries

#### `src/Query/GetStorageTypeOccupancy.php`

```php
namespace App\Query;

use Symfony\Component\Uid\Uuid;

/**
 * @implements QueryMessage<GetStorageTypeOccupancyResult>
 */
final readonly class GetStorageTypeOccupancy implements QueryMessage
{
    public function __construct(
        public Uuid $placeId,
        public Uuid $storageTypeId,
        public ?Uuid $landlordId = null,   // null = admin (whole place); set = scope to that owner
    ) {}
}
```

#### `src/Query/GetStorageTypeOccupancyResult.php`

```php
namespace App\Query;

use App\Entity\StorageType;
use App\Value\StorageRentalView;

final readonly class GetStorageTypeOccupancyResult
{
    /**
     * @param StorageRentalView[] $rows  ordered: occupied first (by rentedUntil ASC, nulls last), then free, then blocked
     */
    public function __construct(
        public StorageType $storageType,
        public int $totalCount,
        public int $occupiedCount,
        public int $availableCount,
        public int $blockedCount,
        public ?\DateTimeImmutable $nextFreeingDate,   // earliest non-null rentedUntil among occupied
        public ?\DateTimeImmutable $nextBookedDate,    // earliest nextBookedFrom among free
        public array $rows,
    ) {}
}
```

#### `src/Query/GetStorageTypeOccupancyQuery.php`

Handler reuses `StorageOccupancyService::currentViews` after pulling storages via `StorageRepository::findByStorageTypeAndPlace` (existing, line 101) — owner-scope by post-filtering when `$landlordId` is set (cheaper than altering the existing repo method's signature).

#### `src/Query/GetPlaceTypeOccupancyOverview.php` + `…Result.php` + `…Query.php`

```php
final readonly class GetPlaceTypeOccupancyOverview implements QueryMessage
{
    public function __construct(public Uuid $placeId, public ?Uuid $landlordId = null) {}
}

final readonly class GetPlaceTypeOccupancyOverviewResult
{
    /** @param GetPlaceTypeOccupancyRow[] $rows */
    public function __construct(public array $rows) {}
}

final readonly class GetPlaceTypeOccupancyRow
{
    public function __construct(
        public StorageType $storageType,
        public int $totalCount,
        public int $occupiedCount,
        public int $availableCount,
        public int $blockedCount,
        public ?\DateTimeImmutable $nextFreeingDate,
        public ?\DateTimeImmutable $nextBookedDate,
    ) {}
}
```

The handler walks each `StorageType` at the place once, aggregates from a single `StorageOccupancyService::currentViews` call across all storages of the place (one batch fetch), then groups by `storageType` in PHP. Bound = #storages at place; small.

### 5. New controller — `src/Controller/Portal/StorageTypeOccupancyController.php`

```php
#[Route(
    '/portal/places/{placeId}/storage-types/{id}/obsazenost',
    name: 'portal_storage_type_occupancy',
    requirements: ['placeId' => '[0-9a-f-]{36}', 'id' => '[0-9a-f-]{36}'],
)]
#[IsGranted('ROLE_LANDLORD')]
final class StorageTypeOccupancyController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly StorageOccupancyService $occupancyService,
        private readonly QueryBus $queryBus,
        private readonly ClockInterface $clock,
    ) {}

    public function __invoke(Request $request, string $placeId, string $id): Response
    {
        $place = $this->placeRepository->get(Uuid::fromString($placeId));
        $this->denyAccessUnlessGranted(PlaceVoter::VIEW, $place);

        $storageType = $this->storageTypeRepository->get(Uuid::fromString($id));
        if (!$storageType->place->id->equals($place->id)) {
            throw $this->createNotFoundException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $now = $this->clock->now();

        $result = $this->queryBus->handle(new GetStorageTypeOccupancy(
            placeId: $place->id,
            storageTypeId: $storageType->id,
            landlordId: $isAdmin ? null : $user->id,
        ));

        // 90-day Gantt strips per storage
        $rangeFrom = $now->setTime(0, 0);
        $rangeTo = $rangeFrom->modify('+90 days');
        $storages = array_map(static fn ($row) => $row->storage, $result->rows);
        $spans = $this->occupancyService->spansInRange($storages, $rangeFrom, $rangeTo);

        return $this->render('portal/storage_type/occupancy.html.twig', [
            'place' => $place,
            'storageType' => $storageType,
            'result' => $result,
            'spans' => $spans,
            'rangeFrom' => $rangeFrom,
            'rangeTo' => $rangeTo,
            'isAdmin' => $isAdmin,
        ]);
    }
}
```

Filter query string `?show=all|occupied|free|blocked` — handled in the template by hiding rows that don't match. (Server-side filtering is unnecessary; one type usually has < 100 storages.)

### 6. New template — `templates/portal/storage_type/occupancy.html.twig`

Sections, top to bottom:

1. **Breadcrumb** — Místa › `{{ place.name }}` › Typy skladů › `{{ storageType.name }}` › Plánování obsazenosti.
2. **Header card** — name + dimensions + default monthly price.
3. **KPI tiles** (5 tiles, 5-col grid on lg, 2-col on md, 1-col on sm):
   - `Celkem` — `result.totalCount`
   - `Obsazeno` — `result.occupiedCount` (red dot)
   - `Volných` — `result.availableCount` (green dot)
   - `Blokováno` — `result.blockedCount` (gray dot) — hidden when 0
   - `Nejbližší uvolnění` — `do dd.mm.yyyy` from `result.nextFreeingDate`, or "—"
4. **Filter chip strip** — `Vše · Obsazené · Volné · Blokované`. Pure links with `?show=…`.
5. **Rentals table** — sortable client-side (no JS dep — use `data-sort` attributes for CSS hooks; the implementer can pick a tiny existing Stimulus controller if one exists, or skip and rely on default order). Columns:
   - Sklad (číslo) — link to `portal_storages_edit`
   - Stav (badge: Obsazený / Volný / Rezervovaný / Blokováno)
   - Nájemce — `view.tenantName ?? '—'`, with link to `admin_order_detail` / `portal_landlord_order_detail` based on `isAdmin`
   - Pronajato OD — `view.rentedFrom|date('d.m.Y')` / "—"
   - Pronajato DO — see "do kdy" rule above
   - Příští volný — `view.availableFrom|date('d.m.Y')` / "Volný" / for free storages: `Volný` (+ if `view.nextBookedFrom`: `další rezervace od dd.mm.yyyy`)
   - Akce — link to `portal_landlord_order_detail` / `admin_order_detail` (when occupied), else nothing.

   Default order: occupied (rentedUntil ASC, nulls last), then free, then blocked.

6. **90-day mini-timeline** — one row per storage. CSS grid `grid-template-columns: repeat(90, minmax(4px, 1fr));`. Each row prefixed with `Sklad #číslo`. Within each row, render absolutely-positioned segments for `spans[storage.id]`:
   - red bar for `CONTRACT` / `ORDER` (with title attribute = nájemce + dates)
   - gray hatched bar for `BLOCK`
   - segments clipped to `[rangeFrom, rangeTo]`; null endDate ⇒ extends to right edge
   - Day-of-today vertical accent line
   - Above the row, axis ticks every 7 days with the start-of-week date (`d.m`)

   Responsive: hide the strip on `< md`, show a "Pro plánování přepněte na desktop" notice instead.

7. **Empty states**:
   - 0 storages of this type at the place → friendly empty card with link to `portal_storages_list?storage_type={{ storageType.id }}`.

### 7. Modifications — `templates/portal/storage/list.html.twig`

When `selectedStorageType` is set:

- Pass `rentalViews` (keyed by storage id) from `StorageListController` (computed via `StorageOccupancyService::currentViews`).
- Add columns to the existing table (after `Stav`): `Nájemce`, `Pronajato OD`, `Pronajato DO`, `Příští volný`. Apply the "do kdy" display rule.
- Card header (lines 126–135) gets a secondary action button: **`Plánování (90 dní)` → `portal_storage_type_occupancy`** to drive landlords to the new page.
- When no type is selected, the page stays as today (placeholder card).

#### Controller change — `src/Controller/Portal/StorageListController.php`

```php
$rentalViews = [];
if (null !== $selectedStorageType) {
    $now = $this->clock->now();   // inject ClockInterface
    $rentalViews = $this->occupancyService->currentViews($storages, $now);
}
```

Pass `rentalViews` to the template. Inject `ClockInterface` and `StorageOccupancyService`.

### 8. Modifications — `templates/portal/storage_type/list.html.twig` (admin only)

- Add columns after `Cena/měsíc`: `Celkem`, `Obsazeno`, `Volných`, `Nejbližší volný`.
- For each storage type row, look up the matching row in `placeOverview.rows` (passed from controller). Render counts; `Nejbližší volný` = `row.nextFreeingDate|date('d.m.Y')` or "—".
- Replace the action cell's "Upravit / Smazat" with `Plánování · Upravit · Smazat`. The new "Plánování" link goes to `portal_storage_type_occupancy`.

#### Controller change — `src/Controller/Portal/StorageTypeListController.php`

Inject `QueryBus` and dispatch `GetPlaceTypeOccupancyOverview($place->id, null)` (admin already; landlordId always null here). Pass the result rows keyed by storage type id (`$rowsById[$row->storageType->id->toRfc4122()] = $row`) to the template.

### 9. Modifications — `templates/portal/place/detail.html.twig`

Replace the existing "Volné sklady (per typ)" block (lines 335–349) with **"Obsazenost typů"**:

- Heading: "Obsazenost typů"
- For each row from `GetPlaceTypeOccupancyOverview`:
  - left side: type name (link to `portal_storage_type_occupancy`) + small subtitle `{{ row.totalCount }} skladů · {{ row.occupiedCount }} obsazeno · {{ row.availableCount }} volných {% if row.blockedCount > 0 %} · {{ row.blockedCount }} blokovaných{% endif %}`
  - right side:
    - if `row.availableCount > 0`: green badge `{{ row.availableCount }} volných`
    - else: red badge `Vše obsazeno`
    - second line (gray, smaller): `{% if row.nextFreeingDate %}Nejbližší uvolnění do {{ row.nextFreeingDate|date('d.m.Y') }}{% endif %}`
- Show the block only when at least one storage type exists at the place (mirrors current `freeByType|length > 0`).

#### Controller change — `src/Controller/Portal/PlaceDetailController.php`

Replace the existing `findFreeCountByTypeAtPlace(...)` call with `$queryBus->handle(new GetPlaceTypeOccupancyOverview($place->id, $isAdmin ? null : $user->id))` and pass `placeOverview` to the template. The repo method `findFreeCountByTypeAtPlace` is removed if no other caller uses it (`grep -r findFreeCountByTypeAtPlace src/ templates/` to verify before deletion); otherwise leave it in place.

### 10. Calendar enhancements — `src/Controller/Portal/CalendarController.php` + `templates/portal/calendar/index.html.twig`

#### Controller

Add a `view` query param: `month` (default) or `timeline`. When `view=timeline`:

- Build `spans = $occupancyService->spansInRange($storages, startOfMonth, endOfMonth)`.
- Pass `spans`, the `daysInMonth` array, and `view` to the template.

When `view=month` (default behaviour), additionally compute per-day deltas to power the badges:

- `endingToday[Y-m-d] = count of spans whose endDate equals that day`
- `startingToday[Y-m-d] = count of spans whose startDate equals that day`
- `dayDetails[Y-m-d] = array<{kind, storage, tenantName, startDate, endDate}>` — kept compact, only currently-occupied storages on that day + ones starting + ones ending (capped at, say, 50 per day; if more, render "…a dalších N").

These derive from `spansInRange` over the same month window — so the controller calls `spansInRange` regardless of `view`, then conditionally renders.

Inject `StorageOccupancyService`. Drop the local `getCzechMonthName` if (and only if) we already have a Twig date filter equivalent — otherwise leave it; cosmetic.

#### Template — `templates/portal/calendar/index.html.twig`

1. **View toggle** — small segmented buttons in the header bar (line 75 area, opposite the legend):

   ```twig
   <a href="{{ path('portal_calendar', filterParams|merge({view: 'month'})) }}"
      class="btn btn-sm {{ view == 'month' ? 'btn-primary' : 'btn-ghost' }}">Měsíc</a>
   <a href="{{ path('portal_calendar', filterParams|merge({view: 'timeline'})) }}"
      class="btn btn-sm {{ view == 'timeline' ? 'btn-primary' : 'btn-ghost' }}">Časová osa</a>
   ```

2. **Month view** — keep existing day-cell rendering (lines 110–148). Append two badges below the existing percent badge:
   - `{% if endingToday[dateKey] ?? 0 > 0 %}<span class="badge badge-warning text-xs">{{ endingToday[dateKey] }} končí</span>{% endif %}`
   - `{% if startingToday[dateKey] ?? 0 > 0 %}<span class="badge badge-success text-xs">{{ startingToday[dateKey] }} začíná</span>{% endif %}`

   Wrap each day cell in a `<details>` element (or attach a Stimulus toggle, implementer's call — `<details>` is zero-JS):

   ```twig
   <details class="…">
     <summary class="cursor-pointer …">{# existing day cell content #}</summary>
     {% if dayDetails[dateKey] is defined and dayDetails[dateKey] is not empty %}
       <div class="absolute z-10 mt-1 w-72 bg-white shadow-lg rounded p-3 text-sm">
         {# 1. Sklady končící today #}
         {# 2. Sklady začínající today #}
         {# 3. Aktuálně obsazené (storage č. + nájemce + do dd.mm.yyyy) #}
       </div>
     {% endif %}
   </details>
   ```

   The `<details>` open/close gives free expand-on-click without Stimulus. Renderer caps each section at 5 entries with "…a dalších N".

3. **Timeline view** — replaces the month grid when `view=timeline`. CSS-grid Gantt:

   ```twig
   {% set days = (endOfMonth.diff(startOfMonth).days + 1) %}
   <div class="overflow-x-auto">
     <div class="grid" style="grid-template-columns: 160px repeat({{ days }}, minmax(20px, 1fr)); gap: 1px;">
       {# Header row: storage label cell, then day numbers 1..N #}
       <div></div>
       {% for d in 1..days %}
         <div class="text-xs text-center text-gray-500 py-1">{{ d }}</div>
       {% endfor %}

       {# One row per storage #}
       {% for storage in storages %}
         <div class="py-1 pr-2 text-sm text-gray-900 truncate">
           {{ storage.number }} <span class="text-gray-500 text-xs">({{ storage.storageType.name }})</span>
         </div>
         {% set storageSpans = spans[storage.id.toRfc4122] ?? [] %}
         {# Render N day cells, color based on whether any span covers that day #}
         {% for d in 1..days %}
           {% set dayDate = startOfMonth.modify('+' ~ (d - 1) ~ ' days') %}
           {% set covering = storageSpans|filter(s => s.startDate <= dayDate and (s.endDate is null or s.endDate >= dayDate))|first %}
           <div class="h-6 {% if covering %}{{ covering.kind.value == 'block' ? 'bg-gray-300' : 'bg-red-400' }}{% else %}bg-green-100{% endif %}"
                title="{% if covering %}{{ covering.tenantName ?? 'Blokováno' }}{% if covering.endDate %} (do {{ covering.endDate|date('d.m.Y') }}){% else %} (neomezeně){% endif %}{% else %}Volný{% endif %}">
           </div>
         {% endfor %}
       {% endfor %}
     </div>
   </div>
   ```

   Hover (`title` attribute) is sufficient for v1; a richer tooltip is out of scope.

   Today's column gets a vertical accent (`outline: 2px solid var(--color-accent); outline-offset: -1px;`) — implementer adds via inline style or a small `today-col` class.

4. **Storage list table below calendar** (lines 162–212) — add columns `Pronajato OD` and `Pronajato DO` (using the "do kdy" rule). Drop `Aktuální stav` (redundant with `Stav` badge), or keep — implementer's call; my recommendation: drop, freeing space for the new dates.

### 11. Fixtures top-up — `fixtures/ContractFixtures.php` + `OrderFixtures.php`

To make the new surfaces meaningful in `db:reset`:

- For `landlord@`'s primary place, ensure ≥ 3 storages of the same type with **different** `endDate`s scattered over the next 90 days (e.g. +5 d, +20 d, +60 d) so the "Plánování typu" page renders a varied timeline.
- Ensure ≥ 1 contract whose `endDate` lands inside the current calendar month at the MockClock baseline (`2025-06-15`) — for the "X končí" badge to render in tests.
- Ensure ≥ 1 contract with `terminatesAt` set, so the "ukončuje se" warning icon renders.
- Ensure ≥ 1 unlimited (`RentalType::UNLIMITED`, `endDate IS NULL`) active contract → "neomezeně" rendering.
- Ensure ≥ 1 storage with active `StorageUnavailability` → "Blokováno" badge + gray timeline segment.

Don't break what spec 023 / 024 fixtures rely on; top-up only.

### 12. Tests

#### Unit — `tests/Unit/Service/Storage/StorageOccupancyServiceTest.php` (new)

Cases (use MockClock baseline `2025-06-15 12:00:00 UTC`):

- Free storage → `currentViews` returns view with `isFree = true`, `rentedUntil = null`, `availableFrom = null`.
- Storage with active fixed-term contract `[2025-06-01, 2025-08-01]` → `rentedFrom`, `rentedUntil = 2025-08-01`, `availableFrom = 2025-08-02`.
- Storage with active unlimited contract → `rentedUntil = null`, `isUnlimited = true`.
- Storage with `Contract.terminatesAt = 2025-07-15` overriding `endDate = 2025-12-31` → `rentedUntil = 2025-07-15`, `isTerminating = true`.
- Storage with paid order but no contract yet → `currentOrder` set, `currentContract` null, `rentedUntil = order.endDate`.
- Storage with active manual block → `blockedBy` set, `currentContract`/`currentOrder` null.
- `nextBookedFrom`: storage occupied until `2025-08-01` AND has a future order/contract starting `2025-09-01` → `nextBookedFrom = 2025-09-01`.
- `spansInRange`: returns all overlapping windows; nullable endDate stays null.

Mock the three repositories or use real ones with in-memory ORM — implementer's call. Pure service unit tests (no DB) are preferred for speed; if hard to set up due to entity constructors, fall back to integration.

#### Integration — `tests/Integration/Repository/ContractRepositoryTest.php`

Test `findActiveByStorages`, `findOverlappingByStorages`, `findNextStartByStorages`.

#### Integration — `tests/Integration/Repository/OrderRepositoryTest.php`

Test `findNextStartByStorages`.

#### Integration — `tests/Integration/Controller/Portal/StorageTypeOccupancyControllerTest.php` (new)

- `tenant@` → 403.
- `landlord2@` for a place where they have no owned storage and no `PlaceAccess` → 403.
- `landlord@` → 200, response body contains `Plánování obsazenosti`, `{{ storageType.name }}`, KPI labels, at least one row from the rentals table, at least one storage row in the timeline grid.
- `admin@` → 200, sees all storages of the type at the place (including those owned by `landlord2@`).
- `?show=occupied` → only occupied rows visible (assert via row count or body assertions).

#### Integration — `tests/Integration/Controller/Portal/CalendarControllerTest.php`

- Default GET → `view=month`, body contains `Měsíc` / `Časová osa` toggle, body contains the legend and the existing day grid.
- GET `?view=timeline` → body contains the Gantt grid markup (assert by checking for a specific class hook like `data-test="timeline-grid"` — implementer adds it).
- For a fixture day where a contract ends, the badge `končí` is present in the day cell.
- `<details>` per-day content is in the response (server-rendered, no JS).

#### Manual walk-through (Czech, full diacritics)

After `docker compose exec web composer db:reset`:

- Login `landlord@` → `/portal/calendar`. Verify `Měsíc | Časová osa` toggle. In Měsíc, day with finishing contract shows `1 končí`. Click day → expandable detail listing the storage + nájemce + `do dd.mm.yyyy`.
- Click `Časová osa` → see Gantt grid; today's column highlighted; hovering a red bar shows nájemce + dates; gray bars for blocks; green for free.
- Open any place → "Obsazenost typů" panel shows per-type counts + `Nejbližší uvolnění do dd.mm.yyyy` + link.
- Click "Plánování" on a type → land on `/portal/places/{placeId}/storage-types/{id}/obsazenost`. KPI tiles correct. Sortable table shows `Pronajato DO`, `neomezeně` badge for unlimited contract, `ukončuje se` warning for terminating one. 90-day strip renders.
- Storage list with type selected → shows `Nájemce / Pronajato OD / DO / Příští volný` columns + `Plánování (90 dní)` button in header.
- Login `admin@` → Storage type list at any place: new columns `Celkem · Obsazeno · Volných · Nejbližší volný` + `Plánování` action link.
- Login `tenant@` → none of the new pages reachable; `/portal/places/.../obsazenost` returns 403.

## Acceptance

- [ ] `docker compose exec web composer quality` green.
- [ ] All new classes use `final readonly` (value objects / queries / results / handler / service).
- [ ] `StorageOccupancyService::currentViews` and `spansInRange` execute at most three SQL queries each (verify by enabling Doctrine query logger in a test or counting via `getSQLLogger`). No N+1 over storages.
- [ ] "Do kdy" rendering rule (Goal table) is applied identically across: storage list, storage type occupancy table, place detail "Obsazenost typů", calendar per-day detail, calendar Gantt tooltip, calendar storage list.
- [ ] `Contract.terminatesAt` correctly takes precedence over `Contract.endDate` in every surface.
- [ ] Unlimited active rentals (`endDate IS NULL`, recurring) display as `neomezeně` (with ∞ icon) — never as blank or "—".
- [ ] `/portal/places/{placeId}/storage-types/{id}/obsazenost` is reachable via: place detail "Obsazenost typů" row, storage type list (admin) "Plánování" link, storage list "Plánování (90 dní)" header button.
- [ ] Calendar `view=month` (default) and `view=timeline` render the same set of storages (filters honoured identically).
- [ ] Manual walk-through above passes for `admin@`, `landlord@`, `landlord2@`, `tenant@`.
- [ ] `BACKLOG.md` row added under #027.

## Out of scope

- **Real-time updates / Mercure / WebSockets** on the calendar Gantt. Page reload is fine; rentals don't change minute-to-minute.
- **Drag-to-create unavailability** in the Gantt timeline. Useful, but a separate UX increment; we keep the existing `/portal/unavailabilities/create` form as the entry point.
- **Print-friendly / PDF export** of the per-type planning page. Defer; the admin can use the browser's print stylesheet for now.
- **Timeline view longer than the current calendar month** in `/portal/calendar`. The per-type page covers 90 days; calendar Gantt stays month-scoped to mirror the month-grid filter behaviour.
- **Customer-facing surfacing** ("kdy bude tenhle sklad volný?" on `/pobocka/{id}`). Customer-facing is excluded; this is operations UX for landlords/admins.
- **Heatmap overlay on the place map (canvas)** showing occupancy per storage rectangle. Tempting but a separate spec; the Gantt + per-type page already answer the planning question.
- **Bulk operations from the per-type page** (e.g. select N storages, generate a bulk reservation). Not requested.
- **Search / pagination** on the per-type planning page. A single type at a single place is bounded; a vertical scroll is fine.
- **Removal of `Storage.status`** as a derived field in favour of computed live state. The coarse status remains as today; we just don't depend on it for the new surfaces.
- **Customer (tenant) view of "do kdy mám sklad"** in user portal. Already shown on `/portal/objednavky/{id}` — not part of this spec.

## Open questions

None — proceed.
