# 038 — Address formatter + "Navigovat" CTA for places without street

**Status:** done
**Type:** UX / bug fix
**Scope:** medium (~14 files: 1 new PHP service + 1 new Twig extension + 1 new shared partial + 6 email handlers + 6 templates touched + 2 unit tests)
**Depends on:** none

## Problem

When a `Place` has no street address but does have GPS coordinates (e.g. `PlaceFixtures::REF_PLZEN` — `address: null`, `latitude: '49.7437572'`, `longitude: '13.3799330'`), the address line renders as malformed text like:

- `, 301 00 Plzen` (templates that do `{{ place.address }}, {{ place.postalCode }} {{ place.city }}`)
- `, Plzen` (admin/place/list)
- `Sklad Plzen - ` (storage canvas — `{{ place.name }} - {{ place.address }}`)

In emails the same `sprintf('%s, %s %s', $place->address, $place->postalCode, $place->city)` produces leading-comma garbage in the body of 6 different notification templates that customers read.

On top of that, the coordinates that ARE present go unused — there's no surfaced way for the customer to navigate to a coord-only place. A handful of templates (`partials/place_detail_content.html.twig`, `public/place_pricelist.html.twig`, `templates/portal/user/order/detail.html.twig`) hand-roll their own Google-Maps `?q=lat,lng` links, but the pattern is duplicated and inconsistent ("Zobrazit na mapě" vs "Navigovat") and missing on the order detail / public status / accept pages and in every customer email.

## Goal

Two things, applied consistently:

1. **Fix the malformed address line everywhere.** A null `address` never produces a leading-comma artefact. Falls back to `{postalCode} {city}` when no street, plus a Navigovat CTA so the coords become reachable.
2. **Surface a Navigovat CTA** (Google Maps navigation intent, opens the Maps app on mobile / Maps web on desktop) on every order surface the customer sees AND in every customer-facing notification email. Single helper + single shared partial — no more hand-rolled `https://maps.google.com/?q=…` strings scattered across templates.

## Context (current state)

### The entity already carries everything

- `src/Entity/Place.php:82` — `address: ?string` (nullable; can legitimately be null).
- `src/Entity/Place.php:85` — `city: string` (always present).
- `src/Entity/Place.php:87` — `postalCode: string` (always present).
- `src/Entity/Place.php:20` / `:23` — `latitude` / `longitude: ?string` (DECIMAL 10,7 nullable; both null or both set in practice).
- `src/Entity/Place.php:98` — existing `hasAddress(): bool` helper. We add no new helpers on `Place` itself; formatting is presentation, not domain.

### Duplicated "address line" spellings to replace

Twig templates that currently render an address line:

| File | Line | Current | Bug? |
|---|---|---|---|
| `templates/portal/user/order/detail.html.twig` | 186 | `{{ place.address }}, {{ place.postalCode }} {{ place.city }}` | yes (leading `, `) |
| `templates/portal/landlord/order/detail.html.twig` | 192 | `{{ place.address }}, {{ place.postalCode }} {{ place.city }}` | yes |
| `templates/admin/order/detail.html.twig` | 212 | `{{ place.address }}, {{ place.postalCode }} {{ place.city }}` | yes |
| `templates/public/order_status.html.twig` | 101, 251 | same | yes (twice on same page) |
| `templates/public/order_accept.html.twig` | 73 | `{{ place.address }}, {{ place.city }}` | yes |
| `templates/admin/place/list.html.twig` | 45 | `{{ place.address }}, {{ place.city }}` | yes |
| `templates/portal/storage/canvas.html.twig` | 17 | `{{ place.name }} - {{ place.address }}` | yes (trailing dash) |
| `templates/portal/place/list.html.twig` | 64 | `place.address ?: place.city` | partial — loses postal |
| `templates/portal/place/edit.html.twig` | 38-41 | `{% if place.address %} … {% else %} … {% endif %}` | ok already |
| `templates/portal/place/detail.html.twig` | 53-56 | same `{% if %}` pattern | ok already |
| `templates/partials/place_detail_content.html.twig` | 21-46 | same + Navigovat button | ok already (reference design) |
| `templates/partials/places_table.html.twig` | 20-23 | same `{% if %}` pattern | ok already |
| `templates/public/place_pricelist.html.twig` | 18-28 | same + Navigovat button | ok already |
| `templates/user/home.html.twig` | 101-104 | same `{% if %}` pattern | ok already |
| `templates/public/customer_signing.html.twig` | 42 | `{{ place.name }}, {{ place.city }}` | safe (no address used) |
| `templates/portal/storage_type/list.html.twig` | 17 | `{{ place.name }} ({{ place.city }})` | safe |

### Email handlers — the most exposed bug

Six PHP handlers do `sprintf('%s, %s %s', $place->address, $place->postalCode, $place->city)`, producing `, 301 00 Plzen` in the customer's inbox:

| Handler | File | Email template using `placeAddress` |
|---|---|---|
| `SendOrderPlacedEmailHandler` | `src/Event/SendOrderPlacedEmailHandler.php:45` | `email/order_placed.html.twig:102` |
| `SendRentalActivatedEmailHandler` | `src/Event/SendRentalActivatedEmailHandler.php:134` | `email/rental_activated.html.twig:107` |
| `SendOrderCancelledEmailHandler` | `src/Event/SendOrderCancelledEmailHandler.php:41` | `email/order_cancelled.html.twig:92` |
| `SendContractExpiringReminderHandler` | `src/Event/SendContractExpiringReminderHandler.php:58` | `email/contract_expiring.html.twig:144` |
| `SendStorageAvailabilityWarningHandler` (customer) | `src/Event/SendStorageAvailabilityWarningHandler.php:82` | `email/storage_availability_warning.html.twig:102` |
| `SendStorageAvailabilityWarningHandler` (admin summary) | `src/Event/SendStorageAvailabilityWarningHandler.php:114` | `email/storage_availability_warning_admin.html.twig:98` |

### One PHP usage that is already safe

`src/Controller/Admin/AdminPlaceExportController.php:71` does `trim(sprintf('%s, %s %s', (string) $place->address, $place->postalCode, $place->city), ', ')` — Excel cell, already trimmed. Refactor it to use the new service for consistency, but it's not a bug.

### Map URL conventions today

- Display-only "Zobrazit na mapě" → `https://maps.google.com/?q={lat},{lng}` (used 5 times).
- No navigation-intent URL is used anywhere. Spec switches to Google's documented navigation URL: `https://www.google.com/maps/dir/?api=1&destination={lat},{lng}` (opens turn-by-turn directions on every platform).
- Fallback when coords are null but a full address exists: `https://www.google.com/maps/dir/?api=1&destination={urlencoded "address, postalCode city"}`.
- When neither coords nor street are usable (only postal+city), no Navigovat button — there's nothing useful to route to.

### Fixture for testing the null-address path

`PlaceFixtures::REF_PLZEN` (`fixtures/PlaceFixtures.php:100`) is already configured as a place with `address: null` and coordinates `49.7437572, 13.3799330`. Use it directly in integration / unit tests — do not fabricate.

## Architecture

```
   ┌──────────────────────────────────────────────────────────────┐
   │  src/Service/Place/PlaceAddressFormatter.php (new)            │
   │  ──── format(Place): string                                    │
   │       "Revolucni 1, 110 00 Praha"   (address present)         │
   │       "301 00 Plzen"                (address null)            │
   │       "Plzen"                       (postalCode empty edge)   │
   │  ──── navigationUrl(Place): ?string                            │
   │       coords present  → dir-intent URL with destination=lat,lng│
   │       coords null,    → dir-intent URL with destination=urlenc │
   │         address+postal+city present                            │
   │       otherwise       → null  (don't render the CTA)           │
   │  ──── hasNavigation(Place): bool   convenience for templates   │
   └──────────────────────────────────────────────────────────────┘
                  ▲                                ▲
                  │                                │
   ┌──────────────────────────────┐   ┌─────────────────────────────┐
   │ src/Twig/PlaceAddressExtension│   │ 6 email handlers             │
   │ ── place_address(place)       │   │ ── placeAddress = formatter  │
   │ ── place_navigation_url(place)│   │      ->format($place)        │
   │ ── place_has_navigation(place)│   │ ── placeNavigationUrl =      │
   └──────────────────────────────┘   │      formatter->navigationUrl│
                  ▲                    │      ($place)                │
                  │                    └─────────────────────────────┘
   ┌──────────────────────────────────────────────────────────────┐
   │ templates/components/place_address.html.twig (new partial)    │
   │ ── inputs: place (Place), with_navigate (bool, default true)  │
   │ ── renders the address line + optional Navigovat button       │
   │ ── used by every customer-facing surface + storage canvas     │
   │ ── inline mode (compact, no button) used by list/detail rows  │
   │      via with_navigate=false                                   │
   └──────────────────────────────────────────────────────────────┘
                  ▲
                  │
   ┌──────────────────────────────────────────────────────────────┐
   │ templates/email/_place_navigate_button.html.twig (new partial)│
   │ ── inputs: url (string, navigation URL — caller ensures !null)│
   │ ── renders a 1-row table button (email-safe inline CSS)       │
   │ ── used by all 6 e-mail templates that already show address   │
   └──────────────────────────────────────────────────────────────┘
```

Why two partials, not one shared? Email HTML is its own beast (no Tailwind, inline CSS, table-based buttons). The on-page partial uses the project's standard btn classes. Sharing would mean either ugly emails or ugly UI — keep them separate, the address-formatter service is the actual dedup point.

## Requirements

### 1. New service: `App\Service\Place\PlaceAddressFormatter`

`src/Service/Place/PlaceAddressFormatter.php` — pure, stateless, no constructor dependencies, `final readonly`.

```php
namespace App\Service\Place;

use App\Entity\Place;

final readonly class PlaceAddressFormatter
{
    private const GOOGLE_NAVIGATION_URL = 'https://www.google.com/maps/dir/?api=1&destination=%s';

    /**
     * Render the place's address as a single human line. Never returns a
     * string with a leading "," — when {@see Place::$address} is null we
     * fall back to "{postalCode} {city}". Returns trimmed output.
     */
    public function format(Place $place): string
    {
        if (null !== $place->address && '' !== $place->address) {
            return sprintf('%s, %s %s', $place->address, $place->postalCode, $place->city);
        }

        return trim(sprintf('%s %s', $place->postalCode, $place->city));
    }

    /**
     * Google Maps navigation-intent URL (opens turn-by-turn). Prefers GPS
     * coordinates (always unambiguous) when present, otherwise builds a
     * destination from the full street address. Returns null when there is
     * nothing useful to route to (no coords AND no street) so callers can
     * hide the CTA entirely.
     */
    public function navigationUrl(Place $place): ?string
    {
        if (null !== $place->latitude && null !== $place->longitude) {
            return sprintf(self::GOOGLE_NAVIGATION_URL, $place->latitude.','.$place->longitude);
        }

        if (null !== $place->address && '' !== $place->address) {
            $destination = sprintf('%s, %s %s', $place->address, $place->postalCode, $place->city);

            return sprintf(self::GOOGLE_NAVIGATION_URL, rawurlencode($destination));
        }

        return null;
    }

    public function hasNavigation(Place $place): bool
    {
        return null !== $this->navigationUrl($place);
    }
}
```

No services.php wiring needed — autowiring handles it (no constructor args). Confirm `config/services.php` `App\` autowire-import already covers `src/Service/Place/`.

### 2. New Twig extension: `App\Twig\PlaceAddressExtension`

`src/Twig/PlaceAddressExtension.php`. Mirrors the shape of existing `RoleLabelExtension` / `UploadExtension`.

```php
namespace App\Twig;

use App\Entity\Place;
use App\Service\Place\PlaceAddressFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PlaceAddressExtension extends AbstractExtension
{
    public function __construct(
        private readonly PlaceAddressFormatter $formatter,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('place_address', $this->formatter->format(...)),
            new TwigFunction('place_navigation_url', $this->formatter->navigationUrl(...)),
            new TwigFunction('place_has_navigation', $this->formatter->hasNavigation(...)),
        ];
    }
}
```

Type-hints flow through to Twig — calling `place_address(someNonPlace)` fails loudly at compile/render time, which is the right behaviour.

### 3. New on-page partial: `templates/components/place_address.html.twig`

The single rendering surface used by every order/place page we touch.

```twig
{# Inputs:
   - place: App\Entity\Place                    (required)
   - with_navigate: bool   (default true)       (false → inline-only, no button row)
   - inline: bool          (default false)      (true → no <div> wrapper, just the text)
   - link_label: string    (default 'Navigovat')
#}
{% set _addr = place_address(place) %}
{% set _nav  = place_navigation_url(place) %}

{% if inline ?? false %}{{ _addr }}{% else %}
<div class="space-y-2">
    <div>{{ _addr }}</div>
    {% if (with_navigate ?? true) and _nav %}
        <a href="{{ _nav }}" target="_blank" rel="noopener" class="link inline-flex items-center text-sm">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
            </svg>
            {{ link_label|default('Navigovat') }}
        </a>
    {% endif %}
{% endif %}
```

Icon copied verbatim from the existing `partials/place_detail_content.html.twig:40-43` Navigovat button — same visual.

### 4. New email partial: `templates/email/_place_navigate_button.html.twig`

Inline-CSS table-button (email-safe). Used as a sibling row directly under the address row in each email template.

```twig
{# Inputs:
   - url: string (caller ensures non-null)
   - label: string (default 'Navigovat')
#}
<tr>
    <td colspan="2" style="padding-top: 8px;">
        <a href="{{ url }}"
           target="_blank"
           rel="noopener"
           style="display: inline-block; padding: 8px 16px; background-color: #2563eb; color: #ffffff; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 500;">
            {{ label|default('Navigovat') }}
        </a>
    </td>
</tr>
```

Brand colour `#2563eb` matches the existing primary blue used in other email CTAs (verify against current email theme palette in any existing template; if a `_button` partial already exists, reuse it — grep `templates/email` for `display: inline-block` first).

### 5. Replace inline address renders with the partial — on-page

All seven buggy sites and the four already-safe `{% if place.address %}` sites collapse to:

```twig
{% include 'components/place_address.html.twig' with {place: place} only %}
```

Per-file mapping:

- `templates/portal/user/order/detail.html.twig`: replace lines 186 + 190-200 (the duplicated "Zobrazit na mapě" link block) with a single include. Drop the existing standalone map link below — the partial already includes Navigovat.
- `templates/portal/landlord/order/detail.html.twig`: replace line 192 with the include.
- `templates/admin/order/detail.html.twig`: replace line 212 with the include.
- `templates/public/order_status.html.twig`: replace line 101 AND line 251 (both occurrences, distinct contexts on the same page).
- `templates/public/order_accept.html.twig`: replace line 73 with the include (inline mode acceptable here since it's a compact summary row — pass `with_navigate: false, inline: true`; the customer hasn't paid yet, navigation CTA is premature).
- `templates/admin/place/list.html.twig`: replace line 45 with `{% include … with {place: place, inline: true} only %}` — list row, no button.
- `templates/portal/storage/canvas.html.twig`: replace line 17. This case is special — original format was `{name} - {address}`. New: keep the name as `{{ place.name }}` then render the partial in `inline: true, with_navigate: false` mode after a dash if and only if `place_address(place)` produces non-empty text different from the bare `{city}` form. Simpler: always show `{{ place.name }} — {{ place_address(place) }}` directly using the Twig function (no partial needed for one line). Operator screen — Navigovat CTA explicitly out of scope here.

For the four already-safe sites (`portal/place/list.html.twig:64`, `portal/place/edit.html.twig:38`, `portal/place/detail.html.twig:53`, `partials/place_detail_content.html.twig:21`, `partials/places_table.html.twig:20`, `public/place_pricelist.html.twig:18`, `user/home.html.twig:101`) — replace their hand-rolled `{% if place.address %} … {% else %} … {% endif %}` blocks with the include for consistency. They produce the same visible output today but next time wording changes they'd drift. Pass `with_navigate: false` on list rows and `inline: true` where appropriate.

### 6. Email handlers — pass `placeNavigationUrl` and fix `placeAddress`

For each of the 6 handlers in §Context, inject `PlaceAddressFormatter` and change the `context()` payload:

```php
public function __construct(
    // existing deps …
    private readonly PlaceAddressFormatter $addressFormatter,
) {}

// inside __invoke …
'placeAddress' => $this->addressFormatter->format($place),
'placeNavigationUrl' => $this->addressFormatter->navigationUrl($place),
```

Then in each of the 6 email Twig files, directly under the `Adresa` row (`<td>{{ placeAddress }}</td>`), add:

```twig
{% if placeNavigationUrl %}
    {% include 'email/_place_navigate_button.html.twig' with {url: placeNavigationUrl} only %}
{% endif %}
```

For `storage_availability_warning_admin.html.twig:98` (the inline-paragraph admin-summary email, not a table), insert the button as a sibling `<p>` rather than `<tr>`. Either inline-style the `<a>` directly there or split off `_place_navigate_link_inline.html.twig` — pick what reads cleanest while implementing.

### 7. Refactor `AdminPlaceExportController:71`

Replace the inline `trim(sprintf('%s, %s %s', …), ', ')` with `$this->addressFormatter->format($place)`. Inject the service into the controller's constructor. No new dependency rules; controllers may use the service directly.

### 8. Tests

`tests/Unit/Service/Place/PlaceAddressFormatterTest.php`:

- `format()` with address → `"Revolucni 1, 110 00 Praha"`.
- `format()` with `address: null` → `"301 00 Plzen"` (no leading comma).
- `format()` with `address: ''` (defensive) → `"301 00 Plzen"`.
- `navigationUrl()` with coords → `https://www.google.com/maps/dir/?api=1&destination=49.7437572,13.3799330`.
- `navigationUrl()` no coords but address present → `https://www.google.com/maps/dir/?api=1&destination=Revolucni%201%2C%20110%2000%20Praha`.
- `navigationUrl()` no coords, no address → `null`.
- `hasNavigation()` mirrors the three branches above.

Use `PlaceFixtures::REF_PLZEN` data shape (instantiate `new Place(…)` in the test — no DB needed) plus a Praha-style address-present case.

`tests/Integration/Controller/Public/OrderStatusControllerTest.php` (or the existing nearest test) — extend with one assertion: rendering an order whose `place` has `address: null` does NOT contain the literal substring `, ,` or a `<dd>` starting with `, `. The Plzen fixture already gives us such a place — wire an order against `Storage` at `REF_PLZEN`. If no such fixture order exists yet, this is a one-line addition to `OrderFixtures` (a new Plzen-storage order) rather than spec scope creep — note it explicitly here so the dev doesn't expand.

## Acceptance

- [ ] Loading `/objednavka/{plzeń-order}/stav` shows `301 00 Plzen` (no leading comma) and a "Navigovat" button. Clicking it opens `https://www.google.com/maps/dir/?api=1&destination=49.7437572,13.3799330` in a new tab.
- [ ] Same order's `/portal/objednavky/{id}` (authenticated portal) shows the same address line + Navigovat CTA. The previous separate "Zobrazit na mapě" link is gone (replaced by Navigovat).
- [ ] Admin order detail (`/portal/admin/orders/{id}`) shows the address line correctly even for the Plzeń order; landlord order detail same.
- [ ] `templates/admin/place/list.html.twig` row for Plzeń shows `Plzen` (no `, Plzen`).
- [ ] `templates/portal/storage/canvas.html.twig` heading for the Plzeń place reads `Sklad Plzen — 301 00 Plzen` (no trailing dash).
- [ ] Triggering each of the 6 emails for an order against Plzeń (via fixtures + a focused integration test) puts `301 00 Plzen` in the body, no leading comma, plus a "Navigovat" button below the address row.
- [ ] Triggering the same emails for a Praha order leaves the existing `Revolucni 1, 110 00 Praha` rendering unchanged and adds a Navigovat button.
- [ ] `composer test` (full suite — `quality` skips integration tests, see memory `feedback_quality_runs_full_test`) is green.
- [ ] `composer quality` is green.

## Out of scope

- **Storage canvas Navigovat CTA**: user explicitly didn't pick the canvas option in question 3. Fix the malformed text only; no button.
- **Place list / place detail Navigovat CTAs**: same — not picked. The existing button on `partials/place_detail_content.html.twig` stays as-is for now (we don't remove it; the place-detail page is one of the already-safe surfaces).
- **Customer signing page** (`templates/public/customer_signing.html.twig:42`): uses `place.name, place.city` — no address field used, no bug, no change.
- **Storage type list** (`templates/portal/storage_type/list.html.twig:17`): same — uses `place.city` only, safe.
- **Mapy.cz / Apple Maps alternative URLs**: user chose Google Maps only.
- **Embedding a static map image** for coord-only places: out of scope. We have `Place::$mapImagePath` already (handled elsewhere) and the Navigovat link is sufficient.
- **Showing raw GPS coordinates as text** (e.g. "GPS: 49.7547, 14.2398"): user picked the cleaner option (B-vs-A in question 2 → "{postalCode} {city}" + Navigovat only).
- **Geocoding the city back to coords** when a place has only `city` but no coords and no address: not worth the API budget; if neither is available, just show `{city}` with no button — visible in `navigationUrl()`'s `null` branch.
- **Renaming the existing "Zobrazit na mapě" link on `place_detail_content.html.twig` to "Navigovat"**: keep both labels available — the partial defaults to "Navigovat", but callers can pass `link_label: 'Zobrazit na mapě'` if a specific surface needs the older verb. The existing place-detail page keeps its current button untouched to avoid scope creep.

## Open questions

None — proceed.
