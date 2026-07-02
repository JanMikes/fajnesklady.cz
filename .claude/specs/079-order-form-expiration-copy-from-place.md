# 079 — Order form: payment-deadline copy reads `place.orderExpirationDays` instead of hardcoded "7 dní"

**Status:** ready
**Type:** UX / copy fix
**Scope:** tiny (1 template + 1 test file)
**Depends on:** none (leftover from spec 017)

## Problem
Spec 017 made the order-expiration window configurable per place (`Place::$orderExpirationDays`, default 3, range 1–30) and `OrderService::createOrder()` correctly computes `Order::$expiresAt` from it (`src/Service/OrderService.php:126`). But the amber notice in the order form still says the pre-017 hardcoded copy: *"Máte **7 dní** na dokončení platby."* (`templates/components/OrderForm.html.twig:607`). A place configured to 3 days tells the customer they have 7 — legally and practically wrong (the order genuinely expires after 3).

Spec 017's "no template change needed" reasoning only covered surfaces that render the stored `order.expiresAt` timestamp; this notice renders **before** the order exists, so it was missed.

## Goal
The amber notice shows the actual configured window of the place being ordered from, with correct Czech pluralization: `1 den`, `2–4 dny`, `5–30 dní`. No other surface changes — everything else already renders `order.expiresAt` as a concrete date.

## Context (current state)
- `templates/components/OrderForm.html.twig:605-608` — the stale copy, inside the amber `bg-amber-50` info box:
  ```twig
  <span>
      Skladová jednotka bude přiřazena automaticky po vytvoření objednávky.
      Máte <strong>7 dní</strong> na dokončení platby.
  </span>
  ```
- `place` is already in the component's template scope (used at lines ~620–627: `place.mapImagePath`, `place.id`), so no PHP change is needed.
- `Place::$orderExpirationDays` (`src/Entity/Place.php:38`) — `int`, default 3, constrained to 1–30 by `Assert\Range` in `src/Form/PlaceFormData.php:36`. All three Czech plural forms are therefore reachable.
- No Czech pluralization helper exists in `src/Twig/` — don't create one for a single call site; inline the ternary.
- Surfaces verified as already correct (render the stored timestamp, no change):
  - `templates/public/order_payment.html.twig:110` — `Objednávka vyprší {{ order.expiresAt|date('d.m.Y H:i') }}`
  - `templates/email/order_placed.html.twig:158` — `platná do {{ expiresAt }}`
- Deliberate other constant, do NOT touch: `AdminOnboardingHandler::ONBOARDING_EXPIRATION_DAYS = 30` (`src/Command/AdminOnboardingHandler.php:21`) — admin-onboarded orders intentionally get a longer signing window.
- Not related (different concepts, all other "7 dní" grep hits): minimum rental duration of 7 days (`OrderFormType.php:166,207`), manual-billing reminder stages `d_plus_3`/`d_plus_7`, onboarding reminders D+2/D+5, recurring-payment 7-day advance-notice e-mails, fine reminders D+7/D+14.

## Requirements

### 1. `templates/components/OrderForm.html.twig` (~line 605)
Replace the hardcoded sentence with the place value + inline Czech plural:

```twig
<span>
    Skladová jednotka bude přiřazena automaticky po vytvoření objednávky.
    Máte <strong>{{ place.orderExpirationDays }} {{ place.orderExpirationDays == 1 ? 'den' : (place.orderExpirationDays <= 4 ? 'dny' : 'dní') }}</strong> na dokončení platby.
</span>
```

Keep the `<strong>` wrapping number + word together, keep the surrounding amber box untouched.

### 2. Test — `tests/Integration/Controller/Public/OrderCreateControllerTest.php`
Extend `testAnonymousCanLoadOrderPage()` (line 37) — or add a sibling test — asserting the rendered page contains the fixture place's value (fixtures use the default 3):

```php
self::assertStringContainsString('3 dny', (string) $this->client->getResponse()->getContent());
self::assertStringNotContainsString('7 dní na dokončení platby', (string) $this->client->getResponse()->getContent());
```

(A unit test of the ternary is pointless; the controller render covers the real surface.)

## Acceptance
- [ ] Order form for a place with `orderExpirationDays = 3` shows *"Máte **3 dny** na dokončení platby."*
- [ ] Pluralization: 1 → `1 den`, 2–4 → `dny`, 5+ → `dní` (verifiable by tweaking the value in a fixture-backed test or manually in dev).
- [ ] No remaining hardcoded "7 dní" tied to order-payment completion (grep `dokončení platby` in templates).
- [ ] `composer quality` green; **run full `composer test`** (template + controller-test change — quality alone skips integration tests).

## Out of scope
- Showing a concrete deadline date in the notice — the order doesn't exist yet at render time; a precomputed date would drift if the customer leaves the form open. Day count is the honest phrasing.
- A reusable Czech-plural Twig filter — one call site; add it only when a second consumer appears.
- `AdminOnboardingHandler::ONBOARDING_EXPIRATION_DAYS = 30` — deliberate longer window for admin-onboarded signing links, unrelated to the public order flow.
- All other "7 dní" occurrences (minimum rental length, billing/fine/onboarding reminder cadences, recurring-payment advance notice, VOP jistota clause) — different concepts, verified during discovery.

## Open questions
None — proceed.
