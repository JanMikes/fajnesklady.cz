# 024 — Per-place dashboard for landlords and admins

**Status:** done
**Type:** feature (operations UX + financial visibility, per-place scope)
**Scope:** large (~22 files: 2 new queries × 3 files + repo additions on Storage / Contract / Payment / Order + OverdueChecker addition + RevenueChart prop + controller refactor + template refactor + admin places list link + fixtures top-up + tests)
**Depends on:** 022 (storage access codes — health check uses `Place::$storageCodesEnabled`), 023 (overdue summariser — extended here per place)

## Problem

Today both landlords and admins land on a **global** `/portal/dashboard` (`portal/dashboard_landlord.html.twig`, `portal/dashboard_admin.html.twig`) and then drill into `/portal/places/{id}` — but the place page is a pure navigation hub (Sklady / Typy skladů / Přístupové kódy / Editor mapy / Upravit / Nebezpečná zóna) with **zero operational data**. A landlord with three places can't tell at a glance which one is empty, which has expiring contracts next week, or how last month's revenue per place compared. An admin can't tell which place is currently leaking debtors. The global dashboard mixes everything together and the hub page tells you nothing — the daily-ops loop "open place X → see what needs my attention there" is impossible.

Two adjacent gaps surfaced while specing this:

- The hub-style cards on `/portal/places/{id}` work as a **setup wizard** for a brand-new place (great user feedback) — replacing it with a pure dashboard would lose that affordance.
- The admin places list (`/portal/admin/places` → `templates/admin/place/list.html.twig`) only links Edit + Editor, not the place detail page. Admins reach a hub by accident, not by design.

## Goal

Open `/portal/places/{id}` and instantly know the operational state of THIS place:

1. **Setup-health alerts at the top** (only when problems): missing provozní řád, missing mapa, no storage types, storageCodesEnabled but nothing generated. Acts as a checklist for fresh places; disappears once configured.
2. **KPI tiles** (4 cards): obsazenost · tržby minulý měsíc · očekávané MRR · aktivní smlouvy. **Landlord sees only their own storages here**; admin sees the whole place.
3. **Po splatnosti banner** (admin only, only when > 0 at this place): count + Kč + link to `/portal/admin/po-splatnosti`.
4. **Brzy končící smlouvy** (≤ 30 days): list of contracts at this place with `endDate` upcoming. Same scope rule.
5. **Co se chystá** (≤ 30 days): orders in `RESERVED` / `AWAITING_PAYMENT` / `PAID` whose `startDate` is upcoming. Useful "you have a new tenant moving in".
6. **Posledních 5 objednávek** at this place. Same scope rule.
7. **Volné sklady** (per storage type): compact table with available count per type. Same scope rule.
8. **Tržby — chart (12 měsíců)** at this place via the existing `RevenueChart` Live Component, extended with a `placeId` prop. Same scope rule.
9. **Správa místa** (the existing hub): Sklady · Typy skladů · Přístupové kódy · Editor mapy · Upravit · Nebezpečná zóna. Kept below the dashboard so navigation stays one click away and a fresh place still has the wizard feel.
10. **Co-owner disclaimer** for landlords on a multi-owner place: small grey line "Vidíte pouze své sklady. Toto místo má i další pronajímatele." — only when ≥1 storage at this place is owned by someone other than the current user.
11. **`/portal/admin/places`** gains a "Detail" link in the row actions (alongside Upravit / Editor) so admins reach the dashboard intentionally.

## Context (current state)

### Where things live

- **Route + controller**: `src/Controller/Portal/PlaceDetailController.php` at `/portal/places/{id}` (route name `portal_places_detail`). Single-action `__invoke`. Granted to `ROLE_LANDLORD` (admins inherit via role hierarchy).
- **Template (the hub)**: `templates/portal/place/detail.html.twig` — header card + 5 navigation cards inside `<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">` + admin-only "Nebezpečná zóna" delete section.
- **Voter**: `App\Service\Security\PlaceVoter::VIEW` is already enforced at line 35 of the controller. Landlords without `PlaceAccess` are denied; admins always pass.
- **Admin places list**: `src/Controller/Admin/AdminPlaceListController.php` → `templates/admin/place/list.html.twig`. Row actions are Upravit + Editor only — no Detail link. **Verified** by reading the template (this spec, the read above).

### Existing dashboard plumbing we mirror

- Aggregates query pattern: `src/Query/GetLandlordDashboardStats*.php` and `GetDashboardStats*.php`. Single `__invoke` calls many repo methods, returns a `final readonly` result DTO with int/float fields.
- Recent-orders pattern: controller calls `OrderRepository::findByLandlord($landlord, 5)` directly, the query result holds aggregates only. We follow the same split (aggregates in query, lists in controller via repos).
- Revenue chart: `src/Twig/Components/RevenueChart.php` is a Live Component with `?string $landlordId` LiveProp; sends `GetLandlordRevenueChart` if set, else `GetAdminRevenueChart`. Pure switch — easy to extend with a `placeId` prop.
- Overdue summary: `src/Service/Overdue/OverdueChecker::summarise(now)` returns `OverdueSummary` (count, totalAmount, top[]). We add `summariseForPlace(now, Place)` mirroring the same shape.

### Repository methods that already do half the work

- `StorageRepository::countByPlace(Place)` (line 277), `countByOwnerAndPlace(User, Place)` (line 289), `findByPlace(Place)` (line 119), `findByOwnerAndPlace(User, Place)` (line 202). Missing: per-place split into available / occupied / blocked, with optional owner.
- `OrderRepository::findByLandlord(User, ?int)` (line 176). Missing: `findByPlace(Place, int, ?User)`, `findUpcomingByPlace(Place, int, \DateTimeImmutable, ?User)`.
- `ContractRepository::countActiveRecurringByLandlord(User)`, `sumExpectedRecurringByLandlord(User)`, `findExpiringWithinDays(int, \DateTimeImmutable)`, `findWithPaymentIssues(\DateTimeImmutable)`. Missing: per-place variants.
- `PaymentRepository::sumByStorageOwnerAndPeriod(User, int, int)` (line 156), `getMonthlyRevenueByLandlord(User, int, \DateTimeImmutable)` (line 199). Missing: per-place + (optional) owner variants.

### Place ownership model — important

`Place` has **no** `owner` field (verified — re-read `src/Entity/Place.php` lines 1–80). Ownership lives at `Storage::owner` (nullable). One place can host storages owned by multiple landlords. `PlaceAccess` separately grants management-only access. So:

- Landlord scope = "all `Storage` rows where `s.place = :place AND s.owner = :landlord AND s.deletedAt IS NULL`".
- Admin scope = "all `Storage` rows where `s.place = :place AND s.deletedAt IS NULL`".
- Co-owner detection = "exists at least one storage at this place owned by someone other than the current user (and whose owner IS NOT NULL)".

### Conventions worth restating for this spec

- `final readonly` for queries / results / DTOs.
- `EntityManager` composition; never `flush()` outside fixtures.
- Single-action `__invoke` controllers; `final` modifier; route at class level.
- `private(set)` / `public private(set)` property hooks on entities; no setters.
- `MockClock` pinned at `2025-06-15 12:00:00 UTC` in tests. Never `new \DateTimeImmutable()`.
- Czech UI text MUST have full diacritics (memory rule).

## Architecture

```
                ┌─────────────────────────────────────────────┐
                │  StorageRepository (extended)               │
                │  + countAtPlace(Place, ?User)               │
                │  + countOccupiedAtPlace(Place, ?User)       │
                │  + countAvailableAtPlace(Place, ?User)      │
                │  + countBlockedAtPlace(Place, ?User)        │
                │  + hasCoOwners(Place, User)                 │
                │  ContractRepository (extended)              │
                │  + countActiveRecurringAtPlace(Place,?User) │
                │  + sumExpectedRecurringAtPlace(Place,?User) │
                │  + findExpiringWithinDaysAtPlace(...)       │
                │  PaymentRepository (extended)               │
                │  + sumAtPlaceAndPeriod(Place,y,m,?User)     │
                │  + getMonthlyRevenueAtPlace(Place,n,now,?U) │
                │  OrderRepository (extended)                 │
                │  + findRecentAtPlace(Place, n, ?User)       │
                │  + findUpcomingAtPlace(Place, days, now,?U) │
                │  OverdueChecker (extended)                  │
                │  + summariseForPlace(now, Place)            │
                │  + filterOverdueAtPlace(now, Place)         │
                └─────────────────────────────────────────────┘
                                     ▲
                                     │
                ┌─────────────────────────────────────────────┐
                │  GetPlaceDashboardStats (query)             │
                │    Uuid placeId, ?Uuid landlordId           │
                │    landlordId=null → admin (whole place)    │
                │    landlordId=set  → only that owner's      │
                │  GetPlaceRevenueChart (query)               │
                │    Uuid placeId, ?Uuid landlordId, int n    │
                └─────────────────────────────────────────────┘
                                     ▲
                                     │
        ┌────────────────────────────┴───────────────────┐
        ▼                                                ▼
  PlaceDetailController                       RevenueChart (Live)
  (fetches stats + lists,                     + ?string placeId prop
   determines scope,                          owner+place precedence:
   renders dashboard + hub)                   placeId → place chart
                                              landlordId → landlord chart
                                              else → admin chart
```

## Requirements

### 1. `GetPlaceDashboardStats` query, handler, result

#### `src/Query/GetPlaceDashboardStats.php`

```php
namespace App\Query;

use Symfony\Component\Uid\Uuid;

/**
 * @implements QueryMessage<GetPlaceDashboardStatsResult>
 */
final readonly class GetPlaceDashboardStats implements QueryMessage
{
    public function __construct(
        public Uuid $placeId,
        public ?Uuid $landlordId = null, // null = admin (whole place)
    ) {}
}
```

#### `src/Query/GetPlaceDashboardStatsResult.php`

```php
namespace App\Query;

use App\Value\OverdueContractView;

final readonly class GetPlaceDashboardStatsResult
{
    /**
     * @param OverdueContractView[] $overdueTop
     */
    public function __construct(
        // capacity — scope-aware
        public int $totalStorages,
        public int $occupiedStorages,
        public int $availableStorages,
        public int $blockedStorages,
        public float $occupancyRate,
        // revenue — scope-aware
        public int $lastMonthRevenue,
        public int $expectedThisMonthRevenue,
        public int $activeRecurringContracts,
        // overdue — admin only (always 0/[] when landlordId is set)
        public int $overdueCount,
        public int $overdueAmount,
        public array $overdueTop,
        // setup-health — admin only (always false when landlordId is set)
        public bool $missingOperatingRules,
        public bool $missingMap,
        public bool $missingStorageTypes,
        public bool $missingLockCodes, // storageCodesEnabled && no storage at place has a code
        // co-owner detection — landlord only (always false for admin)
        public bool $hasCoOwners,
    ) {}
}
```

#### `src/Query/GetPlaceDashboardStatsQuery.php`

```php
namespace App\Query;

use App\Repository\ContractRepository;
use App\Repository\PaymentRepository;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Repository\UserRepository;
use App\Service\Overdue\OverdueChecker;
use App\Value\OverdueSummary;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GetPlaceDashboardStatsQuery
{
    public function __construct(
        private PlaceRepository $placeRepository,
        private UserRepository $userRepository,
        private StorageRepository $storageRepository,
        private StorageTypeRepository $storageTypeRepository,
        private ContractRepository $contractRepository,
        private PaymentRepository $paymentRepository,
        private OverdueChecker $overdueChecker,
        private ClockInterface $clock,
    ) {}

    public function __invoke(GetPlaceDashboardStats $query): GetPlaceDashboardStatsResult
    {
        $place = $this->placeRepository->get($query->placeId);
        $owner = null !== $query->landlordId
            ? $this->userRepository->get($query->landlordId)
            : null;
        $now = $this->clock->now();

        $totalStorages = $this->storageRepository->countAtPlace($place, $owner);
        $occupiedStorages = $this->storageRepository->countOccupiedAtPlace($place, $owner);
        $availableStorages = $this->storageRepository->countAvailableAtPlace($place, $owner);
        $blockedStorages = $this->storageRepository->countBlockedAtPlace($place, $owner);
        $occupancyRate = $totalStorages > 0
            ? ($occupiedStorages / $totalStorages) * 100
            : 0.0;

        $lastMonth = $now->modify('first day of last month');
        $lastMonthRevenue = $this->paymentRepository->sumAtPlaceAndPeriod(
            $place,
            (int) $lastMonth->format('Y'),
            (int) $lastMonth->format('n'),
            $owner,
        );

        $expectedThisMonthRevenue = $this->contractRepository
            ->sumExpectedRecurringAtPlace($place, $owner);
        $activeRecurringContracts = $this->contractRepository
            ->countActiveRecurringAtPlace($place, $owner);

        // Admin-only blocks
        $overdue = null === $owner
            ? $this->overdueChecker->summariseForPlace($now, $place)
            : new OverdueSummary(count: 0, totalAmount: 0, top: []);

        $missingOperatingRules = null === $owner && null === $place->operatingRulesPath;
        $missingMap            = null === $owner && null === $place->mapImagePath;
        $missingStorageTypes   = null === $owner
            && 0 === $this->storageTypeRepository->countByPlace($place);
        $missingLockCodes      = null === $owner
            && $place->storageCodesEnabled
            && [] === $this->storageRepository->findActiveLockCodesByPlace($place);

        $hasCoOwners = null !== $owner
            && $this->storageRepository->hasCoOwners($place, $owner);

        return new GetPlaceDashboardStatsResult(
            totalStorages: $totalStorages,
            occupiedStorages: $occupiedStorages,
            availableStorages: $availableStorages,
            blockedStorages: $blockedStorages,
            occupancyRate: $occupancyRate,
            lastMonthRevenue: $lastMonthRevenue,
            expectedThisMonthRevenue: $expectedThisMonthRevenue,
            activeRecurringContracts: $activeRecurringContracts,
            overdueCount: $overdue->count,
            overdueAmount: $overdue->totalAmount,
            overdueTop: $overdue->top,
            missingOperatingRules: $missingOperatingRules,
            missingMap: $missingMap,
            missingStorageTypes: $missingStorageTypes,
            missingLockCodes: $missingLockCodes,
            hasCoOwners: $hasCoOwners,
        );
    }
}
```

### 2. `GetPlaceRevenueChart` query, handler, result

Mirror `GetLandlordRevenueChart*` line for line — only the WHERE adds `s.place = :place` and (optionally) `s.owner = :owner`.

#### `src/Query/GetPlaceRevenueChart.php`

```php
namespace App\Query;

use Symfony\Component\Uid\Uuid;

/**
 * @implements QueryMessage<GetPlaceRevenueChartResult>
 */
final readonly class GetPlaceRevenueChart implements QueryMessage
{
    public function __construct(
        public Uuid $placeId,
        public ?Uuid $landlordId = null,
        public int $months = 12,
    ) {}
}
```

#### `src/Query/GetPlaceRevenueChartResult.php`

Identical shape to `GetLandlordRevenueChartResult` — `string[] $labels` + `int[] $revenues` (halíře).

#### `src/Query/GetPlaceRevenueChartQuery.php`

Mirror `GetLandlordRevenueChartQuery` (including the same `MONTH_NAMES` table and the `fillMissingMonths` helper — copy them). Calls `PaymentRepository::getMonthlyRevenueAtPlace($place, $months, $now, $owner)` instead of `getMonthlyRevenueByLandlord`. Resolves `Place` via `PlaceRepository::get($query->placeId)` and `User` via `UserRepository::get($query->landlordId)` when set.

The duplication of `MONTH_NAMES` + `fillMissingMonths` across two handlers is bounded; a shared helper trait/service is a cosmetic refactor we don't take on here.

### 3. Repository additions

#### `src/Repository/StorageRepository.php`

Add an internal helper to share the "scope by place + optional owner" boilerplate across the four count methods:

```php
private function scopedAtPlace(Place $place, ?User $owner): \Doctrine\ORM\QueryBuilder
{
    $qb = $this->entityManager->createQueryBuilder()
        ->select('COUNT(s.id)')
        ->from(Storage::class, 's')
        ->where('s.place = :place')
        ->andWhere('s.deletedAt IS NULL')
        ->setParameter('place', $place);

    if (null !== $owner) {
        $qb->andWhere('s.owner = :owner')->setParameter('owner', $owner);
    }

    return $qb;
}

public function countAtPlace(Place $place, ?User $owner): int
{
    return (int) $this->scopedAtPlace($place, $owner)->getQuery()->getSingleScalarResult();
}

public function countOccupiedAtPlace(Place $place, ?User $owner): int
{
    return (int) $this->scopedAtPlace($place, $owner)
        ->andWhere('s.status = :status')
        ->setParameter('status', StorageStatus::OCCUPIED)
        ->getQuery()->getSingleScalarResult();
}

public function countAvailableAtPlace(Place $place, ?User $owner): int
{
    return (int) $this->scopedAtPlace($place, $owner)
        ->andWhere('s.status = :status')
        ->setParameter('status', StorageStatus::AVAILABLE)
        ->getQuery()->getSingleScalarResult();
}

public function countBlockedAtPlace(Place $place, ?User $owner): int
{
    return (int) $this->scopedAtPlace($place, $owner)
        ->andWhere('s.status = :status')
        ->setParameter('status', StorageStatus::MANUALLY_UNAVAILABLE)
        ->getQuery()->getSingleScalarResult();
}

/**
 * True iff there's ≥1 non-deleted storage at $place owned by someone other
 * than $excludeOwner (and whose owner is set). Used to surface a co-owner
 * disclaimer on the landlord dashboard.
 */
public function hasCoOwners(Place $place, User $excludeOwner): bool
{
    $count = (int) $this->entityManager->createQueryBuilder()
        ->select('COUNT(s.id)')
        ->from(Storage::class, 's')
        ->where('s.place = :place')
        ->andWhere('s.deletedAt IS NULL')
        ->andWhere('s.owner IS NOT NULL')
        ->andWhere('s.owner != :owner')
        ->setParameter('place', $place)
        ->setParameter('owner', $excludeOwner)
        ->getQuery()
        ->getSingleScalarResult();

    return $count > 0;
}

/**
 * Per–storage-type free count at a place, scoped to an optional owner.
 *
 * @return array<array{storageType: StorageType, freeCount: int}>
 */
public function findFreeCountByTypeAtPlace(Place $place, ?User $owner): array
{
    $qb = $this->entityManager->createQueryBuilder()
        ->select('IDENTITY(s.storageType) AS typeId, COUNT(s.id) AS freeCount')
        ->from(Storage::class, 's')
        ->where('s.place = :place')
        ->andWhere('s.deletedAt IS NULL')
        ->andWhere('s.status = :status')
        ->setParameter('place', $place)
        ->setParameter('status', StorageStatus::AVAILABLE)
        ->groupBy('s.storageType');

    if (null !== $owner) {
        $qb->andWhere('s.owner = :owner')->setParameter('owner', $owner);
    }

    /** @var array<int, array{typeId: string, freeCount: int|string}> $rows */
    $rows = $qb->getQuery()->getArrayResult();

    if ([] === $rows) {
        return [];
    }

    // Resolve StorageType refs in one round trip — no N+1.
    $typeIds = array_map(static fn (array $r): string => (string) $r['typeId'], $rows);
    /** @var StorageType[] $types */
    $types = $this->entityManager->createQueryBuilder()
        ->select('st')->from(StorageType::class, 'st')
        ->where('st.id IN (:ids)')
        ->setParameter('ids', $typeIds)
        ->getQuery()->getResult();

    $byId = [];
    foreach ($types as $t) {
        $byId[(string) $t->id] = $t;
    }

    $result = [];
    foreach ($rows as $r) {
        $key = (string) $r['typeId'];
        if (!isset($byId[$key])) {
            continue; // tombstoned StorageType — skip
        }
        $result[] = ['storageType' => $byId[$key], 'freeCount' => (int) $r['freeCount']];
    }

    usort($result, static fn (array $a, array $b): int =>
        strnatcmp($a['storageType']->name, $b['storageType']->name));

    return $result;
}
```

`findActiveLockCodesByPlace(Place)` already exists at `StorageRepository.php:152` — reuse it for the `missingLockCodes` health check. No change needed there.

#### `src/Repository/ContractRepository.php`

```php
public function countActiveRecurringAtPlace(Place $place, ?User $owner): int
{
    $qb = $this->entityManager->createQueryBuilder()
        ->select('COUNT(c.id)')
        ->from(Contract::class, 'c')
        ->join('c.storage', 's')
        ->where('s.place = :place')
        ->andWhere('c.goPayParentPaymentId IS NOT NULL')
        ->andWhere('c.terminatedAt IS NULL')
        ->setParameter('place', $place);

    if (null !== $owner) {
        $qb->andWhere('s.owner = :owner')->setParameter('owner', $owner);
    }

    return (int) $qb->getQuery()->getSingleScalarResult();
}

public function sumExpectedRecurringAtPlace(Place $place, ?User $owner): int
{
    $qb = $this->entityManager->createQueryBuilder()
        ->select('SUM(o.firstPaymentPrice)')
        ->from(Contract::class, 'c')
        ->join('c.storage', 's')
        ->join('c.order', 'o')
        ->where('s.place = :place')
        ->andWhere('c.goPayParentPaymentId IS NOT NULL')
        ->andWhere('c.terminatedAt IS NULL')
        ->setParameter('place', $place);

    if (null !== $owner) {
        $qb->andWhere('s.owner = :owner')->setParameter('owner', $owner);
    }

    return (int) ($qb->getQuery()->getSingleScalarResult() ?? 0);
}

/**
 * Active contracts at $place whose endDate falls within $days from $now.
 * Same semantics as findExpiringWithinDays (line 118) but place-scoped.
 *
 * @return Contract[]
 */
public function findExpiringWithinDaysAtPlace(
    int $days,
    \DateTimeImmutable $now,
    Place $place,
    ?User $owner,
): array {
    $futureDate = $now->modify("+{$days} days");

    $qb = $this->entityManager->createQueryBuilder()
        ->select('c')
        ->from(Contract::class, 'c')
        ->join('c.storage', 's')
        ->where('s.place = :place')
        ->andWhere('c.terminatedAt IS NULL')
        ->andWhere('c.endDate IS NOT NULL')
        ->andWhere('c.endDate >= :now')
        ->andWhere('c.endDate <= :futureDate')
        ->setParameter('place', $place)
        ->setParameter('now', $now)
        ->setParameter('futureDate', $futureDate)
        ->orderBy('c.endDate', 'ASC');

    if (null !== $owner) {
        $qb->andWhere('s.owner = :owner')->setParameter('owner', $owner);
    }

    return $qb->getQuery()->getResult();
}
```

#### `src/Repository/PaymentRepository.php`

```php
public function sumAtPlaceAndPeriod(Place $place, int $year, int $month, ?User $owner): int
{
    $startDate = new \DateTimeImmutable(sprintf('%d-%02d-01 00:00:00', $year, $month));
    $endDate = $startDate->modify('first day of next month');

    $qb = $this->entityManager->createQueryBuilder()
        ->select('SUM(p.amount)')
        ->from(Payment::class, 'p')
        ->join('p.storage', 's')
        ->where('s.place = :place')
        ->andWhere('p.paidAt >= :startDate')
        ->andWhere('p.paidAt < :endDate')
        ->setParameter('place', $place)
        ->setParameter('startDate', $startDate)
        ->setParameter('endDate', $endDate);

    if (null !== $owner) {
        $qb->andWhere('s.owner = :owner')->setParameter('owner', $owner);
    }

    return (int) ($qb->getQuery()->getSingleScalarResult() ?? 0);
}

/**
 * @return array<array{year: int, month: int, total: int}>
 */
public function getMonthlyRevenueAtPlace(
    Place $place,
    int $months,
    \DateTimeImmutable $now,
    ?User $owner,
): array {
    $startDate = $now->modify("-{$months} months")->modify('first day of this month midnight');
    $endDate = $now->modify('first day of next month midnight');

    $qb = $this->entityManager->createQueryBuilder()
        ->select('p')
        ->from(Payment::class, 'p')
        ->join('p.storage', 's')
        ->where('s.place = :place')
        ->andWhere('p.paidAt >= :startDate')
        ->andWhere('p.paidAt < :endDate')
        ->setParameter('place', $place)
        ->setParameter('startDate', $startDate)
        ->setParameter('endDate', $endDate);

    if (null !== $owner) {
        $qb->andWhere('s.owner = :owner')->setParameter('owner', $owner);
    }

    /** @var Payment[] $payments */
    $payments = $qb->getQuery()->getResult();

    return $this->groupPaymentsByMonth($payments);
}
```

`groupPaymentsByMonth(...)` already exists as a private method in `PaymentRepository` (used by `getMonthlyRevenueByLandlord`). Reuse it as-is.

Add the imports: `use App\Entity\Place;`.

#### `src/Repository/OrderRepository.php`

```php
/**
 * Recent orders at a place, scoped to an optional owner.
 *
 * @return Order[]
 */
public function findRecentAtPlace(Place $place, int $limit, ?User $owner): array
{
    $qb = $this->entityManager->createQueryBuilder()
        ->select('o')
        ->from(Order::class, 'o')
        ->join('o.storage', 's')
        ->where('s.place = :place')
        ->setParameter('place', $place)
        ->orderBy('o.createdAt', 'DESC');

    if (null !== $owner) {
        $qb->andWhere('s.owner = :owner')->setParameter('owner', $owner);
    }

    if ($limit > 0) {
        $qb->setMaxResults($limit);
    }

    return $qb->getQuery()->getResult();
}

/**
 * Orders that are still in flight (RESERVED / AWAITING_PAYMENT / PAID) and
 * scheduled to start within $daysAhead from $now. Surfaces "incoming tenants".
 *
 * @return Order[]
 */
public function findUpcomingAtPlace(
    Place $place,
    int $daysAhead,
    \DateTimeImmutable $now,
    ?User $owner,
): array {
    $futureDate = $now->modify("+{$daysAhead} days");

    $qb = $this->entityManager->createQueryBuilder()
        ->select('o')
        ->from(Order::class, 'o')
        ->join('o.storage', 's')
        ->where('s.place = :place')
        ->andWhere('o.status IN (:statuses)')
        ->andWhere('o.startDate >= :now')
        ->andWhere('o.startDate <= :futureDate')
        ->setParameter('place', $place)
        ->setParameter('statuses', [
            OrderStatus::RESERVED,
            OrderStatus::AWAITING_PAYMENT,
            OrderStatus::PAID,
        ])
        ->setParameter('now', $now)
        ->setParameter('futureDate', $futureDate)
        ->orderBy('o.startDate', 'ASC');

    if (null !== $owner) {
        $qb->andWhere('s.owner = :owner')->setParameter('owner', $owner);
    }

    return $qb->getQuery()->getResult();
}
```

Add imports: `use App\Entity\Place;`.

### 4. `OverdueChecker` extension

Add to `src/Service/Overdue/OverdueChecker.php`:

```php
public function summariseForPlace(\DateTimeImmutable $now, \App\Entity\Place $place): OverdueSummary
{
    $views = array_values(array_filter(
        $this->findOverdueViews($now),
        static fn (OverdueContractView $v): bool =>
            $v->contract->storage->place->id->equals($place->id),
    ));

    $totalAmount = array_sum(array_map(
        static fn (OverdueContractView $v): int => $v->overdueAmount,
        $views,
    ));

    return new OverdueSummary(
        count: count($views),
        totalAmount: $totalAmount,
        top: array_slice($views, 0, 5),
    );
}
```

Filtering in PHP after `findOverdueViews(now)` is acceptable — the overdue universe is small (debtors, not all contracts) and we already pay for that fetch anywhere on the dashboard. Pushing the filter into SQL would duplicate the WHERE clause from `ContractRepository::findWithPaymentIssues` for marginal gain.

### 5. Extend `RevenueChart` Live Component

#### `src/Twig/Components/RevenueChart.php`

Add a new LiveProp + extend the resolution order:

```php
#[LiveProp]
public ?string $placeId = null;

// ...

public function getChart(): Chart
{
    if (null !== $this->placeId) {
        $result = $this->queryBus->handle(new GetPlaceRevenueChart(
            placeId: Uuid::fromString($this->placeId),
            landlordId: null !== $this->landlordId ? Uuid::fromString($this->landlordId) : null,
            months: $this->months,
        ));
    } elseif (null !== $this->landlordId) {
        $result = $this->queryBus->handle(new GetLandlordRevenueChart(
            landlordId: Uuid::fromString($this->landlordId),
            months: $this->months,
        ));
    } else {
        $result = $this->queryBus->handle(new GetAdminRevenueChart(
            months: $this->months,
        ));
    }

    // ... rest unchanged
}
```

Precedence: `placeId > landlordId > admin`. When both `placeId` and `landlordId` are passed, the chart is for "this landlord's storages at this place" — the `GetPlaceRevenueChart` handler already supports that.

The component template (`templates/components/RevenueChart.html.twig` if it exists) needs no change — it just renders `getChart()`.

### 6. `PlaceDetailController` refactor

Replace the body of `__invoke` while preserving the existing `denyAccessUnlessGranted(PlaceVoter::VIEW, $place)` gate:

```php
namespace App\Controller\Portal;

use App\Entity\User;
use App\Query\GetPlaceDashboardStats;
use App\Query\QueryBus;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Repository\PlaceAccessRepository;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Service\Security\PlaceVoter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/places/{id}', name: 'portal_places_detail', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
#[IsGranted('ROLE_LANDLORD')]
final class PlaceDetailController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly PlaceAccessRepository $placeAccessRepository,
        private readonly ContractRepository $contractRepository,
        private readonly OrderRepository $orderRepository,
        private readonly QueryBus $queryBus,
        private readonly ClockInterface $clock,
    ) {}

    public function __invoke(string $id): Response
    {
        $place = $this->placeRepository->get(Uuid::fromString($id));
        $this->denyAccessUnlessGranted(PlaceVoter::VIEW, $place);

        /** @var User $user */
        $user = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $now = $this->clock->now();

        $ownerScope = $isAdmin ? null : $user;

        $stats = $this->queryBus->handle(new GetPlaceDashboardStats(
            placeId: $place->id,
            landlordId: $isAdmin ? null : $user->id,
        ));

        $expiringContracts = $this->contractRepository
            ->findExpiringWithinDaysAtPlace(30, $now, $place, $ownerScope);
        $upcomingOrders = $this->orderRepository
            ->findUpcomingAtPlace($place, 30, $now, $ownerScope);
        $recentOrders = $this->orderRepository
            ->findRecentAtPlace($place, 5, $ownerScope);
        $freeByType = $this->storageRepository
            ->findFreeCountByTypeAtPlace($place, $ownerScope);

        // Existing hub-section data — kept for backwards compatibility
        $storageTypeCount = $this->storageTypeRepository->countByPlace($place);
        $storageCount = $isAdmin
            ? $this->storageRepository->countByPlace($place)
            : $this->storageRepository->countByOwnerAndPlace($user, $place);
        $hasAccess = $isAdmin || $this->placeAccessRepository->hasAccess($user, $place);

        return $this->render('portal/place/detail.html.twig', [
            'place' => $place,
            'stats' => $stats,
            'expiringContracts' => $expiringContracts,
            'upcomingOrders' => $upcomingOrders,
            'recentOrders' => $recentOrders,
            'freeByType' => $freeByType,
            // hub
            'storageTypeCount' => $storageTypeCount,
            'storageCount' => $storageCount,
            'hasAccess' => $hasAccess,
            'isAdmin' => $isAdmin,
        ]);
    }
}
```

### 7. Template refactor — `templates/portal/place/detail.html.twig`

Restructure top-to-bottom. Reuse the existing styles/utilities (Tailwind + DaisyUI cards, the same status badge map already used in `dashboard_landlord.html.twig` lines 194–214). **Keep all existing hub cards** (Sklady / Typy skladů / Přístupové kódy / Editor mapy / Upravit místo / Nebezpečná zóna) inside a new `<section class="mt-12">` titled "Správa místa".

Section order:

1. **Header card** — unchanged (place icon + name + type chip + address).
2. **Setup-health alerts** (admin only). Render each missing-* flag as a separate amber card with an explanation + CTA. The existing "Chybí nahrát provozní řád" alert (lines 45–57) is replaced by a single consolidated block — see snippet below. Skip the entire section when none are flagged.
3. **Co-owner disclaimer** (landlord only). When `stats.hasCoOwners` is true:
   ```twig
   <div class="text-sm text-gray-500 mb-6 italic">
       Vidíte pouze své sklady. Toto místo má i další pronajímatele.
   </div>
   ```
4. **Po splatnosti banner** (admin only). Render only when `stats.overdueCount > 0`. Mirror the pattern in `dashboard_admin.html.twig` lines 9–33 but link to `path('admin_overdue')` and prefix the heading with "Na tomto místě:" so admins know this slice is place-scoped. Show count, total Kč, and a max-3 mini-list from `stats.overdueTop` (filter by place — already done in the query).
5. **KPI tiles** (4-card grid) — Obsazenost · Tržby minulý měsíc · Očekávané MRR / měsíc · Aktivní smlouvy. Same visual idiom as `dashboard_landlord.html.twig` lines 9–95. Bind to `stats.*`.
6. **Brzy končící smlouvy (≤ 30 dní)** — only when `expiringContracts|length > 0`. Compact list: customer name, sklad/typ, endDate, link to `portal_contracts_detail` (or `portal_landlord_order_detail` for landlords / `admin_order_detail` for admins).
7. **Co se chystá (≤ 30 dní)** — only when `upcomingOrders|length > 0`. Same compact-list idiom: customer + storage + startDate + status badge.
8. **Posledních 5 objednávek** — only when `recentOrders|length > 0`. Mirror the table chunk from `dashboard_landlord.html.twig` lines 170–225 (status-class map + status-label map already there — copy verbatim into a Twig macro `templates/portal/place/_order_status.html.twig` or inline; implementer's call).
9. **Volné sklady (per typ)** — only when `freeByType|length > 0`. Compact 2-col table: typ → počet volných.
10. **Tržby — chart (12 měsíců)**:
    ```twig
    {% if isAdmin %}
        {{ component('RevenueChart', { placeId: place.id.toRfc4122 }) }}
    {% else %}
        {{ component('RevenueChart', { placeId: place.id.toRfc4122, landlordId: app.user.id.toRfc4122 }) }}
    {% endif %}
    ```
11. **Správa místa** — the existing hub. Wrap the existing cards in a heading section so users orient: setup at the top morphs into "navigation hub" at the bottom.

Setup-health snippet (admin only):

```twig
{% if isAdmin and (stats.missingOperatingRules or stats.missingMap or stats.missingStorageTypes or stats.missingLockCodes) %}
    <div class="space-y-3 mb-6">
        {% if stats.missingOperatingRules %}
            {{ include('portal/place/_health_alert.html.twig', {
                title: 'Chybí nahrát provozní řád',
                detail: 'Provozní řád je vyžadován při objednávce.',
                ctaUrl: path('portal_places_edit', {id: place.id}),
                ctaLabel: 'Nahrát v nastavení místa',
            }) }}
        {% endif %}
        {% if stats.missingMap %}
            {{ include('portal/place/_health_alert.html.twig', {
                title: 'Mapa není nahrána',
                detail: 'Bez mapy nelze používat editor rozmístění a zákazník nevidí plán.',
                ctaUrl: path('portal_storage_canvas', {placeId: place.id}),
                ctaLabel: 'Otevřít editor mapy',
            }) }}
        {% endif %}
        {% if stats.missingStorageTypes %}
            {{ include('portal/place/_health_alert.html.twig', {
                title: 'Žádné typy skladů',
                detail: 'Bez aspoň jednoho typu se nedá vytvořit sklad ani objednávka.',
                ctaUrl: path('portal_storage_types_list', {placeId: place.id}),
                ctaLabel: 'Vytvořit typ skladu',
            }) }}
        {% endif %}
        {% if stats.missingLockCodes %}
            {{ include('portal/place/_health_alert.html.twig', {
                title: 'Přístupové kódy ještě nevygenerovány',
                detail: 'Přístupové kódy jsou povolené, ale žádný sklad zatím kód nemá.',
                ctaUrl: path('portal_place_access_codes', {placeId: place.id}),
                ctaLabel: 'Spravovat přístupové kódy',
            }) }}
        {% endif %}
    </div>
{% endif %}
```

#### `templates/portal/place/_health_alert.html.twig` (new partial)

A small partial reused 4× by the snippet above. Same visual language as the existing amber alert at lines 45–57 of the current `detail.html.twig`. Three params: `title`, `detail`, `ctaUrl`, `ctaLabel`.

### 8. Admin places list — link to detail

Edit `templates/admin/place/list.html.twig`. In the row actions cell (lines 44–58), add a "Detail" link **before** Upravit:

```twig
<a href="{{ path('portal_places_detail', {id: place.id}) }}" class="inline-flex items-center link text-sm font-medium">
    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
    </svg>
    Detail
</a>
```

The whole row becomes Detail · Upravit · Editor.

### 9. Fixtures top-up

`fixtures/ContractFixtures.php` (or the equivalent place where landlord/place fixtures are seeded) — make sure the dev DB has, **per place**, a useful mix:

- ≥ 2 active contracts (for "Aktivní smlouvy" + "Očekávané MRR" tiles to be non-zero).
- ≥ 1 contract whose `endDate` falls within +30 days of MockClock (`2025-06-15`) — i.e. `endDate` between `2025-06-15` and `2025-07-15`. So "Brzy končící smlouvy" renders.
- ≥ 1 order with `startDate` within +30 days and status RESERVED / AWAITING_PAYMENT (so "Co se chystá" renders).
- For the place owned by `landlord@`: ≥ 1 storage owned by `landlord2@` so the `hasCoOwners` disclaimer fires when logged in as `landlord@`.
- For the admin overdue banner: at least the existing spec-023 debtor's contract should attach to one of the seeded places (no new fixture user needed; verify which place it currently lands on and document in the PR description).

If the existing fixtures already cover most of these, just top up the gaps. Don't break what spec 023's tests depend on.

### 10. Tests

#### Integration — `tests/Integration/Repository/StorageRepositoryTest.php`

- `countAtPlace(place, null)` returns total count for the place across owners.
- `countAtPlace(place, landlord)` returns only that landlord's storages.
- `countOccupiedAtPlace`, `countAvailableAtPlace`, `countBlockedAtPlace` mirror the same scope behaviour for each `StorageStatus`.
- `hasCoOwners(place, landlord)` is `true` when ≥1 storage at place is owned by another user, `false` when all storages are unowned or owned by the same landlord.
- `findFreeCountByTypeAtPlace`: returns one row per StorageType present at the place with AVAILABLE storages; counts respect owner scope.

#### Integration — `tests/Integration/Repository/ContractRepositoryTest.php`

- `countActiveRecurringAtPlace` and `sumExpectedRecurringAtPlace` for both null + landlord scopes.
- `findExpiringWithinDaysAtPlace(30, now, place, null)` returns only contracts at this place ending within 30 days; landlord-scoped variant excludes other landlords' contracts.

#### Integration — `tests/Integration/Repository/PaymentRepositoryTest.php`

- `sumAtPlaceAndPeriod` and `getMonthlyRevenueAtPlace` honour place + optional owner scope.

#### Integration — `tests/Integration/Repository/OrderRepositoryTest.php`

- `findRecentAtPlace`: limit honoured, scope honoured.
- `findUpcomingAtPlace`: only RESERVED / AWAITING_PAYMENT / PAID statuses, only `startDate` in `[now, now+30d]`, scope honoured.

#### Unit — `tests/Unit/Service/Overdue/OverdueCheckerTest.php`

Extend the existing test (added in spec 023) with a `summariseForPlace` case: 3 overdue contracts, 2 at place A, 1 at place B → calling for place A returns count=2 totalAmount=sum-of-A-views; place B returns count=1.

#### Integration — `tests/Integration/Controller/Portal/PlaceDetailControllerTest.php` (new)

- Login `admin@`, GET `/portal/places/{id}` for a fixture place: 200, response body contains "Obsazenost", "Tržby minulý měsíc", "Správa místa", and at least one of the existing hub card titles ("Sklady", "Editor mapy"). When that place has a debtor: response contains "Po splatnosti".
- Login `landlord@`, GET the same place: 200, KPI tile values reflect only `landlord@`'s storages (assert by counting fixture storages owned by `landlord@` at that place vs the rendered occupancy denominator).
- Login `landlord@` on a place where `landlord2@` also owns a storage: response contains "Vidíte pouze své sklady".
- Login `tenant@` (`ROLE_USER` only): GET `/portal/places/{id}` redirects (or 403) — `IsGranted('ROLE_LANDLORD')` blocks it.
- Login `landlord2@` for a place where they have **no** owned storage and no `PlaceAccess`: `PlaceVoter::VIEW` denies → 403.

#### Manual walk-through (Czech, full diacritics)

After `docker compose exec web composer db:reset`:

- Login `admin@`. Click any place → land on `/portal/places/{id}`. Verify: 4 KPI tiles, lists for any non-empty section, RevenueChart renders, Správa místa cards still visible at bottom. If that place has a debtor: red Po splatnosti card.
- `/portal/admin/places` row actions show Detail · Upravit · Editor; clicking Detail lands on the same page.
- Login `landlord@`. Click their place → KPI tiles show only their storages' numbers. If `landlord2@` co-owns a storage there: disclaimer visible. No setup-health alerts (those are admin-only).
- A freshly-created place (admin → create new): land on the detail page, expect amber alerts: "Chybí nahrát provozní řád", "Mapa není nahrána", "Žádné typy skladů". Each alert's CTA navigates to the right page.
- Login `tenant@`: navigate to `/portal/places/{anyId}` → blocked.

## Acceptance

- [ ] `docker compose exec web composer quality` green.
- [ ] Manual walk-through above passes for `admin@`, `landlord@`, `landlord2@`, `tenant@`.
- [ ] No occurrence of the old hub-only template body remains as a fallback (no orphaned blocks). Grep `templates/portal/place/detail.html.twig` for "Správa místa" — it's the new section heading.
- [ ] `RevenueChart` invoked with both `placeId` and `landlordId` returns chart data filtered by both.
- [ ] All new repository methods accept `?User $owner` and behave as: `null` → no owner filter; `User` → `s.owner = :owner`.
- [ ] Co-owner disclaimer renders only when ≥1 non-deleted storage at the place is owned by a user other than the current landlord (and that other owner is not null).
- [ ] `BACKLOG.md` row added: `024` `Per-place dashboard for landlords and admins (KPI tiles + setup-health alerts + expiring/upcoming/recent lists + place-scoped RevenueChart, owner-scoped for landlords, admin sees overdue + setup health) plus admin places list "Detail" link`, status `ready`, link to `024-per-place-dashboards.md`.

## Out of scope

- **Clickable occupancy heatmap on the place map image.** The map already shows storages via the canvas editor; overlaying live occupancy with click-to-detail is a separate UX increment.
- **Calendar widget for upcoming starts and expirations.** A list of "Brzy končící smlouvy" + "Co se chystá" covers the operational need; a true calendar is a larger feature.
- **Per-storage-type capacity chart.** "Volné sklady (per typ)" surfaces the same data more compactly.
- **Trend deltas vs previous month** ("+12 % vs minulý měsíc"). Useful but doubles the query count; deferred to a later spec.
- **Email digest "place dashboard summary".** Not requested.
- **Per-place admin filter on the global overdue page.** The new place dashboard surfaces the place-scoped slice; a query-string filter on `/portal/admin/po-splatnosti` is a separate increment.
- **Refactoring the duplicated `MONTH_NAMES` / `fillMissingMonths` / scope-by-owner builder** across query handlers and repos. Bounded duplication; cosmetic.
- **Cache.** Aggregates are cheap and the page isn't hot. Premature.
- **Landlord overdue visibility.** Spec 023 left this admin-only; we don't change that here.
- **Public-side `/pobocka/{id}` (PlaceDetailController in `Public\*`)** — that's the customer-browse page; this spec only touches portal `/portal/places/{id}`.

## Open questions

None — proceed.
