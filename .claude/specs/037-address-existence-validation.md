## 037 — Validate billing address against a real-world registry (CZ-first, Photon-backed)

**Status:** done
**Type:** feature (validation, UX, data quality)
**Scope:** medium (~18 files: 1 new value object + 1 service interface + 1 service + 1 controller + 1 Stimulus controller + 1 Twig macro + 1 cache pool + 1 rate-limiter entry + 7 FormData touches + 7 FormType touches + 7 template touches + ~6 new tests)
**Depends on:** none (mirrors 006-ares-lookup-button.md architecture)

## Problem

Customers can submit complete nonsense as their billing address — `validateAddress` in `src/Form/OrderFormData.php:107-127` (and its 6 siblings) only check **presence** of `billingStreet` / `billingCity` / `billingPostalCode`. "Asdfghj 999 / Tatratata / 99999" sails through, lands on the nájemní smlouva and the GoPay/Fakturoid invoice, and burns support time when post-payment documents need to be re-issued.

99.9% of customers are Czech, so the failure mode is concentrated: typos, joke inputs, and partially-filled forms (street pasted into city, etc.) all reach contract generation today.

## Goal

Both the consumer and the admin/portal flows surface a sharp inline warning the moment a billing address can't be matched in a real-world registry, with a deliberate one-click override for the rare legitimate edge case. Customers who pick from the new autocomplete dropdown never see the warning at all — fields self-fill, validation passes silently. Manual typists who land a real address also pass silently (server geocodes, hit cached). The remaining ~5% who type garbage see the warning and must either correct it or tick the override.

No order, registration, profile update, or admin onboarding is hard-blocked: the override checkbox always exists. The goal is data quality + a frictionless happy path, not a gate.

## Context (current state)

### Where billing address lives today

Three free-text inputs (`billingStreet` ≤255, `billingCity` ≤100, `billingPostalCode` ≤10) appear on **seven** FormData classes; all use the same field names + length asserts and currently share the same trivial `validateAddress` callback (presence-only):

| FormData                                  | FormType                                | Template / Live wrapper                                       | Surface                       |
|-------------------------------------------|-----------------------------------------|---------------------------------------------------------------|-------------------------------|
| `OrderFormData`                           | `OrderFormType`                         | `templates/components/OrderForm.html.twig` (Live)             | `/objednavka/...` (consumer)  |
| `RegistrationFormData`                    | `RegistrationFormType`                  | `templates/user/register.html.twig`                           | `/register`                   |
| `LandlordRegistrationFormData`            | `LandlordRegistrationFormType`          | `templates/user/landlord_register.html.twig`                  | `/registrace-pronajimatele`   |
| `BillingInfoFormData`                     | `BillingInfoFormType`                   | `templates/components/BillingInfoForm.html.twig` (Live)       | `/portal/profile/billing`     |
| `AdminUserFormData`                       | `AdminUserFormType`                     | `templates/portal/user/edit.html.twig`                        | `/portal/users/{id}/edit`     |
| `AdminCreateOnboardingFormData`           | `AdminCreateOnboardingFormType`         | `templates/admin/onboarding/digital.html.twig`                | admin onboarding (digital)    |
| `AdminMigrateCustomerFormData`            | `AdminMigrateCustomerFormType`          | `templates/admin/onboarding/migrate.html.twig`                | admin onboarding (migrate)    |

Two of these (`OrderForm`, `BillingInfoForm`) are Live Components with **per-field on-blur validation** wired via `validateField(...)` (`src/Twig/Components/OrderForm.php:183-191`), so any expensive check inside the FormData callback runs on every blur — cache must absorb the repeats cleanly.

### Pattern to mirror — 006 (ARES lookup)

Architecture is identical to the address case:

- **Interface + service**: `App\Service\AresLookup` (`src/Service/AresLookup.php`) + `App\Service\AresService` (`src/Service/AresService.php`) — `HttpClientInterface` + structured error handling + `try/catch` around the network call, logger uses `'exception' => $e` per CLAUDE.md.
- **JSON proxy controller**: `App\Controller\Api\AresLookupController` (`src/Controller/Api/AresLookupController.php`) — single-action, `RateLimiterFactoryInterface` injected, returns 200/404/422/429/503 with a tiny JSON shape.
- **Rate limiter** wired in `config/packages/rate_limiter.php:29-33` (`ares_lookup`, sliding_window, 60/hour/IP).
- **Stimulus controller**: `assets/controllers/ares_lookup_controller.js` — `fetch('/api/ares/{ico}')`, status-coded UI feedback (loading / success / not_found / error), pushes results into sibling form fields via `input[name$="[fieldName]"]` selectors and dispatches `input` + `change` events so Live Components re-render.

The new address-validation feature **copies this skeleton** verbatim. Same naming, same error surfacing, same `RateLimiterFactoryInterface` per-IP throttling. A developer who shipped 006 should feel they're shipping the same thing again with a different provider.

### Provider — Photon (Komoot), live-probed

Probed during spec authoring on 2026-05-19:

- `https://photon.komoot.io/api/?q=Vinohradsk%C3%A1%2052,%20Praha&limit=5` → returns GeoJSON `FeatureCollection`, each feature has `properties.{street, housenumber, city, postcode, countrycode, ...}`. Garbage (`asdfghj 999 tatratata`) → `features: []`.
- Free, no API key, OSM-backed, designed for typeahead (fast).
- No native country filter parameter — filter server-side on `properties.countrycode === 'CZ'`.
- Mapy.cz Suggest API was tried and rejected: returns 401 / 403 without a Seznam API key (probed `https://api.mapy.cz/v1/suggest`). Procurement + billing setup is out of scope for this spec.
- Nominatim was considered as a structured-query alternative; using a single provider for both autocomplete and validation simplifies the design.

Rate limit policy: be polite. One source IP (the prod server) hits Photon for server-side validation; we cache 7 days in `cache.app` keyed on the normalized triple. Browsers hit our `/api/address/suggest` proxy, never Photon directly.

### Why we are NOT using RÚIAN

The ČÚZK RÚIAN data dump (~2 GB CSV, monthly delta) is the authoritative Czech address register. It would catch addresses that Photon/OSM misses (rural, newly-built). But the integration cost is large (ETL, monthly sync, schema, search index) for a problem where Photon already handles ~99% of CZ addresses. The override checkbox covers the residual gap. Re-evaluate if support tickets show systematic Photon misses.

## Architecture

```
Browser                         Symfony app                          Photon (komoot.io)
───────                         ────────────                         ──────────────────
[ Order form     ] ── debounced ─▶ /api/address/suggest ─cache miss▶ /api/?q=...
[ street input   ]    fetch        AddressSuggestController          ◀── GeoJSON
[ autocomplete   ] ◀── JSON ──────                        ─cache hit─ (no upstream call)
[ dropdown       ]
       │
       │ user picks
       ▼
[ street/city/PSČ filled ]
[ submit form ]
       │
       ▼
HTTP POST ─────────────────────▶ FormData::validateAddress callback
                                  │
                                  ▼
                                 AddressValidator::validate()  ─cache miss▶ /api/?q=...
                                  │                            ◀── GeoJSON
                                  ▼
                                 AddressValidationResult{verified|notFound|skipped}
                                  │
                                  ├── verified  → no violation
                                  ├── notFound  → violation @ billingStreet + macro renders override checkbox
                                  └── skipped   → no violation  (override ticked, or Photon outage)
```

## Requirements

### 1. New service — `App\Service\Address\AddressValidator`

`src/Service/Address/AddressValidator.php` (new directory `src/Service/Address/`):

```php
final readonly class AddressValidator
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,                       // Symfony\Contracts\Cache\CacheInterface
        private LoggerInterface $logger,
    ) {}

    public function validate(?string $street, ?string $city, ?string $postalCode): AddressValidationResult { /* ... */ }

    /** @return list<AddressSuggestion> */
    public function suggest(string $query): array { /* ... */ }
}
```

`validate()` semantics:

1. If any of the three fields is null/blank → return `AddressValidationResult::skipped()` (presence violations are emitted elsewhere; this method does not duplicate them).
2. Compute cache key `address_validation.'.md5(strtolower(trim($street).'|'.trim($city).'|'.preg_replace('/\s+/', '', $postalCode)))`. 7-day TTL on `cache.app`.
3. On cache hit → return the cached `AddressValidationResult`.
4. On miss → call Photon with `q = "{street}, {postalCode} {city}, Česko"`, `limit=5`, `lang=cs`, 3 s timeout. `User-Agent: fajnesklady.cz address validation (info@fajnesklady.cz)`.
5. Parse results. A result is a **match** when ALL of:
   - `properties.countrycode === 'CZ'`
   - normalized `properties.postcode` (strip spaces) equals normalized input PSČ
   - normalized `properties.street` (mb_strtolower + Symfony `AsciiSlugger` for diacritics) contains the normalized input street's first token (the street name; housenumber tokens are best-effort, not required)
6. If ≥1 match → `AddressValidationResult::verified()`; else `AddressValidationResult::notFound()`. Cache the result either way (negative cache prevents re-hitting Photon on every repeated garbage submit).
7. Any `TransportExceptionInterface` / non-2xx / `\Throwable` → log at `warning` level with `'exception' => $e` and the input fields, **return `AddressValidationResult::skipped()`** (i.e. do NOT block orders during a Photon outage). The skipped result is **not** cached.

`suggest()` semantics:

- Cache key `address_suggest.'.md5(mb_strtolower(trim($query)))`, 1-day TTL.
- Calls Photon with `q={query}&limit=8&lang=cs`, same UA/timeout.
- Filters to `countrycode === 'CZ'`; maps each feature to `AddressSuggestion { street, houseNumber, city, postalCode, displayLabel }` where `displayLabel = "{street} {houseNumber}, {postalCode} {city}"` for the dropdown.
- Failure → returns `[]` and logs at `warning`.

### 2. New value objects — `App\Value\Address\*`

`src/Value/Address/AddressValidationResult.php`:

```php
final readonly class AddressValidationResult
{
    public function __construct(public string $state) {}    // 'verified' | 'not_found' | 'skipped'

    public static function verified(): self  { return new self('verified'); }
    public static function notFound(): self  { return new self('not_found'); }
    public static function skipped(): self   { return new self('skipped'); }

    public function isVerified(): bool { return 'verified' === $this->state; }
    public function isNotFound(): bool { return 'not_found' === $this->state; }
}
```

`src/Value/Address/AddressSuggestion.php`:

```php
final readonly class AddressSuggestion
{
    public function __construct(
        public string $street,         // empty string if Photon didn't return one
        public string $houseNumber,    // empty string if missing
        public string $city,
        public string $postalCode,     // normalized to digits (no space)
        public string $displayLabel,
    ) {}

    /** @return array{street: string, houseNumber: string, city: string, postalCode: string, displayLabel: string} */
    public function toArray(): array { /* trivial */ }
}
```

### 3. New JSON proxy controller — `App\Controller\Api\AddressSuggestController`

`src/Controller/Api/AddressSuggestController.php`:

```php
#[Route('/api/address/suggest', name: 'api_address_suggest', methods: ['GET'])]
final class AddressSuggestController extends AbstractController
{
    public function __construct(
        private readonly AddressValidator $addressValidator,
        private readonly RateLimiterFactoryInterface $addressSuggestLimiter,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $limiter = $this->addressSuggestLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$limiter->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $query = trim((string) $request->query->get('q', ''));
        if (mb_strlen($query) < 3) {
            return new JsonResponse(['suggestions' => []]);
        }

        return new JsonResponse([
            'suggestions' => array_map(
                static fn (AddressSuggestion $s): array => $s->toArray(),
                $this->addressValidator->suggest($query),
            ),
        ]);
    }
}
```

Public (no auth) — same posture as `/api/ares/{companyId}`. Rate limiter blocks abuse.

### 4. New rate limiter entry

`config/packages/rate_limiter.php` — add alongside `ares_lookup`:

```php
// Address autocomplete rate limiter — typeahead is chatty, give it more headroom
'address_suggest' => [
    'policy' => 'sliding_window',
    'limit' => 300,
    'interval' => '1 hour',
],
```

(Matches the convention; ARES at 60/h is for explicit button clicks, address suggest fires per keystroke debounce so needs more.)

`config/packages/test/services.php` — add `test.limiter.address_suggest` alias mirroring the existing `test.limiter.ares_lookup` block so functional tests can reset between cases.

### 5. New Stimulus controller — `address-autocomplete`

`assets/controllers/address_autocomplete_controller.js`. Targets:

- `streetInput`, `cityInput`, `postalCodeInput`, `dropdown`, `overrideCheckbox` (optional).

Behavior:

1. On `input` of `streetInput`, debounce 250 ms, then `fetch('/api/address/suggest?q=' + encodeURIComponent(value))`. Hide dropdown if response is rate-limited / empty / errored.
2. Render up to 8 suggestions in `dropdown`. Each `<li>` shows `displayLabel`. Mouse + keyboard navigation (`ArrowDown` / `ArrowUp` / `Enter` / `Escape`).
3. On selection: write `street`, `houseNumber` (joined as `"{street} {houseNumber}"` into `streetInput`), `city`, `postalCode` into respective inputs. Dispatch `input` + `change` events on each (Live Components rely on this — see ARES controller's `applyData()`). Hide dropdown.
4. Any manual edit to one of the three address inputs after a suggestion was picked: **uncheck the override checkbox** if present (the previous override no longer applies).
5. Hide dropdown on `clickOutside` and on `blur` (with a 150 ms delay so click events on dropdown items still fire — same trick as the existing `tom_select_controller` patterns).

Live Component gotcha: the dropdown `<ul>` MUST be rendered in a sibling container outside the `data-live-fields-defaults` region (or carry `data-live-ignore`), otherwise a Live re-render mid-typing wipes it. The simplest way is to render the `<ul>` from JS (the controller appends it to `this.element` — not part of the server-rendered HTML), which is the approach this spec assumes.

### 6. New Twig macro — `_address_override.html.twig`

`templates/components/_address_override.html.twig`:

```twig
{% macro render(form) %}
    <div {{ stimulus_controller('address-autocomplete') }}
         data-address-autocomplete-street-input-name="{{ form.billingStreet.vars.full_name }}"
         data-address-autocomplete-city-input-name="{{ form.billingCity.vars.full_name }}"
         data-address-autocomplete-postal-code-input-name="{{ form.billingPostalCode.vars.full_name }}"
         class="space-y-4">

        {{ form_row(form.billingStreet, {
            label_attr: {class: 'form-label-required'},
            attr: {'data-address-autocomplete-target': 'streetInput', autocomplete: 'street-address'},
        }) }}

        <div class="grid md:grid-cols-2 gap-4">
            <div>{{ form_row(form.billingCity, {
                label_attr: {class: 'form-label-required'},
                attr: {'data-address-autocomplete-target': 'cityInput', autocomplete: 'address-level2'},
            }) }}</div>
            <div>{{ form_row(form.billingPostalCode, {
                label_attr: {class: 'form-label-required'},
                attr: {'data-address-autocomplete-target': 'postalCodeInput', autocomplete: 'postal-code'},
            }) }}</div>
        </div>

        {# Override checkbox surfaces only when the server-side check emitted the 'address_unverified' flag.
           form.addressOverride is always present (CheckboxType, required=false, mapped=true); the wrapper
           is hidden by default and revealed by removing the 'hidden' class once a violation lands. #}
        <div class="{{ form.vars.errors|length > 0 or form.billingStreet.vars.errors|length > 0 ? '' : 'hidden' }} rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
             data-address-autocomplete-target="overrideContainer">
            <p class="font-medium mb-2">Tuto adresu se nepodařilo ověřit v registru.</p>
            <p class="mb-2 text-amber-800">Zkontrolujte ulici, město i PSČ. Pokud je adresa správná, pokračujte zaškrtnutím níže.</p>
            {{ form_widget(form.addressOverride, {attr: {'data-address-autocomplete-target': 'overrideCheckbox'}}) }}
            {{ form_label(form.addressOverride) }}
        </div>
    </div>
{% endmacro %}
```

(The visibility-by-error heuristic is a Twig-side shortcut — the violation at path `billingStreet` is the carrier signal. The Stimulus controller also un-hides the container when the server returns a 200 + suggestion was rejected.)

Each of the seven affected templates replaces its current `{{ form_row(form.billingStreet) }}` / `billingCity` / `billingPostalCode` block with `{% import 'components/_address_override.html.twig' as addr %} ... {{ addr.render(form) }}`.

Two of the seven templates (`OrderForm.html.twig`, `BillingInfoForm.html.twig`) currently sprinkle `data-action="blur->live#action"` + `data-live-action-param="validateField"` + `data-live-field-param="billingX"` on each input — the macro must accept an optional `liveValidate: true` flag that re-emits those attributes for the Live cases, or the macro signature passes through extra `attr` per field. **Recommendation:** add a second macro `render_live(form)` that emits the same structure with the live-validation attributes baked in; the two Live templates call `render_live`, the five non-Live call `render`. Avoids a parameter explosion.

### 7. New FormData field — `addressOverride` + reworked `validateAddress`

For each of the seven affected FormData classes:

```php
public bool $addressOverride = false;

#[Assert\Callback]
public function validateAddress(ExecutionContextInterface $context): void
{
    // ... existing presence checks for billingStreet / billingCity / billingPostalCode (unchanged) ...

    if ($this->addressOverride) {
        return;
    }

    // bail out if any presence check already fired — no point geocoding "asdf, , 11000"
    if (null === $this->billingStreet || '' === $this->billingStreet
        || null === $this->billingCity || '' === $this->billingCity
        || null === $this->billingPostalCode || '' === $this->billingPostalCode) {
        return;
    }

    $validator = $context->getObject() instanceof self
        ? null
        : null; // see "How the service reaches the callback" below
    // ...
}
```

#### How the service reaches the callback

A FormData class is a POPO, not a service — no DI. Two clean options exist; use **Option A** unless the dev finds a concrete reason against it:

**Option A (chosen): service-aware `Validator` constraint.** Introduce a Symfony validator constraint `App\Validator\AddressExists` + `AddressExistsValidator` (constraint + validator class pair). Apply it to **each FormData class** as a class-level `#[AddressExists]` attribute. The validator class is auto-wired and receives `AddressValidator` via constructor injection. Inside `validate(object $value, Constraint $constraint)`, read the three billing fields + `addressOverride` from `$value` (instance check on a shared interface `HasBillingAddress`).

```php
// src/Validator/AddressExists.php
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AddressExists extends Constraint
{
    public string $message = 'Tuto adresu se nepodařilo ověřit. Zkontrolujte ji, nebo potvrďte zaškrtnutím.';
    public function getTargets(): string|array { return self::CLASS_CONSTRAINT; }
}

// src/Validator/AddressExistsValidator.php
final class AddressExistsValidator extends ConstraintValidator
{
    public function __construct(private readonly AddressValidator $addressValidator) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$value instanceof HasBillingAddress) {
            return;
        }
        if ($value->addressOverride || !$value->hasCompleteAddress()) {
            return;
        }

        $result = $this->addressValidator->validate(
            $value->billingStreet,
            $value->billingCity,
            $value->billingPostalCode,
        );

        if ($result->isNotFound()) {
            $this->context->buildViolation($constraint->message)
                ->atPath('billingStreet')
                ->addViolation();
        }
    }
}
```

`HasBillingAddress` lives in `src/Form/Address/HasBillingAddress.php`:

```php
interface HasBillingAddress
{
    public ?string $billingStreet { get; }
    public ?string $billingCity { get; }
    public ?string $billingPostalCode { get; }
    public bool $addressOverride { get; }

    public function hasCompleteAddress(): bool;
}
```

(PHP 8.4 property-hook interfaces — the convention already used on entities per CLAUDE.md.)

Each FormData class:
- adds `implements HasBillingAddress`
- already exposes the three nullable strings (signature already matches; LandlordRegistrationFormData uses `string` not `?string` — widen those three to `?string` for consistency, see Out of scope rebuttal below)
- adds `public bool $addressOverride = false;` + `public function hasCompleteAddress(): bool { /* trivial */ }`
- gains class-level `#[AddressExists]`
- keeps its existing per-field presence callback unchanged
- includes `addressOverride` in `toSessionArray()` / `fromSessionArray()` for `OrderFormData` (the only one that round-trips through session, per `OrderFormData::toSessionArray()` at `src/Form/OrderFormData.php:257`)

**Why not Option B (callback with manual service location)?** Calling `$context->getValidator()->...` from inside `validateAddress` is ugly and fights the framework. Constraint validators exist for exactly this scenario.

### 8. FormType — register the `addressOverride` field

Each of the seven `*FormType` classes adds:

```php
$builder->add('addressOverride', CheckboxType::class, [
    'label' => 'Adresa je správná, pokračovat',
    'required' => false,
    'mapped' => true,
]);
```

(Mapped so the override persists on Live re-renders; the macro only reveals it after a server check has emitted the violation.)

### 9. Template touches

For each of the seven templates that currently render the three billing fields inline, replace that block with the macro call from §6. Concretely:

- `templates/components/OrderForm.html.twig:170-193` → `{{ addr.render_live(form) }}`
- `templates/components/BillingInfoForm.html.twig` → `{{ addr.render_live(form) }}`
- `templates/portal/user/edit.html.twig:79-91` → `{{ addr.render(form) }}`
- `templates/admin/onboarding/digital.html.twig:80-92` → `{{ addr.render(form) }}`
- `templates/admin/onboarding/migrate.html.twig:80-92` → `{{ addr.render(form) }}`
- `templates/user/register.html.twig` (block currently spanning the three fields) → `{{ addr.render(form) }}`
- `templates/user/landlord_register.html.twig` (block currently spanning the three fields) → `{{ addr.render(form) }}`

The macro import goes at the top of each file: `{% import 'components/_address_override.html.twig' as addr %}`. If a template already uses `import "..." as something`, alias the import to avoid collision.

The OrderForm Live template at line 165 says `{{ invoiceToCompany ? 'Sídlo společnosti' : 'Adresa bydliště' }}` — keep that heading; the macro renders below it.

### 10. Cache pool

The default `cache.app` pool is fine — no dedicated pool. Spec 006 (ARES) uses none either. If a dev wants isolation, add `cache.address_validation` in `config/packages/cache.php` later; not part of this scope.

### 11. Czech vs. non-CZ addresses

Photon results are filtered to `countrycode === 'CZ'`. Non-CZ addresses (the 0.1%) will:
- get no autocomplete suggestions
- fail `validate()` → violation surfaces → override checkbox appears → user ticks it → submit goes through

This is the explicit Czech-first design from the spec gathering. If non-CZ traffic grows materially, lift the country filter (1-line change in `AddressValidator::validate()`) — out of scope today.

### 12. Tests

`tests/Unit/Service/Address/AddressValidatorTest.php`:

- happy path (Photon returns one CZ result matching street + PSČ) → `verified`
- garbage input (Photon returns `[]`) → `notFound`
- foreign address (Photon returns non-CZ result only) → `notFound`
- transport error → `skipped` + logger called once with `'exception'` key
- cache hit on second call with identical normalized input (mock HttpClient asserts a single request)
- PSČ normalization (`"110 00"` vs `"11000"` → same cache key, same result)

`tests/Unit/Form/OrderFormDataTest.php` (extend existing `OrderFormDataTest`):

- a FormData with `addressOverride = true` and unmatchable address → no `AddressExists` violation
- a FormData with all three fields missing → presence violations fire but `AddressExists` does NOT fire (returns early before geocode)

`tests/Integration/Controller/Api/AddressSuggestControllerTest.php` (mirrors `AresLookupControllerTest` if it exists; if not, create the smallest viable test):

- 200 with empty `suggestions[]` for q-length < 3
- 200 with parsed suggestions when the validator returns one
- 429 once rate limiter exhausted

(No live HTTP — mock `AddressValidator` in the controller test; mock `HttpClientInterface` in the service test via `MockHttpClient`.)

## Acceptance

- [ ] `docker compose exec web composer quality` is green.
- [ ] `docker compose exec web composer test` is green (all 1100+ tests, not only unit) — controller / template / form touches require the full suite per the project's quality memory.
- [ ] Visiting `/objednavka/{place}/{type}` and typing "Vinohradská" into "Ulice" shows a dropdown within ~400 ms, picking a suggestion auto-fills city + PSČ.
- [ ] Submitting the order form with billing address "Asdfghj 999 / Tatratata / 99999" surfaces an inline warning + an override checkbox under the street field. Ticking the override + resubmitting succeeds.
- [ ] Submitting the same form with a real CZ address (typed manually, no autocomplete) succeeds silently.
- [ ] The override checkbox auto-unchecks when the user edits any of the three fields after ticking it.
- [ ] During a simulated Photon outage (e.g. `MockHttpClient` throwing) the form submits without blocking, and a `warning`-level log line lands with `exception` key + IP redacted.
- [ ] Same end-to-end behavior on `/portal/profile/billing`, `/portal/users/{id}/edit`, both `/register` flows, both admin onboarding flows.
- [ ] `curl -sI 'https://app.local/api/address/suggest?q=Vinohrad'` returns `200` JSON; 301st call within an hour from the same IP returns `429`.
- [ ] Cache: validating the same normalized triple twice within 7 days produces exactly one outbound Photon request (assert via integration test or manual `tail -f` of HttpClient profiler).

## Out of scope

- **RÚIAN ETL.** Photon + override + the 7-day cache handle the long tail; introducing a 2 GB CZ-government data sync is its own multi-week project. Re-evaluate only if support reports recurring "valid address but Photon rejects it" tickets.
- **Replacing free-text PSČ with a typed value object.** The existing `string $billingPostalCode` is used in many places (Fakturoid, contract PDF, signing flow). Normalizing the input on save is a separate, riskier refactor.
- **Address validation on `Place` (warehouse) records.** `PlaceFormData` / `PlaceProposeFormData` collect address too but those are admin-supplied and reviewed manually; not the problem this spec addresses.
- **Backfilling existing `User.billingStreet` rows.** Today's customers' addresses stay as-is; the validator only fires on form submission going forward.
- **Switching providers.** Mapy.cz Suggest API would arguably be better Czech coverage but requires API key procurement and pricing review with Seznam — orthogonal to shipping this feature.
- **Submit-time hard-block.** Explicitly rejected during spec gathering — soft warn + override is the chosen failure mode.
- **Pulling billing address out of `RegistrationFormData` / `LandlordRegistrationFormData`** even though that's where 99% of customers first type it. Both registration flows already collect it; this spec validates it where it's collected and at every later edit. No structural change.
- **Stricter street-name matching (e.g. requiring housenumber).** Photon's `housenumber` is often missing for valid CZ addresses; requiring it would raise the false-negative rate. The current spec matches on street + PSČ + CZ country, treats housenumber as best-effort.

## Open questions

None — proceed.
