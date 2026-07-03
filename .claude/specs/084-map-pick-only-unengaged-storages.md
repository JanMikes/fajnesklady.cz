# 084 — Order map: manual pick only "clean" units; auto-assign prefers them

**Status:** done
**Type:** feature (booking policy)
**Scope:** medium (~8 files: availability checker, assignment service, OrderForm component + template, map controller JS, OrderCreateController, tests)
**Depends on:** none (builds on spec 071's unified availability)

## Problem

On the public order form, "vybrat ručně z mapy" lets a customer pick any unit that is free *in their chosen window* — including a unit whose sitting tenant's contract ends just before that window, or one with some other future booking. That enables "hijacking": the sitting tenant intends to prolong (spec 077), but a stranger manually grabs the exact unit for the day after the contract ends. Auto-assignment has the same blind spot: it hands out engaged-but-free-in-window units even when completely unengaged units of the same type exist.

## Goal

Manual map picking on the public order form only offers **clean** units — units with no engagement of any kind (order, contract, manual block) anywhere in [today, ∞). Engaged-but-free-in-window units render in a distinct "nelze vybrat ručně" state and stay reachable **only** through auto-assignment, which now prefers clean units and uses engaged ones strictly as a last resort. No guarantee is created — just best-effort protection of prolongations. Admin onboarding map picking is deliberately unrestricted.

## Context (current state)

- **Availability core** (spec 071): `src/Service/StorageAvailabilityChecker.php` — `isAvailable(Storage, start, ?end, …)` (`:39-84`) and bulk `availabilityForStorages(storages, start, ?end)` (`:97-133`), three queries total; all overlap repo predicates accept `endDate = null` = infinite window (spec 076 uses this for open-ended card blocks). **Therefore "clean" ≡ available for the window `[today, null]`** — the machinery already exists, it just needs a named wrapper.
- **Order form map**: `src/Twig/Components/OrderForm.php` — `getStoragesJson()` (`:171-205`) builds the map payload with window-derived `'available'` per unit; `selectStorage()` LiveAction (`:359-392`) guards a pick with `isAvailable($candidate, $window[0], $window[1])` and silently ignores invalid picks. `storageId` is a **non-writable** `#[LiveProp]` (`:45-46`, checksum-protected) — `selectStorage` is the only client mutation path, so guarding it IS the server-side enforcement for the Live flow. The component has no `ClockInterface` yet.
- **Map JS**: `assets/controllers/storage_map_controller.js` — in booking modes (`viewMode` 'order'/'onboarding') clickability, colors, labels key on `storage.available === true` (`:524-530`, `:763`, `:782`, `:801`). Legend in `templates/components/OrderForm.html.twig:746-753` (green "Volný", gray "Nedostupný").
- **Deep-link entry**: `src/Controller/Public/OrderCreateController.php` — no `storageId` → picks `findFirstAvailableStorage` and redirects (`:60-75`); explicit `storageId` validated for type/place/window-availability, with graceful "pick an alternative" redirect when stale (`:95-112`).
- **Final creation**: `OrderForm::submit()` stores to session → `/prijmout` → `src/Controller/Public/OrderAcceptController.php:373` `createOrder(preSelectedStorage: $storage)` with an existing manual/auto session flag (auto → silent re-assign, manual → "unit just taken" error). `src/Service/OrderService.php:107-111` re-validates window availability under a row lock.
- **Auto-assign**: `src/Service/StorageAssignment.php` — `assignStorage()` priority 1 = keep the extending user in their current unit (`findPreferredStorageForUser`, `:68-97`, with `excludeContract`); priority 2 = `findFirstAvailableStorage()` (`:102-118`, per-storage `isAvailable` loop, first hit wins). Also `hasAvailableStorage` / `countAvailableStorages` / `findAvailableStorages` — pure availability surfaces used elsewhere, must NOT change.
- **Admin onboarding**: `src/Twig/Components/AdminOnboardingForm.php` builds its own map payload (no `selectable` key) and `src/Command/AdminOnboardingHandler.php:53-61` passes `preSelectedStorage` into `createOrder` — both stay untouched; the JS fallback (Req. 4) keeps admin picking unrestricted.
- **Tests**: `tests/Integration/Service/StorageAssignmentTest.php`, `tests/Integration/Service/StorageAvailabilityCheckerTest.php`, `tests/Integration/Twig/Components/OrderFormTest.php`, `tests/Integration/Controller/Public/OrderCreateControllerTest.php`.
- **MockClock**: tests run at fixed `2025-06-15 12:00 UTC`; both new injection points use `ClockInterface`, never `new \DateTimeImmutable()`.

## Architecture

```
clean(unit) := isAvailable(unit, today, null)      // nothing in [today, ∞)

manual pick (public form):  selectable = available(window) && clean
auto-assign:                1. extending user's own unit (unchanged, exempt)
                            2. first available && clean
                            3. first available            // last resort
admin onboarding:           available(window) only        // unrestricted
```

Note: on the public form `start ≥ today`, so clean ⇒ available-in-window; `selectable = available && clean` is kept anyway as belt-and-braces.

## Requirements

### 1. `src/Service/StorageAvailabilityChecker.php` — named "clean" helpers

```php
/**
 * "Clean" = no engagement of any kind overlapping [$from, ∞): no live or
 * future contract, no blocking order, no manual block. Spec 084: manual map
 * picks require clean units so a stranger can't grab a unit whose sitting
 * tenant may still prolong; engaged-but-free units remain auto-assignable.
 */
public function isClean(Storage $storage, \DateTimeImmutable $from): bool
{
    return $this->isAvailable($storage, $from, null);
}

/**
 * Bulk variant of {@see self::isClean()} — same three-query shape as
 * availabilityForStorages().
 *
 * @param Storage[] $storages
 * @return array<string, bool> keyed by Storage->id->toRfc4122()
 */
public function cleanForStorages(array $storages, \DateTimeImmutable $from): array
{
    return array_map(
        static fn (StorageAvailability $a): bool => $a->isAvailable,
        $this->availabilityForStorages($storages, $from, null),
    );
}
```

### 2. `src/Twig/Components/OrderForm.php` — selectable flag + guard

- Inject `ClockInterface $clock`.
- `getStoragesJson()` (`:171-205`): alongside the existing availability lookup, when a window exists compute `$clean = $this->availabilityChecker->cleanForStorages($storages, $this->clock->now());` and add to each payload entry:

```php
'selectable' => null !== $available && $available->isAvailable && ($clean[$key] ?? false),
```

(Keep `'available'` untouched — display still distinguishes free vs occupied.)
- `selectStorage()` (`:387-389`): after the window-availability guard, add the clean guard (same silent-ignore style):

```php
if (!$this->availabilityChecker->isClean($candidate, $this->clock->now())) {
    return;
}
```

This is sufficient server-side enforcement for the Live flow — `storageId` is a non-writable LiveProp, so it cannot be set client-side except through this action.

### 3. `templates/components/OrderForm.html.twig` — legend

In the legend row (`:746-753`) add a third entry between Volný and Nedostupný:

```twig
<div class="flex items-center gap-1">
    <div class="w-3 h-3 rounded bg-amber-400"></div>
    <span class="text-gray-600" title="Jednotka má budoucí rezervaci — může být přidělena pouze automaticky.">Pouze automaticky</span>
</div>
```

### 4. `assets/controllers/storage_map_controller.js` — selectable-aware booking mode

Booking-mode logic currently keys on `storage.available === true` at four places (`:524-530` clickability, `:763` labels, `:782` badge classes, `:801` colors). Introduce one helper and use it at all four:

```js
// Manual pick eligibility: order mode payload carries `selectable`
// (available AND no future engagement — spec 084); onboarding payload
// doesn't, so admins keep picking any window-available unit.
isPickable(storage) {
    return storage.selectable ?? storage.available === true;
}
```

New intermediate visual state in booking modes for `available === true && !isPickable` (only occurs in order mode): fill color `#fbbf24` (amber-400, matches the legend), badge class `badge-warning`, label `Pouze automaticky`, cursor NOT pointer, click ignored. Tooltip line for that state: `Jednotku nelze vybrat ručně — systém ji může přidělit automaticky.` Fully-unavailable and available states render exactly as today.

### 5. `src/Controller/Public/OrderCreateController.php` — deep-link parity

Extend the stale-preselection condition (`:95`) so a hand-crafted or outdated `?storageId=` deep link onto an engaged unit gracefully falls through to the existing alternative-redirect (which, after Req. 6, prefers clean units):

```php
if (!$this->availabilityChecker->isAvailable($preSelectedStorage, $startDate, $endDate)
    || !$this->availabilityChecker->isClean($preSelectedStorage, $this->clock->now())) {
```

(Inject `ClockInterface`.) No copy changes — the existing alternative/flash flow already reads correctly.

### 6. `src/Service/StorageAssignment.php` — clean-preferred auto-assign

- Inject `ClockInterface $clock`.
- Rewrite `findFirstAvailableStorage()` (`:102-118`) as a two-tier pick using the bulk calls (also drops the per-storage query loop):

```php
public function findFirstAvailableStorage(...): ?Storage
{
    $storages = $this->storageRepository->findByStorageTypeAndPlace($storageType, $place);
    $availability = $this->availabilityChecker->availabilityForStorages($storages, $startDate, $endDate);
    $clean = $this->availabilityChecker->cleanForStorages($storages, $this->clock->now());

    $lastResort = null;
    foreach ($storages as $storage) {
        $key = $storage->id->toRfc4122();
        if (!($availability[$key]->isAvailable ?? false)) {
            continue;
        }
        if ($clean[$key] ?? false) {
            return $storage;              // clean & available — preferred
        }
        $lastResort ??= $storage;         // engaged elsewhere but free in window
    }

    return $lastResort;
}
```

- `assignStorage()` priority order is unchanged and deliberate: the extending user's own unit (priority 1, `findPreferredStorageForUser`) wins over cleanliness — that user IS the prolongation the rule protects.
- `hasAvailableStorage` / `countAvailableStorages` / `findAvailableStorages` stay pure-availability (they feed capacity displays, not assignment).
- The contract of `findFirstAvailableStorage` (returns *some* available unit or null) is preserved, so its other call sites (`OrderCreateController:60/:96`, acceptance re-assignment) just silently get better picks.

### 7. Stated decisions (no code)

- **No exemption for the logged-in customer's own unit on the map.** Picking your own unit manually stays blocked; keeping the same unit is already served by the prolongation flow (spec 077) and by auto-assign priority 1 (which excludes your own contract). One rule, no identity edge cases on a mostly-anonymous form.
- **No re-check of cleanliness at acceptance / in `OrderService::createOrder`.** The rule is a best-effort anti-hijack at *selection* time ("negarantujeme"); at acceptance the existing window-availability + row lock stays the sole gate. Re-checking would bounce customers whose picked unit gained an unrelated future booking mid-checkout, and would break admin onboarding (which shares `createOrder`).
- **Admin onboarding unrestricted** — operators assign deliberately (including re-seating the prolonging tenant themselves).

### 8. Tests

- **`StorageAvailabilityCheckerTest`**: `isClean` false for a unit with a future-only order (free today); true for a unit with only past/terminated engagements; `cleanForStorages` bulk agrees with per-unit `isClean`.
- **`StorageAssignmentTest`**: two available units, one with a future order → assign returns the clean one regardless of iteration order; only the engaged one available → it IS returned (last resort); extending user's own unit still wins priority 1 even though their own contract makes it unclean.
- **`OrderFormTest`** (Live Component): unit free in the chosen window but holding a future order → payload has `available: true, selectable: false`; `selectStorage` on it leaves `storageId` empty; `selectStorage` on a clean unit sets it.
- **`OrderCreateControllerTest`**: deep link `?storageId=` onto an engaged-but-free unit → redirect to a clean alternative (not a 200 with the engaged unit preselected).
- Fixture note: build the "future order" inline via existing fixtures' place/type + a persisted `Order` starting after the test window (see `OrderFixtures` reference constants in `.claude/FIXTURES.md`).

## Acceptance

- [ ] On `/objednavka` with dates chosen, a unit that is free in the window but has ANY future order/contract renders amber "Pouze automaticky", is not clickable, and `selectStorage` refuses it server-side; a fully clean unit picks normally.
- [ ] Auto mode (`selectionMode = auto`) on a type with one clean and one engaged unit always books the clean one; with only the engaged unit available, it books that one (capacity never shrinks).
- [ ] Deep link to an engaged unit redirects to a clean alternative; admin onboarding map behavior is byte-identical to today.
- [ ] Legend shows Volný / Pouze automaticky / Nedostupný; onboarding map legend unchanged.
- [ ] `composer quality` green; full `composer test` green (controller/template/JS-adjacent changes).

## Out of scope

- Any hard availability guarantee for prolongation — spec 076's card-contract open-ended block already covers the guaranteed tier; this rule is explicitly best-effort ("negarantujeme").
- Applying the clean rule to admin onboarding or to `OrderService`/acceptance — operator flows must stay able to assign any window-available unit (stated decision above).
- Smarter last-resort ranking (e.g. picking the engaged unit whose next booking starts latest) — two tiers satisfy the requirement; add only if real collisions show up.
- Exposing "why can't I pick this" beyond the tooltip/legend — the auto-assign path is offered right next to the map.
- Changing `hasAvailableStorage`/`countAvailableStorages`/type-card capacity counts — capacity semantics stay window-availability; the rule only shapes *which* unit gets picked.

## Open questions

None — proceed.
