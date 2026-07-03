# 082 — Lock codes: manual exclusions (system codes), visible history, and guaranteed previous-code retirement

**Status:** done
**Type:** feature + bugfix
**Scope:** medium (~15 files: enum, entity + migration, repository, generator, 2 existing handlers, 2 new commands + handlers, 2 new controllers, exception, template, fixtures, tests)
**Depends on:** none (extends shipped spec 022)

## Problem

Three gaps in the storage lock-code system (spec 022). **(1)** Some codes must never be assigned — e.g. the lock system's own service/master codes — but there is no way to blocklist them; `propose()` can hand them out and a landlord can type them in manually. **(2)** The "Přístupové kódy" page shows only counts; the operator cannot see *which* codes are burned (used history) or excluded. **(3)** The previous-code-retirement guarantee has a hole: `markUsed` fires only at assignment time, so a code assigned before spec 022 (or while `storageCodesEnabled` was off) exists *only* in the active-codes set — the moment it is replaced (handover completion, canvas save, storage form), it silently becomes proposable again. Someone who knows the old code can then be handed it for a different unit.

## Goal

The Přístupové kódy page gains a "Vyloučené kódy" form (enter one or more system codes + optional note) and a full history table showing every code with its state (Použitý / Vyloučený), date, and note; exclusions are never proposed, never accepted on manual entry, and survive the "Resetovat použité kódy" action (un-excluding is an explicit per-row action). Code assignment is centralized so that replacing a storage's lock code **always** retires the previous code into the used history — from every write path (handover completion, canvas drawer save, storage form, bulk fill) — even when that code predates the history table.

## Context (current state)

- **Generator**: `src/Service/StorageCodeGenerator.php` — `propose()` draws random codes avoiding `buildUsedSet()` (= usage-history rows ∪ active `Storage.lockCode`s, `:139-150`); `validateForStorage()` (`:63-85`) rejects wrong length / non-numeric / out-of-range / active-on-another-storage / `inHistory`; `markUsed()` (`:87-100`) inserts an idempotent `PlaceStorageCodeUsage` row; `bulkGenerateForEmpty()` (`:110-127`) fills NULL-code storages and marks each new code; `availableCount()` counts range minus used-set-in-range.
- **History entity**: `src/Entity/PlaceStorageCodeUsage.php` — `(id, place, code, usedAt)` with unique `(place_id, code)`. Repository `src/Repository/PlaceStorageCodeUsageRepository.php`: `existsForPlace`, `findCodesForPlace`, `releaseUnusedForPlace` (`:60-77` — bulk-deletes rows whose code is not an active lockCode; this is the "Resetovat" recycling path).
- **Assignment write paths** (all call `Storage::updateLockCode`, `src/Entity/Storage.php:186`):
  1. `src/Command/UpdateStorageHandler.php:58-67` — storage form + canvas drawer (`StorageApiUpdateController` dispatches `UpdateStorageCommand`); marks the NEW code used, never the old.
  2. `src/Event/ReleaseStorageOnHandoverCompletedHandler.php:30-36` — handover completion; marks the NEW code, never the old.
  3. `StorageCodeGenerator::bulkGenerateForEmpty:120` — only fills NULL codes (no previous code exists).
  `Storage::release()` does NOT touch `lockCode` — no other mutation path exists (verified by grep).
- **Proposal-only endpoints** (generate but don't assign): `src/Controller/Api/StorageApiGenerateCodeController.php` (canvas "Vygenerovat") and `src/Controller/Portal/LandlordHandoverGenerateCodeController.php` (handover "Vygenerovat jiný") — both call `propose()` in plain controllers with **no command dispatch**, so any write there would be silently lost (CLAUDE.md lost-write trap). Marking at proposal is therefore NOT an option; assignment is the correct marking point (see Requirements §3 decision).
- **The "lock codes section"**: `src/Controller/Portal/PlaceAccessCodesController.php` + `templates/portal/place/access_codes.html.twig` (config form, Akce card with bulk-generate + password-gated Reset via `_danger_modal`, storages table, Souhrn counts). Sibling POST controllers to mirror: `PlaceAccessCodesBulkGenerateController`, `PlaceAccessCodesResetController` (`PlaceVoter::MANAGE_CODES` guard, flash + redirect pattern, `HandledStamp` result reading at `:53-54`).
- **Exception**: `src/Exception/InvalidStorageCode.php` — named constructors, one per rejection reason.
- **Fixtures / tests**: `fixtures/PlaceStorageCodeUsageFixtures.php`; `tests/Unit/Service/StorageCodeGeneratorTest.php`, `tests/Integration/Repository/PlaceStorageCodeUsageRepositoryTest.php`, `tests/Integration/Controller/Portal/PlaceAccessCodesControllerTest.php`.
- **Design decision — single table**: exclusions live as rows in `place_storage_code_usage` with a new `type` discriminator, NOT a separate entity. `buildUsedSet` / `existsForPlace` / `findCodesForPlace` / `availableCount` then respect exclusions with zero changes; the only query that must discriminate is `releaseUnusedForPlace` (Reset must never delete exclusions) and the error message in `validateForStorage`.

## Requirements

### 1. `src/Enum/StorageCodeUsageType.php` — new enum

```php
enum StorageCodeUsageType: string
{
    case USED = 'used';
    case EXCLUDED = 'excluded';

    public function label(): string
    {
        return match ($this) {
            self::USED => 'Použitý',
            self::EXCLUDED => 'Vyloučený',
        };
    }
}
```

### 2. `src/Entity/PlaceStorageCodeUsage.php` — type + note

Add to the constructor (after `code`):

```php
#[ORM\Column(length: 20, enumType: StorageCodeUsageType::class, options: ['default' => 'used'])]
private(set) StorageCodeUsageType $type,
#[ORM\Column(length: 255, nullable: true)]
private(set) ?string $note,
```

(`options: ['default' => 'used']` so the generated migration backfills existing rows.) Plus a mutator for upgrading an already-used code to a protected exclusion:

```php
public function exclude(?string $note): void
{
    $this->type = StorageCodeUsageType::EXCLUDED;
    $this->note = $note;
}
```

Migration via `make:migration` (never handwritten). Update `markUsed()` in the generator and `fixtures/PlaceStorageCodeUsageFixtures.php:30` to pass `type: StorageCodeUsageType::USED, note: null`; add one EXCLUDED fixture row (e.g. code `'9999'`, note `'Servisní kód zámku'`) so the dev UI shows both states.

### 3. `src/Service/StorageCodeGenerator.php` — centralized assignment: `applyCode`

New public method — **the single write path for changing a storage's lock code**:

```php
/**
 * Assigns $newCode to the storage and guarantees the code history stays
 * airtight: the PREVIOUS code is retired into the used history (even when
 * it predates the history table), and the new code is recorded as used.
 */
public function applyCode(Storage $storage, ?string $newCode, \DateTimeImmutable $now): void
{
    $place = $storage->getPlace();
    $previous = $storage->lockCode;

    if ($place->storageCodesEnabled && null !== $previous && $previous !== $newCode) {
        $this->markUsed($place, $previous);
    }

    $storage->updateLockCode($newCode, $now);

    if ($place->storageCodesEnabled && null !== $newCode) {
        $this->markUsed($place, $newCode);
    }
}
```

Route all three write sites through it:
- `UpdateStorageHandler.php:58-67` → replace the `updateLockCode` + conditional `markUsed` block with `$this->codeGenerator->applyCode($storage, $command->lockCode, $now);` (keep the `if ($command->updateLockCode)` gate).
- `ReleaseStorageOnHandoverCompletedHandler.php:30-36` → `$this->codeGenerator->applyCode($storage, $event->newLockCode, $now);` (keep the `null !== $event->newLockCode` gate — handovers without a new code must not touch the existing one).
- `bulkGenerateForEmpty:120-122` → `$this->applyCode($storage, $code, $now);` (previous is always NULL there; behavior identical, one code path).

**Stated decision — proposal-only "Generovat"/"Vygenerovat jiný" clicks do NOT reserve**: both endpoints are plain controllers without a command dispatch (writes would be silently lost), and pre-reserving abandoned proposals would leak codes that only the password-gated Reset could reclaim. Assignment (form/drawer/handover submit) is the marking point; a race between two simultaneous proposals of the same code is already rejected at save by `validateForStorage`.

### 4. Exclusion awareness in `validateForStorage` + `propose`

- `propose()` needs no change (exclusions enter `buildUsedSet` automatically via `findCodesForPlace`).
- `validateForStorage()` — distinguish the message. Replace the `existsForPlace` check (`:82-84`) with a row lookup:

```php
$usage = $this->usageRepository->findOneByPlaceAndCode($place, $code);
if ($storage->lockCode !== $code && null !== $usage) {
    throw StorageCodeUsageType::EXCLUDED === $usage->type
        ? InvalidStorageCode::excluded($code)
        : InvalidStorageCode::inHistory($code);
}
```

Note the existing `$storage->lockCode !== $code` escape stays: a storage keeps its current code even if that code was excluded after assignment (exclusion prevents NEW assignments; the physical lock still has the old code until actively changed).

- `src/Exception/InvalidStorageCode.php` — new constructor:

```php
public static function excluded(string $code): self
{
    return new self(sprintf('Kód "%s" je vyloučen (systémový kód) a nelze jej přiřadit.', $code));
}
```

### 5. `src/Repository/PlaceStorageCodeUsageRepository.php`

- `findOneByPlaceAndCode(Place $place, string $code): ?PlaceStorageCodeUsage` (QueryBuilder, mirrors `existsForPlace`).
- `findForPlace(Place $place): array` — all rows ordered `code ASC` (zero-padded fixed length ⇒ string order = numeric order); return `list<PlaceStorageCodeUsage>`.
- `releaseUnusedForPlace()` — **add `->andWhere('u.type = :used')->setParameter('used', StorageCodeUsageType::USED)`** to the DELETE so Reset never wipes exclusions. This is the critical line of the whole spec.
- `remove(PlaceStorageCodeUsage $usage): void` — `$this->entityManager->remove($usage);` (no flush; command-bus middleware).

### 6. Exclude command — `src/Command/ExcludeStorageCodesCommand.php` + handler

```php
final readonly class ExcludeStorageCodesCommand
{
    /** @param list<string> $codes */
    public function __construct(
        public Uuid $placeId,
        public array $codes,
        public ?string $note,
    ) {}
}
```

Handler (`ExcludeStorageCodesHandler`, returns `int` = number of rows newly excluded/flipped):
- For each code: must be `ctype_digit` and `strlen === $place->storageCodeDigits`, else throw `InvalidStorageCode::notNumeric()` / `::wrongLength()` (whole batch rolls back — the doctrine middleware handles it).
- **Range deliberately NOT enforced**: an out-of-range system code can't be assigned today, but excluding it protects against a future range widening.
- Existing row `USED` → `$usage->exclude($command->note)` (survives Reset from now on); existing `EXCLUDED` → skip; no row → new `PlaceStorageCodeUsage(type: EXCLUDED, note: $command->note, usedAt: $now)`.

### 7. Un-exclude command — `src/Command/RemoveStorageCodeExclusionCommand.php` + handler

`(Uuid $usageId)`; handler loads the row (via `EntityManager::find`), throws `\DomainException('Kód není vyloučen.')` unless `type === EXCLUDED`, then `remove()`s it. The code becomes assignable again (if it was USED before the exclusion flip, that provenance is intentionally lost — the operator explicitly freed it).

### 8. Two POST controllers (mirror `PlaceAccessCodesResetController` skeleton, minus the password gate — exclusion/un-exclusion is low-impact and reversible)

- `src/Controller/Portal/PlaceAccessCodesExcludeController.php` — `/portal/places/{placeId}/access-codes/exclude`, `portal_place_access_codes_exclude`, POST. Guard `PlaceVoter::MANAGE_CODES` + `storageCodesEnabled`. Parse `codes` input (split on commas/whitespace, drop empties, dedupe), `note` optional string. Catch `InvalidStorageCode` via `HandlerFailureUnwrap::unwrap()` (MESSENGER.md — typed catch never matches wrapped) → error flash with the message. Success flash `sprintf('Vyloučeno %d kódů.', $count)` from `HandledStamp`. Extra touch: after success, query `StorageRepository` for storages at the place whose active `lockCode` is among the excluded codes; if any, add a `warning` flash `'Kód(y) %s jsou aktuálně přiřazené skladům — zůstávají aktivní, ale nebudou znovu nabídnuty.'` (new tiny repo method `findNumbersByPlaceAndLockCodes(Place $place, array $codes): array` on `StorageRepository`, QueryBuilder `WHERE s.place = :place AND s.lockCode IN (:codes) AND s.deletedAt IS NULL`).
- `src/Controller/Portal/PlaceAccessCodesUnexcludeController.php` — `/portal/places/{placeId}/access-codes/exclusions/{usageId}/remove`, `portal_place_access_codes_unexclude`, POST. Same guards; verify the usage row belongs to the place (404 otherwise); dispatch; flash `'Vyloučení kódu bylo zrušeno.'`.

### 9. `PlaceAccessCodesController` + `templates/portal/place/access_codes.html.twig` — history UI

Controller: replace `findCodesForPlace` count with `$usages = $this->usageRepository->findForPlace($place)`; pass `usages`, and compute `usedCodesCount` / `excludedCodesCount` by filtering on `type`. (Keep passing counts, not logic, to Twig.)

Template (all inside the existing `{% if place.storageCodesEnabled %}` main column, below the "Sklady na tomto místě" card):

- **"Vyloučené kódy" card** — short explainer (`Systémové nebo servisní kódy zámků, které nesmí být nikdy přiřazeny skladům.`), then a plain POST form to the exclude route: text input `name="codes"` (`class="form-input"`, `inputmode="numeric"`, placeholder `Např. 0000, 1234`), optional `name="note"` text input (placeholder `Poznámka (např. servisní kód)`), submit `btn btn-secondary` labelled `Vyloučit`.
- **"Historie kódů" card** — table `Kód | Stav | Datum | Poznámka | Akce` over `usages`: monospace code; badge (`bg-red-100 text-red-800` for Vyloučený, `bg-gray-100 text-gray-800` for Použitý — use `usage.type.label()`); `usage.usedAt|date('d.m.Y')`; note or `—`; for EXCLUDED rows a small POST form button `Zrušit vyloučení` to the unexclude route (plain `text-red-600` link-button, no modal). Empty state: `Zatím žádné použité ani vyloučené kódy.`
- **Souhrn card** (`:112-126`): add a `Vyloučeno` row between Použito and Dostupné; `Použito` now shows `usedCodesCount` (USED only). `availableCount` needs no change — exclusions in range reduce it automatically via `buildUsedSet`.
- **Reset modal description** (`:62-65`): append one sentence: `Vyloučené kódy zůstanou zachovány.`

Czech with full diacritics throughout.

### 10. Tests

- **Unit `StorageCodeGeneratorTest`**:
  - `applyCode` retires a previous code that was NEVER in history (the legacy gap): storage with lockCode `1111` and empty history → `applyCode($storage, '2222', $now)` → history contains both `1111` and `2222`, storage has `2222`.
  - `applyCode` with `null` new code still retires the previous one; with same code is a no-op on history duplication (markUsed idempotent).
  - `propose()` never returns an excluded code (exclude every code in a tiny range except one; propose returns the free one; excluding all → `StorageCodeRangeExhausted`).
  - `validateForStorage` on an excluded code throws with the `vyloučen (systémový kód)` message; on the storage's own current code passes even when excluded.
  - `availableCount` drops by 1 per in-range exclusion; out-of-range exclusion doesn't change it (`countUsedInRange` filters).
- **Integration `PlaceStorageCodeUsageRepositoryTest`**: `releaseUnusedForPlace` deletes inactive USED rows but keeps EXCLUDED rows (and keeps active USED rows as today).
- **Integration `PlaceAccessCodesControllerTest`**: exclude POST with `codes: "0000, 1234"` creates two EXCLUDED rows + success flash; excluding an existing USED code flips it (then Reset keeps it); invalid code (`"12"` with digits=4) → error flash, nothing persisted; unexclude POST removes the row; both actions 403 for a landlord without `MANAGE_CODES` on the place; history table renders both badges.
- **Integration** (extend existing handover / storage-update tests if cheap): completing a landlord handover with a new code leaves the OLD code in history.

## Acceptance

- [ ] Excluded codes are never returned by `propose()` (canvas Generovat, handover Vygenerovat jiný, bulk-generate) and are rejected on manual entry everywhere `validateForStorage` runs (canvas drawer, handover form) with `Kód "X" je vyloučen (systémový kód)…`.
- [ ] "Resetovat použité kódy" releases only USED rows; EXCLUDED rows survive (assert via repository test + UI).
- [ ] Replacing a lock code from ANY path (storage form, canvas PUT, handover completion) always inserts the previous code into history — including codes that had no history row (assigned pre-022 or while the feature was off).
- [ ] Přístupové kódy page lists every code with Stav badge, datum, poznámka; shows Použito / Vyloučeno / Dostupné counts; exclusion form and per-row Zrušit vyloučení work; excluding a currently-active code warns but succeeds.
- [ ] Migration generated via `make:migration`; existing rows become `type='used'`; `doctrine:schema:validate` clean.
- [ ] `composer quality` green; full `composer test` green (controller/template changes).

## Out of scope

- Reserving codes at proposal time ("Generovat" click without save) — plain controllers can't persist (lost-write trap), and abandoned reservations would leak pool capacity; assignment-time marking + save-time validation covers the race.
- Global (cross-place) exclusion list — codes, config, and locks are per-place everywhere in the system; system codes differ per lock installation.
- Audit-logging exclude/unexclude — the sibling code actions (bulk generate, reset) don't audit-log either; add uniformly later if ever needed.
- Password gate on exclude/un-exclude — reversible, low-impact actions; only the destructive bulk Reset keeps the danger-zone gate.
- Per-row release of a single USED code — the bulk Reset is the designed recycling path; un-exclude covers the only mistake that needs undoing.
- Recording WHICH storage a used code belonged to — would require backfilling unknowable history; the storages table on the same page already shows current assignments.

## Open questions

None — proceed.
