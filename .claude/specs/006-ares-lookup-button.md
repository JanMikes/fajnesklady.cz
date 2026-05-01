# 006 — "Načíst z ARES" button next to every IČO field

**Status:** done
**Type:** feature (UX + small backend refactor)
**Scope:** medium (~12 files: refactor existing service + new exception + JSON endpoint + rate-limiter config + Stimulus controller + Twig partial + 7 template touches)
**Depends on:** none. Spec 005 (password toggle) lives in the same form theme but doesn't conflict.

## Problem

Czech business addresses are public information available via the ARES open API at https://ares.gov.cz. Every form in the app that asks for IČO (company ID) currently makes the user re-type the company name, VAT ID, and address by hand even though we could fetch that data automatically. We need a "Načíst z ARES" button next to every IČO input that prefills the related fields without losing anything else the user has already typed.

## Goal

A small button below every IČO input that, when clicked, fetches data from ARES and overwrites the company-info fields in the same form. The flow handles four distinct UI states clearly: loading, success, "IČO not found in ARES", and "ARES unavailable / transport error".

## Why not `h4kuna/ares`?

The user suggested testing the `h4kuna/ares` library, so I tested the **upstream ARES REST API directly** (which is what `h4kuna/ares` wraps) using a real call to `https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/{ico}`:

- For valid IČO `27604977` (Google Czech Republic): HTTP 200 with `{ico, obchodniJmeno, dic, sidlo:{nazevUlice, cisloDomovni, cisloOrientacni, nazevObce, psc, …}, …}` — everything we need.
- For non-existent IČO `00000000`: HTTP 404 with `{"kod":"NENALEZENO", "popis":"…", "subKod":"VYSTUP_SUBJEKT_NENALEZEN"}`.

The codebase already has `App\Service\AresService` (`src/Service/AresService.php`) which calls this exact endpoint and maps the response into a clean `App\Value\AresResult` (`companyName, companyId, companyVatId, street, city, postalCode`). **Reuse it. Do not install `h4kuna/ares`** — it would add a dependency for zero gain.

The only refactor needed is to differentiate "ARES says not-found" from "we couldn't reach ARES" — see Requirement 1.

## Context (current state)

- `AresService::loadByCompanyId(string): ?AresResult` returns `null` for both 404 and transport errors (catches `\Throwable`). This collapses the two states the user explicitly wants differentiated.
- `App\Service\AresLookup` interface aliases `AresService` (autowired via `config/services.php` alias entry). All consumers should depend on `AresLookup`, not the concrete class.
- 7 FormTypes have an `IČO` field, all using property name `companyId` plus the consistent set `companyName / companyVatId / billingStreet / billingCity / billingPostalCode`:
  - `RegistrationFormType` → `/register`
  - `LandlordRegistrationFormType` → `/registrace-pronajimatele`
  - `BillingInfoFormType` → `/portal/profile/billing`
  - `OrderFormType` → `/objednavka/.../prijmout` (guest + logged-in checkout)
  - `AdminUserFormType` → `/portal/users/{id}/edit`
  - `AdminCreateOnboardingFormType` → `/portal/admin/onboarding/digital`
  - `AdminMigrateCustomerFormType` → `/portal/admin/onboarding/migrate`
- Symfony Form renders inputs with names like `<formname>[companyId]`, `<formname>[companyName]`, etc. Field names are predictable across every form.
- JS stack: Stimulus 3.2.2 auto-registered via `@symfony/stimulus-bundle`. Existing controllers in `assets/controllers/`. No build step needed for new controllers.
- Turbo is globally disabled. Don't fight it — go with a JSON fetch + Stimulus, not Turbo Frames.
- No `framework.rate_limiter` configured yet (`grep rate_limiter config/packages/` is empty).

## Architecture

```
[user clicks button]
        │
        ▼
ares_lookup_controller.js
   ├─ disables button, shows spinner
   ├─ fetch('/api/ares/{ico}', { headers: { Accept: 'application/json' } })
   │
   ├── 200 ─► populate sibling inputs by selector (input[name$="[companyName]"], …)
   │           show ✓ "Údaje načteny z ARES"
   │
   ├── 404 ─► show ⚠ "IČO nebylo v ARES nalezeno"
   │
   ├── 422 ─► show ⚠ "IČO musí mít přesně 8 číslic" (defensive — button is also disabled until valid)
   │
   ├── 429 ─► show ✗ "Načítání údajů z ARES selhalo, zkuste to prosím za chvíli"
   │
   └── 5xx / network ─► show ✗ "Načítání údajů z ARES selhalo, zkuste to prosím později"
```

The user never leaves the page; nothing reloads; only specific input values are written. Other form fields (firstName, lastName, phone, password, email…) are untouched.

## Requirements

### 1. Refactor `AresService` to differentiate not-found vs transport error

In `src/Service/AresService.php` and `src/Service/AresLookup.php`:

- Keep the return contract `loadByCompanyId(string): ?AresResult` — `null` now means **only** "ARES returned 404 (subject not found)".
- Throw a new exception `App\Exception\AresUnavailable` for any transport error (network failure, non-200/404 status code, malformed JSON). Include the inner exception via `previous`.
- Keep the existing logger call for unavailable cases (we still want monolog visibility).
- The 200/404 split: check `$response->getStatusCode()` explicitly, return `null` if 404, throw `AresUnavailable` for any other non-200.

```php
// rough sketch
try {
    $response = $this->httpClient->request('GET', self::ARES_API_URL.$companyId);
    $status = $response->getStatusCode();

    if (404 === $status) {
        return null;
    }
    if (200 !== $status) {
        throw AresUnavailable::withStatus($status);
    }

    return AresSubject::fromArray($response->toArray())->toResult();
} catch (AresUnavailable $e) {
    throw $e;  // re-raise, already typed
} catch (\Throwable $e) {
    $this->logger->error('ARES lookup failed', ['company_id' => $companyId, 'exception' => $e]);
    throw AresUnavailable::wrap($e);
}
```

Create `src/Exception/AresUnavailable.php`:
- Extends `\RuntimeException`
- `#[WithHttpStatus(503)]` so unhandled propagation maps to 503
- Static factories `withStatus(int $status): self` and `wrap(\Throwable $e): self`

Update existing call sites of `AresLookup::loadByCompanyId` to handle the new exception. Search `grep -rn AresLookup src/` to find them; expect they'll just want to treat `AresUnavailable` the same as `null` (silent failure on form submission). Check before changing.

### 2. JSON endpoint `GET /api/ares/{companyId}`

Create `src/Controller/Api/AresLookupController.php`:

```php
#[Route('/api/ares/{companyId}', name: 'api_ares_lookup', requirements: ['companyId' => '\d{1,12}'], methods: ['GET'])]
final class AresLookupController extends AbstractController
{
    public function __construct(
        private readonly AresLookup $aresLookup,
        private readonly RateLimiterFactory $aresLookupLimiter,
    ) {}

    public function __invoke(Request $request, string $companyId): JsonResponse
    {
        $limiter = $this->aresLookupLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$limiter->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        if (1 !== preg_match('/^\d{8}$/', $companyId)) {
            return new JsonResponse(['error' => 'invalid_format'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->aresLookup->loadByCompanyId($companyId);
        } catch (AresUnavailable) {
            return new JsonResponse(['error' => 'unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (null === $result) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'companyName' => $result->companyName,
            'companyVatId' => $result->companyVatId,
            'billingStreet' => $result->street,
            'billingCity' => $result->city,
            'billingPostalCode' => $result->postalCode,
        ]);
    }
}
```

**Auth**: no `#[IsGranted]` attribute — IČO is public information and the field exists on public registration / order forms. Anonymous access is required. The rate limiter prevents abuse.

**Security note for the dev**: this is effectively a public ARES proxy. The rate limit (Requirement 3) is the only guard. Don't add CSRF — it's GET only, and unauthenticated by design.

In `config/packages/security.php`, ensure the route is reachable anonymously. Most likely it falls under the existing `^/` firewall with no role restriction; verify with `bin/console debug:router api_ares_lookup` and `bin/console debug:firewall` after wiring up.

### 3. Rate limiter config

Add to `config/packages/framework.php` (or a new `config/packages/rate_limiter.php` — match what's tidiest):

```php
'rate_limiter' => [
    'ares_lookup' => [
        'policy' => 'sliding_window',
        'limit' => 60,
        'interval' => '1 hour',
    ],
],
```

Symfony auto-creates a `RateLimiterFactory` service named `aresLookupLimiter` (autowire by argument name `$aresLookupLimiter` of type `RateLimiterFactory`). 60/hour per IP is generous enough for legitimate retypes and tight enough to make scripted abuse pointless.

### 4. Stimulus controller

Create `assets/controllers/ares_lookup_controller.js`:

```js
import { Controller } from '@hotwired/stimulus';

const FIELD_NAMES = ['companyName', 'companyVatId', 'billingStreet', 'billingCity', 'billingPostalCode'];

export default class extends Controller {
    static targets = ['button', 'status'];

    connect() {
        this.companyIdInput = this.element.querySelector('input[name$="[companyId]"]');
        this.companyIdInput?.addEventListener('input', () => this.updateButtonState());
        this.updateButtonState();
    }

    updateButtonState() {
        const value = (this.companyIdInput?.value ?? '').trim();
        const valid = /^\d{8}$/.test(value);
        this.buttonTarget.disabled = !valid || this.loading;
    }

    async lookup() {
        const ico = this.companyIdInput.value.trim();
        if (!/^\d{8}$/.test(ico)) return;

        this.setStatus('loading', 'Načítání údajů z ARES…');
        this.loading = true;
        this.buttonTarget.disabled = true;

        try {
            const response = await fetch(`/api/ares/${encodeURIComponent(ico)}`, {
                headers: { 'Accept': 'application/json' },
            });

            if (response.status === 200) {
                const data = await response.json();
                this.applyData(data);
                this.setStatus('success', 'Údaje načteny z ARES');
                return;
            }

            if (response.status === 404) {
                this.setStatus('not_found', 'IČO nebylo v ARES nalezeno');
                return;
            }

            if (response.status === 429) {
                this.setStatus('error', 'Načítání údajů z ARES selhalo, zkuste to prosím za chvíli');
                return;
            }

            // 422 / 5xx / anything else
            this.setStatus('error', 'Načítání údajů z ARES selhalo, zkuste to prosím později');
        } catch (e) {
            this.setStatus('error', 'Načítání údajů z ARES selhalo, zkuste to prosím později');
        } finally {
            this.loading = false;
            this.updateButtonState();
        }
    }

    applyData(data) {
        for (const field of FIELD_NAMES) {
            const value = data[field];
            // ARES sometimes returns null for VAT ID — leave the existing input alone in that case
            if (value === null || value === undefined || value === '') continue;
            const input = this.element.querySelector(`input[name$="[${field}]"]`);
            if (input) {
                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
    }

    setStatus(kind, message) {
        const colors = {
            loading: 'text-gray-600',
            success: 'text-green-700',
            not_found: 'text-amber-700',
            error: 'text-red-700',
        };
        this.statusTarget.className = `text-sm mt-1 ${colors[kind] ?? ''}`;
        this.statusTarget.textContent = message;
    }
}
```

Notes:
- Selector-based (`input[name$="[companyId]"]`) so we don't need to touch any FormType — just wrap the right area of each template in `data-controller="ares-lookup"`.
- Dispatching `input` events after writing values lets any other listeners (live validation, character counters, etc.) react.
- VAT ID guard: when ARES returns no `dic`, we don't clear an existing form value. Same behavior for any field ARES leaves null — we never overwrite with null/empty.

### 5. Reusable Twig partial

Create `templates/components/ares_lookup_button.html.twig`:

```twig
<div class="mt-1 text-right">
    <button type="button"
            data-ares-lookup-target="button"
            data-action="click->ares-lookup#lookup"
            class="text-sm link disabled:text-gray-400 disabled:no-underline disabled:cursor-not-allowed">
        Načíst z ARES
    </button>
    <p data-ares-lookup-target="status" class="text-sm mt-1" aria-live="polite"></p>
</div>
```

`aria-live="polite"` makes screen readers announce the status changes without interrupting.

### 6. Wire up every IČO-bearing form template

For each of the 7 form templates that render the company-info section, wrap the `companyId` field plus the company-info fields in a single `<div data-controller="ares-lookup">…</div>`, and `{% include 'components/ares_lookup_button.html.twig' %}` immediately after the IČO field.

The dev should grep `grep -rln "companyId" templates/` to find each render site (most use `{{ form_row(form.companyId) }}` patterns). Targets:

| FormType | Template (most likely path — verify) |
|---|---|
| `RegistrationFormType` | `templates/user/register.html.twig` |
| `LandlordRegistrationFormType` | `templates/user/landlord_register.html.twig` |
| `BillingInfoFormType` | rendered as a Twig component `templates/components/BillingInfoForm.html.twig` (already exists) |
| `OrderFormType` | `templates/public/order/...` |
| `AdminUserFormType` | `templates/portal/user/edit.html.twig` |
| `AdminCreateOnboardingFormType` | `templates/admin/onboarding/...` |
| `AdminMigrateCustomerFormType` | `templates/admin/onboarding/...` |

Pattern (rough sketch — adapt to each template's actual structure):

```twig
<div data-controller="ares-lookup">
    {{ form_row(form.companyId) }}
    {% include 'components/ares_lookup_button.html.twig' %}

    {{ form_row(form.companyName) }}
    {{ form_row(form.companyVatId) }}
    {{ form_row(form.billingStreet) }}
    {{ form_row(form.billingCity) }}
    {{ form_row(form.billingPostalCode) }}
</div>
```

The wrapper must contain BOTH the companyId input AND all the inputs that should be populated, because the Stimulus controller's `this.element.querySelector` is scoped to that wrapper. Anything outside the wrapper is not touched by the controller — keeps the blast radius minimal and predictable.

### 7. Tests

- Unit test `tests/Unit/Service/AresServiceTest.php` (or update existing if any):
  - Mock `HttpClientInterface` returning 200 + valid JSON → returns `AresResult`.
  - Mock returning 404 → returns `null`.
  - Mock returning 500 → throws `AresUnavailable`.
  - Mock throwing `TransportException` → throws `AresUnavailable` and logs.

- Integration test `tests/Integration/Controller/Api/AresLookupControllerTest.php`:
  - Stub `AresLookup` in the test container to return predictable values for given IČOs.
  - 8-digit valid IČO → 200 with the expected JSON shape.
  - 8-digit not-found IČO → 404 `{error: 'not_found'}`.
  - Stub throws `AresUnavailable` → 503 `{error: 'unavailable'}`.
  - Non-numeric / wrong-length IČO → 422.
  - 61 calls in quick succession from the same test client → at least one 429.

No JS unit tests — verify the Stimulus controller manually per the acceptance section.

## Acceptance

- `docker compose exec web composer quality` is green.
- `GET /api/ares/27604977` returns 200 with `{companyName: "Google Czech Republic, s.r.o.", companyVatId: "CZ27604977", billingStreet: "Stroupežnického 3191/17", billingCity: "Praha", billingPostalCode: "15000"}`. (Verified against the live API while writing this spec.)
- `GET /api/ares/00000000` returns 404 `{"error":"not_found"}`.
- `GET /api/ares/abc` returns 422.
- Spamming the endpoint 100× from one IP returns 429 within the first ~60 calls.
- On every form listed in the table above, the "Načíst z ARES" button is visible directly below the IČO field. It's disabled until the field contains exactly 8 digits.
- Clicking the button when valid: shows "Načítání…" briefly, then either populates the company-info fields and shows "Údaje načteny z ARES" (success), or shows "IČO nebylo v ARES nalezeno" (404), or "Načítání údajů z ARES selhalo, zkuste to prosím později" (any 5xx/network/422), or the rate-limited variant for 429.
- All other form fields (name, email, phone, password, etc.) keep their values across an ARES lookup — verified manually by typing in those fields before clicking the button.
- When ARES has no VAT ID (`dic` missing), the form's VAT ID field keeps whatever the user typed; it is not cleared.

## Out of scope

- Adopting `h4kuna/ares` (rationale documented above).
- Auto-loading on blur / debounced input (explicit click is the ask, less surprising UX).
- Validating the IČO checksum client-side (server already validates on form submit).
- Handling EU VAT registry (VIES) lookups.
- Caching ARES responses (each call is a one-off; ARES itself is fast and our rate-limit is generous).
- Showing the ARES `datumAktualizace` ("last updated" date) in the success message.
- Detecting when the user manually edits a field after a successful ARES fill (no special UI for that).

## Open questions

None — proceed.
