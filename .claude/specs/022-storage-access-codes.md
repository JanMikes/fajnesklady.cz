# 022 — Optional per-place storage access codes (config + history + random proposal + customer surfacing)

**Status:** done
**Type:** feature (config + new entity + bulk/reset/random actions + canvas/handover/customer-page changes)
**Scope:** large (~25 files: 1 new entity + repo + migration, 1 generator service, 1 API endpoint, 1 new portal page + controller, place form/command/handler updates, canvas JS additions, handover handler updates, 2 customer-page templates, fixtures, tests)
**Depends on:** 020 (the public `/stav` page exists and has the documents card the customer reads)

## Problem

Every Fajnesklady place has a physical lock on the gate. Today `Storage::$lockCode` is a free-form `string(50)?` column edited only via the canvas ("Kód k zámku", `templates/portal/storage/canvas.html.twig:247`) and rotated only when the landlord types a value into the handover form (`LandlordHandoverFormData::$newLockCode`). Three things are broken:

1. **No configurability.** Landlords don't have a uniform code policy per place — some places need 4-digit numeric codes in `0000–9999`, others want a wider range; today everything is free text and there's nothing stopping anyone from typing `12345abc`.
2. **No reuse protection.** A landlord rotating a code at handover has to remember every code they've ever issued — there's nothing to prevent picking a code that was in use six months ago.
3. **No customer surfacing.** The code shows in the order-confirmation e-mail (`templates/email/order_confirmation.html.twig:126`) but **not** on the customer's portal order detail (`templates/portal/user/order/detail.html.twig`) and **not** on the new public `/stav` page (`templates/public/order_status.html.twig`). The customer who deletes the e-mail has no way back to it.

## Goal

A per-place feature flag turns the lockCode field into a managed access-code system:

- **Config on `Place`:** `storageCodesEnabled`, `storageCodeDigits` (default 4), `storageCodeFrom` (default 0), `storageCodeTo` (default 9999).
- **Used-code history:** new `PlaceStorageCodeUsage` table tracks every code ever assigned at the place. The random picker excludes both currently-assigned and historically-used codes. **"Reset codes"** deletes history rows whose code is **not** currently on any non-deleted storage at the place — your Q1 (a) definition. Currently-assigned codes survive reset.
- **Validation when enabled:** manually entered codes must be exactly N digits (left-pad short input automatically), numeric, within range, and not currently used by another storage at the same place nor in history (excluding *this* storage's own current code so editing a storage doesn't loop on itself).
- **Random proposal everywhere it matters:**
  - "Vygenerovat" button on the canvas inspector next to the lock-code input.
  - "Vygenerovat kódy pro prázdné sklady" bulk action on the place's new "Přístupové kódy" card (initial fill).
  - Pre-fill of `newLockCode` on the landlord handover form, with a "Vygenerovat jiný" re-roll button.
- **Customer surfacing:** the access code shows in a green callout on `templates/portal/user/order/detail.html.twig` and `templates/public/order_status.html.twig` — only while the contract is active (no `terminatedAt`, `endDate is null` or in the future). E-mail already does this; just ensure the formatting is consistent.
- **Disabled places: zero behavior change.** The `lockCode` column stays free-form; no validation, no proposal, no surfacing changes apart from the consistent customer-page block (which simply renders whatever value is on `Storage::$lockCode`, even if non-numeric).

## Context (current state)

### What already exists

- **`Storage::$lockCode`** — `src/Entity/Storage.php:37`, nullable `string(50)`. Behavior method `updateLockCode()` at `:158`.
- **Canvas inspector input** — `templates/portal/storage/canvas.html.twig:245-249` ("Kód k zámku", `data-storage-canvas-target="lockCodeInput"`, free-form). The Stimulus controller is `assets/controllers/storage_canvas_controller.js` (search the file for `lockCodeInput`).
- **Storage update API** — `PUT /api/places/{placeId}/storages/{storageId}` (`StorageApiUpdateController.php`). Already accepts `lockCode` in the JSON body (`:52-53`) and forwards via `UpdateStorageCommand($lockCode, $updateLockCode)` → `UpdateStorageHandler.php:54-59`.
- **Handover code rotation** — `LandlordHandoverFormData::$newLockCode` (`src/Form/LandlordHandoverFormData.php:16`) → `CompleteLandlordHandoverCommand` → `HandoverProtocol::completeLandlordSide()` (`src/Entity/HandoverProtocol.php:83-98`) records `newLockCode` on the protocol → `HandoverCompleted` event → `ReleaseStorageOnHandoverCompletedHandler::__invoke()` (`src/Event/ReleaseStorageOnHandoverCompletedHandler.php:28-30`) calls `$storage->updateLockCode($event->newLockCode, $now)`.
- **E-mail surfacing** — `templates/email/order_confirmation.html.twig:126-130` (the green callout we'll mirror on the customer pages). Context value `lockCode` is set in `SendOrderConfirmationEmailHandler.php:57`.
- **Place detail card grid** — `templates/portal/place/detail.html.twig:59-171`. Add a new card alongside "Sklady / Typy skladů / Editor mapy / Upravit / Smazat".
- **Place form pair** — `src/Form/PlaceFormData.php`, `src/Form/PlaceFormType.php`. Existing pattern for boolean/integer place-level config.
- **`PlaceVoter::EDIT`** — `src/Service/Security/PlaceVoter.php:19`. Same gate used by canvas (`StorageCanvasController.php:33`). Use this for the new "Přístupové kódy" page and the reset/bulk-generate actions.
- **`StorageVoter::EDIT`** — guards individual storage mutations through canvas API.

### What does NOT exist yet

- No tracking table for code history.
- No random-code generator service.
- No place-level "code config" surface.
- No customer-page rendering of `Storage::$lockCode`.
- No canvas "generate" affordance.
- No bulk action.

### Conventions touched

- Migrations: must be generated via `bin/console make:migration`. CLAUDE.md is explicit about this.
- New entity: PHP 8.4 property hooks per `Storage` / `Place` style. ID via `ProvideIdentity` (UUID v7).
- Repository: `EntityManager` composition, no `flush()`, no `ServiceEntityRepository`.
- New command(s): `final readonly`, single `__invoke` handler with `#[AsMessageHandler]`.
- Templates: full Czech diacritics. Tailwind classes consistent with surrounding code.
- Tests: MockClock at `2025-06-15 12:00:00 UTC`. Prefer fixture references.

### Display gating decision (Q4)

A storage's access code is **per-storage** and is **rotated at handover** for the next tenant. A previous tenant whose contract has ended must NOT see the rotated value. Render rule on customer pages:

```
show iff:
  - storage.lockCode is not null
  - and order.contract is not null
  - and order.contract.terminatedAt is null
  - and (order.contract.endDate is null OR order.contract.endDate >= today)
```

This is a Twig conditional in the template; no new flag on the view-model is strictly required (the template already has `contract` and `storage` in scope on both pages).

## Architecture

```
Place (config)                              PlaceStorageCodeUsage
┌────────────────────────┐                  ┌────────────────────────────────┐
│ storageCodesEnabled    │     1   ──*──    │ id (UUIDv7)                    │
│ storageCodeDigits      │                  │ place (FK, cascade delete)     │
│ storageCodeFrom        │                  │ code (string, padded)          │
│ storageCodeTo          │                  │ usedAt (datetime_immutable)    │
└────────────────────────┘                  │ unique(place_id, code)         │
                                            └────────────────────────────────┘

StorageCodeGenerator (service)
  - propose(Place): string                       ← random unused code
  - validateForStorage(Place, Storage, code)     ← strict checks (Q3)
  - markUsed(Place, code)                        ← inserts row in history
  - bulkGenerateForEmpty(Place): list<Storage>   ← canvas/place-level helper
  - releaseUnused(Place): int                    ← Reset action

Touchpoints
  ├── Canvas (manual + Generate button on inspector)
  │     └── StorageApiUpdateController → UpdateStorageHandler → markUsed()
  ├── Place "Přístupové kódy" page
  │     ├── Edit config form
  │     ├── "Vygenerovat kódy pro prázdné sklady" → bulkGenerateForEmpty()
  │     └── "Resetovat použité kódy" → releaseUnused()
  ├── Landlord handover form
  │     ├── GET pre-fills newLockCode with propose()
  │     ├── "Vygenerovat jiný" re-roll (POST → JSON, no persist)
  │     └── Submit validates + markUsed() on completion
  └── Customer pages (portal order detail + public /stav)
        └── Twig conditional renders green callout
```

## Requirements

### 1. `Place` entity — code config fields

`src/Entity/Place.php`

Add four properties (place near `orderExpirationDays` at `:35`). Defaults match the user's spec.

```php
#[ORM\Column(options: ['default' => false])]
public private(set) bool $storageCodesEnabled = false;

#[ORM\Column(options: ['default' => 4])]
public private(set) int $storageCodeDigits = 4;

#[ORM\Column(options: ['default' => 0])]
public private(set) int $storageCodeFrom = 0;

#[ORM\Column(options: ['default' => 9999])]
public private(set) int $storageCodeTo = 9999;
```

Behavior method (single mutator — these four travel together; UI is one form):

```php
public function updateStorageCodeConfig(
    bool $enabled,
    int $digits,
    int $from,
    int $to,
    \DateTimeImmutable $now,
): void {
    $this->storageCodesEnabled = $enabled;
    $this->storageCodeDigits = $digits;
    $this->storageCodeFrom = $from;
    $this->storageCodeTo = $to;
    $this->updatedAt = $now;
}

public function storageCodeRangeSize(): int
{
    return $this->storageCodeTo - $this->storageCodeFrom + 1;
}
```

### 2. New entity `PlaceStorageCodeUsage`

`src/Entity/PlaceStorageCodeUsage.php` (new)

```php
#[ORM\Entity]
#[ORM\Table(name: 'place_storage_code_usage')]
#[ORM\UniqueConstraint(name: 'uniq_place_storage_code_usage_place_code', columns: ['place_id', 'code'])]
class PlaceStorageCodeUsage
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: Place::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) Place $place,
        #[ORM\Column(length: 20)]
        private(set) string $code,
        #[ORM\Column]
        private(set) \DateTimeImmutable $usedAt,
    ) {
    }
}
```

No `releasedAt` column — Reset deletes rows. History = the set of rows currently in the table for the place.

### 3. New repository `PlaceStorageCodeUsageRepository`

`src/Repository/PlaceStorageCodeUsageRepository.php` (new)

EntityManager composition, no `flush()`. Methods:

```php
public function save(PlaceStorageCodeUsage $usage): void;            // persist only

public function existsForPlace(Place $place, string $code): bool;    // SELECT 1 ...

/** @return string[] */
public function findCodesForPlace(Place $place): array;              // for the page listing

/**
 * Delete usage rows whose code is NOT currently the lockCode of any
 * non-deleted Storage at the place. Returns the number of rows deleted.
 *
 * Uses a single DELETE … WHERE NOT EXISTS to keep it atomic. Test that
 * a non-numeric / out-of-range stale code is also released (i.e. we filter
 * purely on equality with Storage.lockCode, not on range membership).
 */
public function releaseUnusedForPlace(Place $place): int;
```

### 4. Service `StorageCodeGenerator`

`src/Service/StorageCodeGenerator.php` (new)

```php
final readonly class StorageCodeGenerator
{
    public function __construct(
        private StorageRepository $storageRepository,
        private PlaceStorageCodeUsageRepository $usageRepository,
        private EntityManagerInterface $entityManager,
        private ProvideIdentity $identityProvider,
        private ClockInterface $clock,
    ) {}

    public function format(Place $place, int $value): string
    {
        return str_pad((string) $value, $place->storageCodeDigits, '0', STR_PAD_LEFT);
    }

    /**
     * @throws StorageCodeRangeExhausted when no available code exists
     */
    public function propose(Place $place): string;

    /**
     * Strict validation for manual / API submissions when feature is enabled.
     *
     * - exactly N digits
     * - numeric value within [from..to]
     * - not currently the lockCode of another non-deleted Storage at the place
     *   (excluding $storage itself — Q3 "exclude the storage itself")
     * - not present in PlaceStorageCodeUsage for the place
     *   (excluding the storage's own current value — same reason)
     *
     * @throws InvalidStorageCode when any rule fails (one exception, message
     *         describes the specific failure for the API to relay)
     */
    public function validateForStorage(Place $place, Storage $storage, string $code): void;

    /**
     * Insert a row into PlaceStorageCodeUsage if not already present for this
     * (place, code). Idempotent so we can call it on every save without checking
     * first. Creates the entity using the identity provider so tests are deterministic.
     */
    public function markUsed(Place $place, string $code): void;

    /**
     * For each non-deleted Storage at the place with NULL lockCode, propose
     * a fresh code, persist it via Storage::updateLockCode, and call markUsed.
     *
     * Returns the list of (storage, assignedCode) tuples actually filled.
     * Stops early and throws StorageCodeRangeExhausted if the range runs out
     * mid-bulk; partial work persists (the doctrine_transaction middleware
     * will roll back the whole command anyway, but the method itself doesn't
     * try/catch — the caller decides).
     *
     * @return array<int, array{storage: Storage, code: string}>
     */
    public function bulkGenerateForEmpty(Place $place): array;

    public function availableCount(Place $place): int;  // for UI: range - used
}
```

Implementation notes for `propose()`:

- Range size matters. For a 4-digit `0..9999` range, brute-forcing random picks against a hash set works fine even at 90 % saturation. Use **rejection sampling**:
  1. Build the in-memory `used = array_flip(usage_codes ∪ active_lock_codes_at_place)`. (`active_lock_codes_at_place` = `SELECT lockCode FROM storage WHERE place=… AND deletedAt IS NULL AND lockCode IS NOT NULL`.)
  2. `available = rangeSize - count(used_within_range)`. If `available <= 0` throw `StorageCodeRangeExhausted`.
  3. Random integer in `[from..to]` via `random_int()`; loop until not in `used`. Cap loop at `rangeSize * 4` iterations as a safety net (defensive).
- For very wide ranges (`0..9_999_999_999`) the in-memory set is fine — you'll never hit it in practice.
- `validateForStorage` MUST exclude `$storage->lockCode` from the "is in history" check so that re-saving a storage with its current code is a no-op.

### 5. New exceptions

`src/Exception/StorageCodeRangeExhausted.php` and `src/Exception/InvalidStorageCode.php`

Both extend `\DomainException`. `InvalidStorageCode` carries a Czech-language message constructible via static factories:

```php
#[WithHttpStatus(422)]
final class InvalidStorageCode extends \DomainException
{
    public static function wrongLength(int $expected): self;
    public static function notNumeric(): self;
    public static function outOfRange(int $from, int $to): self;
    public static function alreadyUsedByAnotherStorage(string $code): self;
    public static function inHistory(string $code): self;
}

#[WithHttpStatus(409)]
final class StorageCodeRangeExhausted extends \DomainException
{
    public static function forPlace(Place $place): self;
}
```

### 6. Migration

Generate via `docker compose exec web bin/console make:migration`. Verify:

- `place` gets four new columns with the defaults from §1 (`DEFAULT FALSE`, `4`, `0`, `9999`). Existing rows are backfilled by the column defaults.
- New table `place_storage_code_usage`:
  - `id UUID PRIMARY KEY`
  - `place_id UUID NOT NULL REFERENCES place(id) ON DELETE CASCADE`
  - `code VARCHAR(20) NOT NULL`
  - `used_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL`
  - `UNIQUE(place_id, code)`
- `bin/console doctrine:schema:validate` clean afterwards.

### 7. Place form — new "Storage codes" subsection

We do NOT bolt the four config fields into the existing `PlaceFormType` (the place edit page is already busy and the codes are a self-contained subsystem with its own actions). Use a **dedicated page**.

#### 7a. New form pair

`src/Form/PlaceStorageCodeConfigFormData.php` (new)

```php
final class PlaceStorageCodeConfigFormData
{
    public bool $enabled = false;

    #[Assert\Range(min: 1, max: 10, notInRangeMessage: 'Počet číslic musí být mezi {{ min }} a {{ max }}.')]
    public int $digits = 4;

    #[Assert\Range(min: 0)]
    public int $from = 0;

    #[Assert\Range(min: 0)]
    public int $to = 9999;

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->enabled) {
            if ($this->from > $this->to) {
                $context->buildViolation('"Od" musí být menší nebo rovno "Do".')
                    ->atPath('from')->addViolation();
            }
            $maxForDigits = (10 ** $this->digits) - 1;
            if ($this->to > $maxForDigits) {
                $context->buildViolation(sprintf('Pro %d číslic je maximální hodnota %d.', $this->digits, $maxForDigits))
                    ->atPath('to')->addViolation();
            }
        }
    }

    public static function fromPlace(Place $place): self;
}
```

`src/Form/PlaceStorageCodeConfigFormType.php` (new) — `CheckboxType` for `enabled`, `IntegerType` for the rest, all with Czech labels matching the entity vocabulary ("Povolit přístupové kódy", "Počet číslic", "Od", "Do").

#### 7b. New command + handler

`src/Command/UpdatePlaceStorageCodeConfigCommand.php` and `…Handler.php`. Handler calls `Place::updateStorageCodeConfig(...)`. No code-history mutation here (changing the range doesn't release codes — that's an explicit user action via Reset).

### 8. New portal page — `/portal/places/{placeId}/access-codes`

`src/Controller/Portal/PlaceAccessCodesController.php` (new)

```php
#[Route('/portal/places/{placeId}/access-codes', name: 'portal_place_access_codes')]
#[IsGranted('ROLE_LANDLORD')]
final class PlaceAccessCodesController extends AbstractController
{
    public function __invoke(string $placeId, Request $request): Response
    {
        $place = $this->placeRepository->get(Uuid::fromString($placeId));
        $this->denyAccessUnlessGranted(PlaceVoter::MANAGE_CODES, $place);
        // GET: render config form + summary (used count, available count, list of in-use storages with codes)
        // POST: handle config form submit → UpdatePlaceStorageCodeConfigCommand → flash + redirect
    }
}
```

Two **action** sub-controllers (separate routes, POST-only):

- `PlaceAccessCodesBulkGenerateController` — `POST /portal/places/{placeId}/access-codes/bulk-generate`. Dispatches `BulkGenerateStorageCodesCommand($placeId)`. On success flash: `"Doplněno X kódů."`. Catches `StorageCodeRangeExhausted` → flash error.
- `PlaceAccessCodesResetController` — `POST /portal/places/{placeId}/access-codes/reset`. Dispatches `ReleaseUnusedStorageCodesCommand($placeId)`. Flash: `"Uvolněno X použitých kódů."`. (X = return value of `releaseUnusedForPlace`.)

Both action handlers MUST be guarded by the new `PlaceVoter::MANAGE_CODES` permission and validated as enabled-only (`if (!$place->storageCodesEnabled) throw …`).

CSRF: not required for these endpoints — accepted risk per project decision (low blast radius: idempotent within the same place, admin/landlord-scoped, no financial impact).

#### 8a. Template `templates/portal/place/access_codes.html.twig` (new)

Minimum sections:

1. **Config form** (the four fields). Save button.
2. **Summary** when enabled: `Rozsah X kódů · Použito Y · Dostupné Z` (Y = `count(usage_rows) + count(distinct active lockCodes not in usage)` — but since we always `markUsed` on save, in steady state Y = `count(usage_rows)`).
3. **Action buttons** when enabled:
   - "Vygenerovat kódy pro prázdné sklady" — submits to bulk-generate route. Only enabled if there's at least one storage with `lockCode IS NULL`.
   - "Resetovat použité kódy" — submits to reset route. Confirmation modal: `"Tímto trvale smažete historii použitých kódů. Kódy aktuálně přiřazené skladům zůstanou zachovány. Pokračovat?"`.
4. **List** of storages at the place with their current codes (number + code in `font-mono`), so the landlord sees what's set right now.

#### 8b. Place detail card — link to the new page

`templates/portal/place/detail.html.twig` — new card alongside "Sklady / Typy skladů / Editor mapy / Upravit / Smazat" inside the `grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6` container. Show as enabled by displaying a green badge "Povoleno" with the range, or a gray "Zakázáno" badge. Card href → `path('portal_place_access_codes', {placeId: place.id})`. Show only when `is_granted(PlaceVoter::MANAGE_CODES, place)` — admin OR landlord with PlaceAccess (broader than EDIT, which is admin-only).

```twig
<a href="{{ path('portal_place_access_codes', {placeId: place.id}) }}" class="card hover:shadow-lg transition-shadow border-2 border-transparent hover:border-indigo-300">
    <div class="card-body">
        <div class="flex items-center gap-3 mb-3">
            <div class="bg-indigo-100 text-indigo-600 rounded-lg p-3">
                {# key icon — heroicon outline #}
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                </svg>
            </div>
            <div class="flex-1">
                <h2 class="text-lg font-semibold text-gray-900">Přístupové kódy</h2>
                <p class="text-sm text-gray-500">
                    {% if place.storageCodesEnabled %}
                        <span class="text-green-600">Povoleno</span> · {{ place.storageCodeDigits }} čísl., {{ place.storageCodeFrom }}–{{ place.storageCodeTo }}
                    {% else %}
                        <span class="text-gray-500">Zakázáno</span>
                    {% endif %}
                </p>
            </div>
        </div>
        <p class="text-sm text-gray-600">Konfigurace, hromadné vygenerování a reset kódů</p>
    </div>
</a>
```

### 9. Canvas — generate button + strict validation hook-up

`templates/portal/storage/canvas.html.twig` around line 245-249. Replace the bare input with input + button (the Stimulus controller already targets `lockCodeInput`):

```twig
<div>
    <label class="block text-sm font-medium text-gray-700 mb-1">Kód k zámku</label>
    <div class="flex gap-2">
        <input type="text" data-storage-canvas-target="lockCodeInput"
               class="form-input flex-1" placeholder="{% if place.storageCodesEnabled %}{{ place.storageCodeDigits }} číslic{% else %}volitelné{% endif %}">
        {% if place.storageCodesEnabled %}
            <button type="button" data-storage-canvas-target="lockCodeGenerateBtn"
                    data-action="click->storage-canvas#generateLockCode"
                    class="btn btn-secondary btn-sm whitespace-nowrap" title="Vygenerovat náhodný kód">
                Vygenerovat
            </button>
        {% endif %}
    </div>
</div>
```

The Stimulus `generateLockCode` action does a `fetch` against a new endpoint:

`POST /api/places/{placeId}/storages/generate-code` — `Api\StorageApiGenerateCodeController` (storage-less variant — used by canvas before the storage exists). Returns `{code: "0042"}`. Guarded by `PlaceVoter::EDIT`. Returns 409 with `{message: "Žádné dostupné kódy."}` on `StorageCodeRangeExhausted`.

In `StorageApiUpdateController.php`, after the existing validation but before dispatching `UpdateStorageCommand`, when `$storage->getPlace()->storageCodesEnabled` is true, run the validator:

```php
if ($storage->getPlace()->storageCodesEnabled && isset($data['lockCode']) && '' !== $data['lockCode']) {
    try {
        $this->codeGenerator->validateForStorage($storage->getPlace(), $storage, $data['lockCode']);
    } catch (InvalidStorageCode $e) {
        return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
```

Inject `StorageCodeGenerator $codeGenerator` into the controller.

In `UpdateStorageHandler.php`, after `$storage->updateLockCode(...)` is called and `lockCode` is non-null AND the place has codes enabled, call `$this->codeGenerator->markUsed($storage->getPlace(), $command->lockCode)`. Use the same `if ($command->updateLockCode)` block. Handler dependency added; existing prices/commission paths stay untouched.

The `Api\StorageApiCreateController` — same treatment if `lockCode` is in the payload (currently `StorageApiCreateController` does not accept lockCode at all — leave it; new storages go through the canvas with no code, then get one via the bulk-fill or via the storage update endpoint).

### 10. Handover form — random pre-fill + re-roll

`src/Controller/Portal/LandlordHandoverViewController.php`

When the landlord opens the form (GET, `protocol->needsLandlordCompletion()` true):

```php
$place = $storage->getPlace();
if ($place->storageCodesEnabled && $protocol->needsLandlordCompletion()) {
    try {
        $formData->newLockCode = $this->codeGenerator->propose($place);
    } catch (StorageCodeRangeExhausted) {
        $this->addFlash('warning', 'Žádné dostupné kódy v rozsahu. Resetujte historii nebo rozšiřte rozsah.');
        $formData->newLockCode = null;
    }
}
```

Inject `StorageCodeGenerator $codeGenerator` into the controller.

Template `templates/portal/landlord/handover/view.html.twig` — around `:104-107`, when `place.storageCodesEnabled`, add a re-roll button next to the input. Use a new action route `POST /portal/pronajimatel/predavaci-protokol/{id}/generate-code` returning JSON `{code}`. Stimulus controller `assets/controllers/handover_code_controller.js` (new — small) wires the click → fetch → input value update.

`LandlordHandoverFormData::$newLockCode` validation — extend the constraint at `:15` so that when codes are enabled (decided in the handler since the FormData has no Place reference), strict validation runs in `CompleteLandlordHandoverHandler.php`:

```php
if ($place->storageCodesEnabled) {
    $this->codeGenerator->validateForStorage($place, $storage, $command->newLockCode);
}
```

Then on success, `markUsed`. Wire this in `ReleaseStorageOnHandoverCompletedHandler` (it already runs after `HandoverCompleted` and updates the storage's lockCode). Add right after the existing `updateLockCode` call:

```php
if (null !== $event->newLockCode && $storage->getPlace()->storageCodesEnabled) {
    $this->codeGenerator->markUsed($storage->getPlace(), $event->newLockCode);
}
```

Inject `StorageCodeGenerator` into `ReleaseStorageOnHandoverCompletedHandler`.

### 11. Customer-page surfacing

#### 11a. Public `/stav` — `templates/public/order_status.html.twig`

After the order-summary card (around `:123`, before the "Recurring info card") add:

```twig
{% set showAccessCode =
    storage.lockCode is not null
    and contract is not null
    and contract.terminatedAt is null
    and (contract.endDate is null or contract.endDate|date('Y-m-d') >= 'now'|date('Y-m-d')) %}

{% if showAccessCode %}
    <div class="rounded-lg border border-emerald-300 bg-emerald-50 p-5 text-emerald-900">
        <div class="text-sm font-semibold uppercase tracking-wide text-emerald-700 mb-1">Váš přístupový kód</div>
        <div class="text-3xl font-mono font-bold tracking-widest">{{ storage.lockCode }}</div>
        <p class="mt-2 text-sm">Tento kód použijete k přístupu na pobočku. Změní se při novém pronájmu, takže si jej uložte.</p>
    </div>
{% endif %}
```

#### 11b. Portal user order detail — `templates/portal/user/order/detail.html.twig`

Same block, dropped in near the top of the storage/order summary section. Re-use the snippet via `{% include 'components/order_access_code.html.twig' with {storage, contract} only %}` (new partial — keeps the two pages in sync).

`templates/components/order_access_code.html.twig` (new) — contents = the conditional + emerald block above. Both pages include it.

#### 11c. E-mail template — formatting parity

`templates/email/order_confirmation.html.twig:126-130` already has the green block. No structural change needed; verify the value is rendered as-is (the storage.lockCode is already padded if it was set via the new system). No change required.

### 12. Fixtures

`fixtures/PlaceFixtures.php` — enable codes on **one** place so dev can exercise the feature end-to-end.

```php
// Praha Centrum — codes feature enabled, default 4-digit 0..9999.
$place1->updateStorageCodeConfig(true, 4, 0, 9999, $now);
```

`fixtures/StorageFixtures.php` — for two storages at Praha Centrum (e.g. `REF_SMALL_A1` and `REF_LARGE_C1`), assign deterministic codes via `updateLockCode('0042', $now)` / `updateLockCode('0577', $now)`. Add a corresponding fixture for `PlaceStorageCodeUsageRepository::save(new PlaceStorageCodeUsage(…, '0042', …))` for both — easiest is a tiny new `PlaceStorageCodeUsageFixtures` with `DependentFixtureInterface` on `[PlaceFixtures, StorageFixtures]`.

Other places stay disabled (zero behavior change there).

`composer db:reset` must succeed.

### 13. Tests

Minimum coverage to ship:

#### Unit

- `tests/Unit/Service/StorageCodeGeneratorTest.php`
  - `format()` pads `5 → "0005"` for digits=4; `123 → "00000123"` for digits=8.
  - `propose()` returns a code in range, never returns one that's already in usage or in active lockCodes.
  - `propose()` throws `StorageCodeRangeExhausted` when the range is fully saturated.
  - `validateForStorage()` rejects: wrong length, non-numeric, out of range, code used by another storage, code in history. Accepts: code that equals the storage's own current value.
  - `bulkGenerateForEmpty()` only fills storages with NULL lockCode, marks each used, returns the tuples.
  - `markUsed()` is idempotent (calling twice with the same (place, code) doesn't error or duplicate-row).

- `tests/Unit/Entity/PlaceTest.php` — assert defaults for the four new fields, behavior of `updateStorageCodeConfig`.

#### Integration

- `tests/Integration/Repository/PlaceStorageCodeUsageRepositoryTest.php` — `releaseUnusedForPlace` deletes only rows whose code is not the lockCode of any non-deleted Storage at that place. Cross-place isolation: never touches another place's history.

- `tests/Integration/Controller/Api/StorageApiUpdateControllerTest.php` — extend (or create if missing) to assert: when codes enabled, an invalid code returns 422 with the exception message; a valid code persists; subsequent attempt to use the same code on a different storage returns 422.

- `tests/Integration/Controller/Portal/PlaceAccessCodesControllerTest.php` — config save round-trips; bulk-generate fills empty storages; reset deletes usage rows whose code isn't currently assigned; access requires `PlaceVoter::EDIT`.

- `tests/Integration/Controller/Portal/LandlordHandoverViewControllerTest.php` (existing or new) — when codes enabled, GET pre-fills `newLockCode` with a 4-digit code in range; submitting an invalid code returns the form with a validation error.

- A controller-access smoke check in `tests/Integration/Controller/ControllerAccessTest.php` for the three new portal/API routes.

#### View-layer

- No changes needed beyond the existing `tests/Integration/Controller/Public/OrderStatusControllerTest.php` (or whatever test exercises `/stav`) — add an assertion for the green code block when contract is active, and absence when contract is terminated or expired.

### 14. Anything that gets touched but is small enough to inline here

- `LandlordHandoverFormType.php` — `placeholder` adapt to "4 číslic" when enabled; otherwise keep existing copy. The form type doesn't have place context — pass it as a `form_option` from the controller.
- `Stimulus storage_canvas_controller` — add `lockCodeGenerateBtn` target + `generateLockCode` action; method `fetch`es the new generate-code endpoint, sets the input value, and triggers the existing change handler so the dirty state updates.
- New `assets/controllers/handover_code_controller.js` — single action that POSTs to the handover-code generate route, swaps the input value.
- `templates/portal/storage/canvas.html.twig` — also pass `place` into the inspector's data attributes if the Stimulus controller needs `placeId` for its `fetch` URL. Currently `place` is already in the template's scope; just emit `data-storage-canvas-place-id-value="{{ place.id }}"` on the controller root.

## Acceptance

- `docker compose exec web composer quality` is green.
- `bin/console doctrine:schema:validate` clean. `bin/console doctrine:migrations:status` up-to-date.
- `composer db:reset` succeeds; Praha Centrum has codes enabled, two storages have codes, `place_storage_code_usage` has the matching rows.
- Place detail (`/portal/places/{id}`) shows the new "Přístupové kódy" card. Clicking it opens `/portal/places/{id}/access-codes`.
- Disabling codes on the config page makes the canvas and handover form revert to free-form behavior; nothing else changes.
- Enabling codes:
  - Canvas inspector shows a "Vygenerovat" button. Clicking it inserts a 4-digit code in the input. Saving with an invalid code (e.g. `"abc"`, `"99999"`, or one already on another storage at the place) returns a 422 with a Czech message and the canvas surfaces it in a flash/toast (existing canvas error handling).
  - Saving with a valid new code: storage's lockCode persists, a row appears in `place_storage_code_usage`, and re-opening the canvas shows the same code.
- Bulk-generate fills every empty storage at the place; flash reports the count.
- Reset deletes only usage rows whose code is not currently a `Storage::$lockCode` at the place; flash reports the count released.
- Landlord handover form pre-fills `newLockCode` with a 4-digit unused code when codes are enabled. The "Vygenerovat jiný" button re-rolls without persisting. Submitting completes the handover, rotates `Storage::$lockCode`, and inserts a usage row.
- Customer's portal order detail (`/portal/objednavky/{id}`) and public `/stav` page show the green access-code block when the customer's contract is active. The block disappears once the contract is terminated or its `endDate` is past.
- E-mail (order confirmation) still shows the code identically to before — no regression.

## Out of scope

- Tracking which storage was assigned which code over time (timeline/audit per storage). The `usage` table only records "this code was used at this place" — no per-storage history. AuditLog already tracks storage events at the entity-event level; if richer history is wanted later, add an AuditLogger method then.
- Customer-side regeneration / "I forgot my code" flow. Customer always has the code on their order page + e-mail; if they lose access they contact the landlord (existing channel).
- Rate-limiting the generate API. Both endpoints sit behind `ROLE_LANDLORD` + `PlaceVoter::EDIT`; abuse is not a credible threat.
- Soft-delete / undelete of usage rows. Reset is destructive by design, and the prior question explicitly accepts it.
- A separate "code config" wizard at place creation. The defaults (`enabled=false`, 4 digits, 0..9999) are sensible for new places; the landlord visits the new card to opt in.
- Migrating non-numeric / out-of-range existing `Storage::$lockCode` values when the feature is flipped on. Per Q2 (b), the system starts empty; if the landlord enables codes on a place that already has e.g. `lockCode = "ABC"` on a storage, that value stays on the storage (display still works) but isn't tracked in usage. The next handover rotates it to a valid code, at which point the new code is tracked normally.
- Self-billing / commission / contract-document changes. Codes don't appear on legal docs; the rotating code surface is purely operational.

## Open questions

None — proceed.
