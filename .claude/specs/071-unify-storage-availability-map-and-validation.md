# 071 — Unify storage availability: one date-range helper for map, selection, and validation

**Status:** done
**Type:** bug / refactor
**Scope:** medium-large (~13 files)
**Depends on:** none (sharpens, does not change, the already-correct enforcement in spec 008/050)

## Problem

The order map and admin-onboarding map show a storage as available/occupied based on the **mutable `Storage::status` enum** (`AVAILABLE`/`RESERVED`/`OCCUPIED`/`MANUALLY_UNAVAILABLE`), but the actual reservation enforcement uses a completely different, **date-range-aware** code path (`StorageAvailabilityChecker::isAvailable($storage, $start, $end)`, which inspects overlapping orders + active contracts + manual blocks for the *chosen* window). The two disagree constantly:

- A unit whose occupying contract has ended (or whose order expired) but whose `status` was never flipped back to `AVAILABLE` shows **red/occupied on the map and refuses the click**, even though it is genuinely free for the customer's dates → "I couldn't pick a unit the map showed as taken / I saw it free elsewhere but couldn't choose it."
- A unit with `status = AVAILABLE` but a contract/order/block that overlaps the customer's chosen dates shows **green/clickable on the map**, the customer picks it, and only at the final step does enforcement throw "tato jednotka už není dostupná" → "I chose something, then got an error it was unavailable."

The map is also **not reactive to the chosen rental type or dates at all** — it paints the same colours whether the customer wants 1 week or unlimited, starting tomorrow or in three months.

Crucially, **enforcement is already correct and already shared**: `OrderService::createOrder()` (`src/Service/OrderService.php:67-71`) validates the pre-selected storage with the date-range checker, `OrderAcceptController:115` re-checks before finalizing, and admin onboarding routes through `createOrder()` too — so the system *already* refuses to double-book server-side. The defect is purely that the **map JSON and the click-guards** read the stale status enum, surfacing the disagreement to the user as a confusing UX instead of an honest, up-front map.

## Goal

The order map and the admin-onboarding map paint each unit's availability using the **exact same date-range logic that enforcement uses**, for the **rental type + dates the user has currently chosen**, recomputed reactively as those change. A unit that is green/clickable on the map is a unit the order will actually accept; a unit that is greyed is one enforcement would reject. Selecting a unit (customer or admin) is guarded by the same check. The mutable `Storage::status` enum stops being the source of truth for any booking decision — availability is *derived from state on the chosen dates* (overlapping orders / contracts / manual blocks), per the operator's intent: "the status is given by its state on the specified date; it cannot be set manually."

## Context (current state)

### The two divergent notions of "available"

1. **Authoritative, date-range (already used by enforcement):** `App\Service\StorageAvailabilityChecker::isAvailable(Storage, \DateTimeImmutable $start, ?\DateTimeImmutable $end, ?Order $excludeOrder, ?Contract $excludeContract)` — `src/Service/StorageAvailabilityChecker.php:38`. Returns false if ANY of: `Storage::status === MANUALLY_UNAVAILABLE`, an overlapping `StorageUnavailability` record, an overlapping `Order` (status in `CREATED|RESERVED|AWAITING_PAYMENT|PAID`), or an overlapping `Contract` (`terminatedAt IS NULL`, end-date overlap). `null` end = open-ended (UNLIMITED) window. `getBlockingReasons()` (line 90) returns the same predicates broken out by category — its precedence order (status → unavailabilities → orders → contracts) is the precedence we reuse for the derived status label.

2. **Stale, mutable enum (used by the maps + click-guards — the bug):**
   - `Storage::status` (`src/Entity/Storage.php:24`), mutated by `reserve()`/`occupy()`/`release()`/`markUnavailable()`. `Storage::isAvailable()` (line 252) = `status === AVAILABLE`.
   - **Order map JSON:** `OrderCreateController:124` ships `'status' => $s->status->value` per storage.
   - **Onboarding map JSON:** `AdminOnboardingForm::getStoragesJson()` (`src/Twig/Components/AdminOnboardingForm.php:149-188`) ships `'status' => $storage->status->value`.
   - **Map rendering:** `assets/controllers/storage_map_controller.js` decides colour (`getStorageColor`, line ~781), clickability (`isClickable`, line ~513), and labels (`getStatusText`/`getStatusClass`, line ~745) from `storage.status === 'available'` in the booking ("order"/selectMode) view modes.
   - **Customer click-guard:** `OrderForm::selectStorage()` (`src/Twig/Components/OrderForm.php:260`) rejects unless `$candidate->isAvailable()` (status enum).
   - **Admin click-guard:** `AdminOnboardingForm::selectStorage()` (`src/Twig/Components/AdminOnboardingForm.php:216-234`) performs **no availability check at all** (only type match).

### Map wiring & reactivity

- **Konva controller** `assets/controllers/storage_map_controller.js` (816 lines). Reads `data-storage-map-storages-value` (JSON). Has a Stimulus `storagesValueChanged()` callback (line 77) that **re-plots when the value attribute changes**. The map `container`/`minimap` targets carry `data-live-ignore` so Live Component morphing never touches the Konva DOM, but the `storages-value` attribute on the controller root *is* re-rendered by Live → triggers re-plot. View modes: `viewMode` value (`order` default; `occupancy` for the admin spec-047 map, which must stay status/snapshot based and is out of scope here). `selectMode` boolean gates click behaviour.
- **Onboarding map** is rendered **inside** the `AdminOnboardingForm` Live Component template (`templates/components/AdminOnboardingForm.html.twig:172-178`, `storages-value="{{ this.storagesJson|e('html_attr') }}"`). The component already exposes `formData.rentalType` / `startDate` / `endDate` (used by `getPaymentSchedule()`), so it can compute the chosen window server-side. It already re-renders `storagesJson` on every live update → **reactivity is free** once `getStoragesJson()` is made window-aware.
- **Order map** is rendered **outside** the `OrderForm` Live Component, as a sibling card in `templates/public/order_create.html.twig:30-39`, fed by a *static* `storagesJson` computed once in `OrderCreateController`. Selection is wired through `assets/controllers/order_map_bridge_controller.js` (map dispatches `storage-map:select` → bridge calls the live `selectStorage` action) and `order-selection-mode` toggles map visibility (spec 009). Because the JSON is static, the order map can never react to dates today.

### Overlap repository methods (single-storage today; bulk needed)

- `OrderRepository::findOverlappingByStorage(Storage, $start, ?$end, ?Order $exclude)` — `src/Repository/OrderRepository.php:235`. Status set `[CREATED, RESERVED, AWAITING_PAYMENT, PAID]`; nullable-end open-ended logic.
- `ContractRepository::findOverlappingByStorage(Storage, $start, ?$end, ?Contract $exclude)` — `src/Repository/ContractRepository.php:341`. `terminatedAt IS NULL`; nullable-end logic. A **bulk** `findOverlappingByStorages(array, $from, $to)` already exists at line 193 — verify it mirrors the single predicate (terminatedAt + null-end) before reuse; if its `$to` is non-nullable it must be widened or a sibling added.
- `StorageUnavailabilityRepository::findOverlappingByStorage(Storage, $start, ?$end)` — `src/Repository/StorageUnavailabilityRepository.php:75`. A bulk `findByStoragesInDateRange(array, $start, $end)` exists at line 133 but its `$end` is **non-nullable** — UNLIMITED needs null-end support, so add a bulk method that mirrors the single nullable-end version.

### Conventions in play

- Live Components extend `AbstractController`, expose data via public methods called as `this.method` in Twig. No getters on entities. Value objects are `final readonly`. Czech UI text with full diacritics. JS lives in `assets/controllers/*`. Tests: `tests/Unit` (no DB) + `tests/Integration` (DAMA, MockClock fixed at `2025-06-15 12:00:00 UTC`).

## Architecture

```
                       ┌─────────────────────────────────────────────┐
                       │  StorageAvailabilityChecker (single source)  │
                       │  - isAvailable() ............ enforcement     │  (already used, unchanged behaviour)
                       │  - availabilityForStorages() . NEW bulk       │  ← map JSON + click-guards now use THIS
                       │      reuses the same 3 overlap predicates     │
                       └─────────────────────────────────────────────┘
                                  ▲                      ▲
        chosen window (rentalType,│start,end)            │ chosen window
          ┌───────────────────────┘                      └────────────────────────┐
   OrderForm (customer)                                   AdminOnboardingForm (admin)
   - getStoragesJson(window) → available + derivedStatus  - getStoragesJson(window) → available + derivedStatus
   - hasValidWindow()  (Q1: gate)                          - hasValidWindow()  (Q1: gate)
   - selectStorage() guarded by checker                    - selectStorage() guarded by checker (Q2: HARD block)
          │                                                         │
          └──────────────► storage_map_controller.js ◄─────────────┘
                  booking view modes colour/click by `storage.available`
                  (derived `storage.status` only feeds the "why" label)
```

Single shared core: `availabilityForStorages()` and `isAvailable()` must compute identical results (refactor so they share the predicate sets — see Requirement 1). Enforcement (`OrderService`, `OrderAcceptController`) is untouched; it already calls `isAvailable()`.

## Requirements

### 1. `StorageAvailabilityChecker::availabilityForStorages()` — the shared bulk helper

New method on `src/Service/StorageAvailabilityChecker.php`:

```php
/**
 * Bulk, date-range availability for a set of storages over ONE window.
 * Reuses the EXACT predicates of isAvailable() — manual block status +
 * overlapping unavailability records + overlapping orders + overlapping
 * contracts — so the map can never disagree with enforcement.
 *
 * @param Storage[] $storages
 *
 * @return array<string, StorageAvailability> keyed by Storage->id->toRfc4122()
 */
public function availabilityForStorages(
    array $storages,
    \DateTimeImmutable $startDate,
    ?\DateTimeImmutable $endDate,
): array
```

- Run three bulk queries (orders, contracts, unavailabilities) over `$storages` for the window, group results by storage id, then for each storage decide — **using the same precedence as `getBlockingReasons()`**:
  1. `Storage::status === MANUALLY_UNAVAILABLE` **or** an overlapping unavailability record → `available=false`, `derivedStatus = MANUALLY_UNAVAILABLE`.
  2. else overlapping active contract → `available=false`, `derivedStatus = OCCUPIED`.
  3. else overlapping blocking order → `available=false`, `derivedStatus = RESERVED`.
  4. else → `available=true`, `derivedStatus = AVAILABLE`.
- **Guarantee single == bulk.** Refactor so `isAvailable()` and the bulk method share the order-status set (extract `private const BLOCKING_ORDER_STATUSES = [...]`) and the per-storage decision logic. Simplest acceptable shape: keep `isAvailable()`'s signature (enforcement passes `excludeOrder`/`excludeContract`), but make its non-exclude path and the bulk path call one private `decide(Storage, bool $hasBlock, bool $hasContract, bool $hasOrder)` helper. Add a unit test asserting agreement on a matrix of fixtures.
- New VO `src/Value/StorageAvailability.php`:

```php
final readonly class StorageAvailability
{
    public function __construct(
        public bool $isAvailable,
        public StorageStatus $derivedStatus,
    ) {}
}
```

Reusing `StorageStatus` (not a new enum) keeps the JS `getStatusText`/`getStatusClass`/`getStorageColor` switches working unchanged — they now receive the *derived* status string instead of the stale stored one.

### 2. Bulk overlap repository methods

Mirror the single-storage predicates exactly (status sets, `terminatedAt IS NULL`, nullable-end open-ended logic). Each returns the entities; grouping by storage id happens in the service.

- `OrderRepository::findOverlappingByStorages(array $storages, \DateTimeImmutable $startDate, ?\DateTimeImmutable $endDate): array` — `WHERE o.storage IN (:storages) AND o.status IN (:BLOCKING_ORDER_STATUSES)` + same null-end branch as the single method. Early-return `[]` on empty input.
- `StorageUnavailabilityRepository::findOverlappingByStorages(array $storages, \DateTimeImmutable $startDate, ?\DateTimeImmutable $endDate): array` — nullable-end variant of the existing `findByStoragesInDateRange`.
- `ContractRepository`: reuse `findOverlappingByStorages` (line 193) **only if** it already mirrors the single method (`terminatedAt IS NULL` + nullable end). If its `$to` is non-nullable / it filters differently, add a dedicated bulk method matching `findOverlappingByStorage`'s predicate instead — do **not** silently reuse a method with different semantics.

No `flush()`; QueryBuilder only; no `findBy`/`getRepository` (per CLAUDE.md repository rules).

### 3. `OrderForm` (customer) — window-aware map + guarded selection

`src/Twig/Components/OrderForm.php`:

- Add `hasValidWindow(): bool` — true when the form's current `OrderFormData` has a usable window: `startDate` set, and for `LIMITED` an `endDate` set with `endDate > startDate`; for `UNLIMITED` just `startDate`. (Mirror the existing date checks in `isEligibleForBillingModeChoice()`.)
- Add `getStoragesJson(): string` (move the per-storage payload here from `OrderCreateController`). For each storage of `this.place`, include the same fields the JS reads today (`id`, `number`, `storageTypeId`, `storageTypeName`, `coordinates`, `dimensions`, prices, `isUniform`, `photoUrls`) **plus**:
  - `'available'` (bool) and `'status'` (the **derived** `StorageStatus->value`) from `availabilityForStorages($storages, $start, $end)` for the chosen window.
  - When `!hasValidWindow()`: set every storage `available=false` and `status` to the stored status (only used for greyed colouring) — the template renders the dates-required hint (below) and the JS paints all non-clickable.
- Change `selectStorage()` (line 239): replace `if (!$candidate->isAvailable())` with the date-range checker for the form's current window. If `!hasValidWindow()`, reject (no selection before dates are chosen — matches the Q1 gate). Keep the existing place/type ownership guards.

`templates/components/OrderForm.html.twig`:

- **Relocate the storage-map card into the component template** (currently in `order_create.html.twig`) so its `data-storage-map-storages-value="{{ this.getStoragesJson()|e('html_attr') }}"` re-renders with the Live Component and `storagesValueChanged()` re-plots on every rental-type/date change — exactly mirroring the onboarding pattern. Keep `container`/`minimap` as `data-live-ignore`. Keep the `#storage-map-card` id and `data-order-map-bridge-target="map"` / selection-mode hooks so the outer controllers still find it (the wrapper in `order_create.html.twig` still encloses the component).
- Above the map, render a dates-required hint when `{% if not this.hasValidWindow() %}`: e.g. *"Nejdříve zvolte termín pronájmu — dostupnost jednotek se zobrazí podle vybraných dat."* (full diacritics).

`templates/public/order_create.html.twig`:

- Remove the now-relocated map card markup and the static `storagesJson` it consumed. Keep the `order-map-bridge` + `order-selection-mode` wrapper (it now wraps a component that renders the map inside itself). If `order-map-bridge` becomes a no-op after the move (selection could be a direct `data-action` on the map → live `selectStorage`, like onboarding's bridge), simplify or retire it — but only if selection + highlight-sync still work; otherwise keep it.

`src/Controller/Public/OrderCreateController.php`:

- Drop the `$storagesData`/`storagesJson` build (now owned by the component) and stop passing it to the template. Keep the auto-pick-first-available redirect and the pre-selected-storage validation **but** note the hardcoded `tomorrow`/`+30 days` probe is now only a *landing default*; the live map immediately recomputes against the customer's real dates, and enforcement re-checks at `/prijmout`, so the stale-status redirect-loop guard comment at line 90-92 stays valid.

### 4. `AdminOnboardingForm` (admin) — window-aware map + HARD-blocked selection

`src/Twig/Components/AdminOnboardingForm.php`:

- Add `hasValidWindow(): bool` from the form's `rentalType`/`startDate`/`endDate` (same rule as OrderForm; `getPaymentSchedule()` already reads these).
- `getStoragesJson()` (line 149): replace `'status' => $storage->status->value` with the **derived** status + add `'available'`, computed via `availabilityForStorages()` for the chosen window. When `!hasValidWindow()`, all `available=false` (+ render the dates-required hint in the template).
- `selectStorage()` (line 216): after the type-match guard, add the date-range availability check and **reject if unavailable** (Q2 — a hard block; onboarding must never assign an occupied unit). If `!hasValidWindow()`, reject. On rejection, set `$this->storageError` to an explanatory Czech message (e.g. *"Tato jednotka je ve zvoleném období obsazená nebo blokovaná."*) so the existing `[data-live-error]` anchor surfaces it.

`templates/components/AdminOnboardingForm.html.twig`: add the dates-required hint above the map (`{% if not this.hasValidWindow() %}`), mirroring the order form.

Note: server enforcement already blocks double-booking (`OrderService::createOrder` validates the pre-selected storage; `AdminOnboardingHandler` routes through it). This requirement makes the *map + click* honest so the admin never reaches that error — defense-in-depth, not a new gate.

### 5. `storage_map_controller.js` — colour/click/labels from derived availability

In the **booking view modes only** (`viewMode === 'order'` / `selectMode === true`; leave `viewMode === 'occupancy'` untouched):

- `isClickable` (line ~513): gate on `storage.available === true` (AND existing `storageTypeId === currentStorageTypeIdValue` AND not already highlighted), instead of `storage.status === 'available'`.
- `getStorageColor` (line ~781): green only when `storage.available === true` for the current type; otherwise paint by derived `storage.status` (the existing reserved/occupied/blocked colours still apply, now date-correct). Units of other types stay neutral as today.
- `getStatusText`/`getStatusClass` (line ~745): the "Volný/Rezervovaný/Obsazený/Nedostupný" switch already keys on the status string — it now receives the *derived* status, so it stays correct without change beyond the available-gating of the "Volný/green" branch.
- Treat a missing/false `available` (dates not chosen) as non-clickable + neutral; the server-rendered hint explains why.

Keep the occupancy-mode tooltip/modal (tenant/until, spec 047) reading `storage.status` + occupancy fields as-is.

### 6. Tests

- **Unit** (`tests/Unit`): `availabilityForStorages()` vs `isAvailable()` agreement matrix (free, manual-block, overlapping order per blocking status, overlapping contract, open-ended UNLIMITED window vs future-dated contract, contract that ends before the chosen start = available). Derived-status precedence (block > contract > order).
- **Integration** (`tests/Integration`, DAMA + MockClock): `OrderForm::getStoragesJson()` and `AdminOnboardingForm::getStoragesJson()` return `available=false` for a unit with an overlapping contract but stale `status=AVAILABLE`, and `available=true` for a unit with `status=OCCUPIED` whose contract ended before the chosen window. `AdminOnboardingForm::selectStorage()` refuses an occupied unit (sets `storageError`). `OrderForm::selectStorage()` refuses when no window chosen.

## Acceptance

- [ ] A unit with a contract that already ended (but `Storage::status` still `OCCUPIED`) appears **green/clickable** on both the order and onboarding maps for a future free window, and can be ordered through to completion.
- [ ] A unit with `status=AVAILABLE` but an order/contract overlapping the chosen dates appears **greyed/non-clickable** on both maps, and `selectStorage` refuses it — the customer never reaches the `/prijmout` "už není dostupná" error for a unit the map showed green.
- [ ] Changing rental type (LIMITED↔UNLIMITED) or start/end dates **recolours both maps** without a page reload (order map now reacts because it's fed by the Live Component).
- [ ] Before dates are chosen, both maps are fully greyed with the Czech dates-required hint; no unit is selectable.
- [ ] Admin onboarding cannot select (and `storageError` surfaces for) a unit that is occupied/blocked in the chosen window; the existing server enforcement remains as backstop.
- [ ] `availabilityForStorages()` and `isAvailable()` produce identical verdicts (unit test green).
- [ ] No booking surface (map JSON, `selectStorage`) reads `Storage::status` / `Storage::isAvailable()` for the available/clickable decision anymore (manual-block status is consulted only inside the checker).
- [ ] `composer quality` is green; controller/component/template changes also pass `composer test` (full suite, per the project note that `quality` runs unit-only).

## Out of scope

- **Removing the `Storage::status` column or the `reserve()`/`occupy()`/`release()` mutators.** Read across ~30 sites (aggregate dashboard counts in `StorageRepository`, exports, `StorageApi*` controllers, canvas, `OrderService` lifecycle, `DeleteStorageHandler`). Demoting it everywhere is a sprawling, risky refactor unrelated to the reported bug; this spec stops relying on it for *booking* decisions but leaves the column as legacy admin-display/manual-block state. `MANUALLY_UNAVAILABLE` stays meaningful (it's a legitimate operator block the checker already honours on every date).
- **Admin dashboards / per-place occupancy counts / exports** that still read stored `status` (e.g. `GetPlaceDashboardStats`, `StorageRepository::countAvailableAtPlace`). The reported bug is the order + onboarding flows ("in both order and onboarding"); the admin occupancy *map* (spec 047) already derives correctly via `StorageOccupancyService`. Aligning the remaining stored-status dashboards is a separate follow-up.
- **`viewMode === 'occupancy'`** map behaviour (spec 047) — stays snapshot/status based; unchanged.
- **Konva zoom/pan preservation across re-plot.** The onboarding map already re-plots (resetting zoom) on each change and that's accepted; the order map adopts the same behaviour. Optimizing to recolour-without-replot is a possible later polish, not required for correctness.
- **Auto-assignment ordering / preferred-storage logic** in `StorageAssignment` — already date-range correct; untouched.

## Open questions

None — proceed.

Decisions captured from the user:
- **Dates not chosen → grey out + require dates** (no optimistic default-window probe).
- **Admin onboarding → hard block** occupied/blocked units (system must make it impossible to rent the same unit twice, even for admins).
- **Status is derived from state on the chosen dates**, never set manually; keep the `StorageStatus` enum as a code-level type but compute it from overlapping orders/contracts/blocks.
