# 013 — Price label correctly reflects billing cadence (emails + UI)

**Status:** ready
**Type:** UX / wording fix
**Scope:** small (~9 files: 1 entity helper; 1 shared Twig partial; 2 email handlers + 2 email templates; 4 page templates updated to use the partial)
**Depends on:** none.

## Problem

Order emails always say **"Celková cena: 5 000,00 Kč"** regardless of rental type. For an unlimited rental this is the **monthly** rate, not a one-time total — the customer reads "5 000 Kč" and thinks they're done paying after one charge. For a limited rental of, say, 90 days, the same field still shows the **monthly** rate (5 000 Kč) labelled as "Celková cena" — also misleading: total commitment is 15 000 Kč, monthly charge is 5 000 Kč; neither is what the label promises.

Concretely the bug appears in:

- `templates/email/order_confirmation.html.twig:121-122` (subject "Potvrzení objednávky - {place}").
- `templates/email/order_cancelled.html.twig:111-112` (subject "Objednávka zrušena - {place}").

The same wording bug also exists in customer-facing web pages and internal portal/admin pages that read `order.totalPrice` and label it "Celková cena" — same shared `Order` field, same confused semantics. Fixing only the emails would leave the inconsistency on screen.

**Why the underlying value is actually fine.** `Order.totalPrice` (set in `OrderService.php:80` via `PriceCalculator::calculateFirstPaymentPrice`) already stores the **first-payment amount**:

| Rental type | `Order.totalPrice` content |
|---|---|
| Unlimited | Monthly rate |
| Limited < 28 days (one-time) | Full one-time price |
| Limited ≥ 28 days (recurring) | Monthly rate |

So the value is correct — only the **label** and the **per-month suffix** are wrong. No price recomputation is needed; this is a wording fix.

## Goal

Two clear scenarios, one display rule, applied identically in every customer-facing surface (and matching it in internal surfaces for consistency):

1. **One-time payment** (limited rental < 28 days) → label **"Celková cena"**, value **`X Kč`**.
2. **Monthly recurring** (unlimited OR limited ≥ 28 days) → label **"Měsíční platba"**, value **`X Kč / měsíc`**.

The lifetime sum of all future monthly payments is **never shown** — explicit user requirement.

## Context (current state)

### The truth about `Order.totalPrice`

- `src/Entity/Order.php:95` declares `private(set) int $totalPrice` (halíře).
- `src/Service/OrderService.php:80` populates it from `PriceCalculator::calculateFirstPaymentPrice` — i.e. **first-payment**, not a lifetime total.
- `src/Service/PriceCalculator.php:127-146` defines the cadence:
  - `null === $endDate` → monthly.
  - `days < 28` → one-time full price.
  - `days >= 28` → monthly.
- `src/Service/PriceCalculator.php:151-158` exposes `needsRecurringBilling(startDate, endDate): bool` with the same threshold.
- `Order::getTotalPriceInCzk()` (`Order.php:233-236`) returns `totalPrice / 100`.

### Where the misleading label appears today

**Emails (in scope — explicit user request):**
- `templates/email/order_confirmation.html.twig:121-122` — "Celková cena: {{ totalPrice }}".
- `templates/email/order_cancelled.html.twig:111-112` — same.

**Other customer-facing pages (in scope — same bug, shared `Order.totalPrice`):**
- `templates/public/order_accept.html.twig:87-93` (recapitulation; renders "Celková cena" with a brittle `if not formData.endDate` "/ měsíc" branch — only catches unlimited, misses limited ≥ 28 days).
- `templates/public/order_accept.html.twig:115` ("Cena" line in price summary block — same issue).
- `templates/public/order_payment.html.twig:65-72, 146` (pre-GoPay redirect screen — "Celková cena" and "Zaplatit X Kč" button label).
- `templates/public/order_complete.html.twig:62` (success page — "{{ order.totalPriceInCzk }}").
- `templates/public/customer_signing.html.twig:69-70` — already correct (`isRecurring ? 'Měsíční platba:' : 'Celková cena:'`), but missing the "/ měsíc" suffix on the value. Tiny tweak only.

**Portal / admin pages (in scope — same field, same bug, internal staff also misreads):**
- `templates/portal/user/order/detail.html.twig:113-118` ("Celková cena").
- `templates/portal/landlord/order/detail.html.twig:145-150` ("Celková cena").
- `templates/admin/order/detail.html.twig:147-148` ("Celková cena").

**List screens** (`portal/{user,landlord}/order/list.html.twig`, `admin/order/list.html.twig`, `portal/dashboard_*.html.twig`) — these render compact rows with no label, just `X Kč`. They show the same field but without a misleading caption. Out of scope (see below).

### Audit of other emails for the same bug

Grep’d every `templates/email/*.twig` for price strings. Only these render `Order.totalPrice` with a "Celková cena"-style label: `order_confirmation.html.twig`, `order_cancelled.html.twig`. Other price-bearing emails are different shapes:
- `payment_default_{tenant,admin}.html.twig` show **outstanding debt** — a real monetary debt, not a rental price; label "Dlužná částka" stays.
- `recurring_payment_failed*.html.twig` discuss failed-attempt counts, no price line.
- `contract_ready.html.twig` mentions "Platba přijata" but renders no Kč value — out of scope.
- `invoice.html.twig` is an invoice, with its own correct "Celková částka" / line items — out of scope.

So just two email templates need actual changes; the broader audit confirms there are no other hidden bug sites.

## Architecture

```
                          src/Entity/Order.php
                          ── new: isRecurring(): bool   (mirrors PriceCalculator::needsRecurringBilling)
                                ▲
                                │ (entities are already in template scope)
                                │
                ┌───────────────┼────────────────────────────────┐
                │                                                 │
templates/_price_label.html.twig             SendOrderConfirmationEmailHandler
templates/email/_price_label.html.twig       SendOrderCancelledEmailHandler
   ── tiny partial:                           ── pass `isRecurring: $order->isRecurring()`
   inputs: isRecurring (bool),                   into ->context([])
            priceCzk (float),
            includeLabel (bool, default true)
   output:  "Celková cena: 5 000 Kč"
       or   "Měsíční platba: 5 000 Kč / měsíc"
```

**Why two near-identical partials (web + email)?** The web partial uses Tailwind classes for spacing/typography and the existing CSS context; the email partial uses inline styles that survive Outlook (matching the convention already in `templates/email/contract_ready.html.twig`). The semantics are identical; the markup is not. Trying to share one partial across both would force one to wear the other's clothes.

## Requirements

### 1. `Order::isRecurring(): bool`

Add to `src/Entity/Order.php` next to the existing `isUnlimited()` (line 228):

```php
/**
 * Whether this order will be billed on a monthly recurring cadence.
 *
 * Mirrors {@see PriceCalculator::needsRecurringBilling()} so labels in
 * templates stay consistent with how the price was actually calculated:
 *  - Unlimited → recurring (monthly).
 *  - Limited ≥ 28 days → recurring (monthly).
 *  - Limited < 28 days → one-time.
 */
public function isRecurring(): bool
{
    if (null === $this->endDate) {
        return true;
    }

    return (int) $this->startDate->diff($this->endDate)->days >= 28;
}
```

The 28-day constant matches `PriceCalculator::WEEKLY_THRESHOLD_DAYS`. Inline it here rather than expose a public constant — keeping the threshold owned by `PriceCalculator` would force a service injection just to render a label, and `Order` already has the dates it needs to answer the question.

### 2. New email partial: `templates/email/_price_label.html.twig`

```twig
{# Inputs: isRecurring (bool), priceCzk (float|int)
   Used by order_confirmation, order_cancelled. Inline styles only — Outlook
   strips body <style> blocks; matches the rest of the email templates. #}
{% set formattedPrice = priceCzk|number_format(2, ',', ' ') ~ ' Kč' %}
{% if isRecurring %}
    <td>Měsíční platba:</td>
    <td><strong>{{ formattedPrice }} / měsíc</strong></td>
{% else %}
    <td>Celková cena:</td>
    <td><strong>{{ formattedPrice }}</strong></td>
{% endif %}
```

### 3. Update `SendOrderConfirmationEmailHandler` + `SendOrderCancelledEmailHandler`

Both handlers currently pre-format the price into the context (`'totalPrice' => number_format($order->getTotalPriceInCzk(), 2, ',', ' ').' Kč'`). Switch to passing the raw float + a recurring flag, so the partial owns formatting:

In `src/Event/SendOrderConfirmationEmailHandler.php:55`:

```php
->context([
    // …existing keys, but replace the totalPrice line with these two…
    'priceCzk' => $order->getTotalPriceInCzk(),
    'isRecurring' => $order->isRecurring(),
])
```

Same in `src/Event/SendOrderCancelledEmailHandler.php:44`.

Remove the pre-formatted `totalPrice` key from both contexts. The two templates are the only consumers of that key.

### 4. Update the two email templates

In **`templates/email/order_confirmation.html.twig:120-123`**, replace:

```twig
<tr>
    <td>Celková cena:</td>
    <td><strong>{{ totalPrice }}</strong></td>
</tr>
```

with:

```twig
<tr>
    {% include 'email/_price_label.html.twig' with {
        isRecurring: isRecurring,
        priceCzk: priceCzk,
    } only %}
</tr>
```

Same edit in **`templates/email/order_cancelled.html.twig:110-113`**.

### 5. Web/portal partial: `templates/_price_label.html.twig`

For non-email surfaces (Tailwind context). Same logic, different markup:

```twig
{# Inputs: isRecurring (bool), priceCzk (float|int),
            valueClass (string, optional, default 'font-medium text-accent'),
            labelClass (string, optional, default 'text-gray-600').
   Renders two inline spans: a label and a value. The caller wraps them in
   the layout it wants (flex, dl row, etc.). #}
{% set formattedPrice = priceCzk|number_format(2, ',', ' ') ~ ' Kč' %}
<span class="{{ labelClass ?? 'text-gray-600' }}">
    {{ isRecurring ? 'Měsíční platba' : 'Celková cena' }}
</span>
<span class="{{ valueClass ?? 'font-medium text-accent' }}">
    {{ formattedPrice }}{% if isRecurring %} / měsíc{% endif %}
</span>
```

### 6. Update web/portal/admin templates to use it

Six surfaces, all reading `Order` and the same field. Replace each "Celková cena" + price line with an include of `_price_label.html.twig`. The exact insertion depends on the surrounding markup (some use `<dl>`, some use `<div class="flex justify-between">`, some use a table row), but the pattern is uniform: kill the hard-coded label + value, drop in two spans.

**`templates/public/order_accept.html.twig`:**
- Line 87-93 (Shrnutí objednávky → "Cena" row): replace the `<span>Cena</span>` + price block. Use `{% include '_price_label.html.twig' with { isRecurring: order.isRecurring, priceCzk: totalPrice / 100 } only %}` — `totalPrice` here is the raw integer halíře already in scope from the controller (see `OrderAcceptController`); pass `order` from the same controller scope. **Verify** by reading `src/Controller/Public/OrderAcceptController.php` and confirming `order` is passed; if not, use `formData.endDate is null or formData.startDate.diff(formData.endDate).days >= 28` to derive `isRecurring` inline (less clean — prefer adding `order` to the context if missing).
- Line 115: same fix in the smaller "Cena" line of the price summary block.
- Line 557: this one already says "{{ totalPrice }} Kč / měsíc" inside an `{% if isRecurring %}` block — leave as-is, it's already correct.

**`templates/public/order_payment.html.twig`:**
- Lines 65-72 (the "Celková cena" `<dt>/<dd>` pair): replace with the partial.
- Line 146 (the "Zaplatit X Kč" button): change to `Zaplatit {{ priceFormatted }}{% if order.isRecurring %} (první platba){% endif %}`. The "(první platba)" suffix is important — the customer should know that for recurring rentals the button charges only the first month. Inline string; no partial needed.

**`templates/public/order_complete.html.twig:62`:** replace the `<span>` + price with the partial. The existing `flex justify-between` wrapper stays.

**`templates/public/customer_signing.html.twig:69-70`:** already conditional; just append `{% if isRecurring %} / měsíc{% endif %}` to the value to match the new wording rule. No partial needed (the local conditional is already there).

**`templates/portal/user/order/detail.html.twig:113-118`:** replace the `<dt>Celková cena</dt><dd>…</dd>` pair with the partial wrapped in the same `<dt>/<dd>` shells (so the surrounding `<dl>` grid layout stays intact). The dt/dd are the partial's two spans repurposed:

```twig
<dt class="text-sm font-medium text-gray-500">
    {{ order.isRecurring ? 'Měsíční platba' : 'Celková cena' }}
</dt>
<dd class="mt-1 text-sm text-gray-900 font-semibold">
    {{ order.totalPriceInCzk|number_format(2, ',', ' ') }} Kč{% if order.isRecurring %} / měsíc{% endif %}
</dd>
```

The partial gives you spans, but in `<dl>` contexts spans are nested inside `<dt>/<dd>` — fine, but inlining the two-line conditional here is less indirection. **Decision: keep the partial for places where the wrapper is flexible (page top of `order_accept`, `order_payment`, `order_complete`); inline the conditional for `<dl>`-based detail pages** (`order_detail` × 3). One include vs. four lines — wash. The duplication is bounded (3 nearly-identical blocks). Don't force a partial that fights the existing structural element.

**`templates/portal/landlord/order/detail.html.twig:145-150`:** identical inline replacement.

**`templates/admin/order/detail.html.twig:147-148`:** identical inline replacement.

### 7. Czech wording — final reference

| Scenario | Label | Value |
|---|---|---|
| Limited < 28 days | `Celková cena` | `1 500,00 Kč` |
| Limited ≥ 28 days | `Měsíční platba` | `5 000,00 Kč / měsíc` |
| Unlimited | `Měsíční platba` | `5 000,00 Kč / měsíc` |

Diacritics correct (`Měsíční`, `Celková`).

### 8. Tests

Two pieces:

- **Unit test** `tests/Unit/Entity/OrderTest.php` (extend or create): cover `isRecurring()` against three fixtures — unlimited (true), limited 14 days (false), limited 60 days (true). Use `MockClock` for `createdAt` and the existing `OrderFixtures` shape if convenient; raw entity construction with hardcoded UUIDs is fine for a unit test.
- **Integration test** `tests/Integration/Event/SendOrderConfirmationEmailHandlerTest.php` (extend): for an unlimited order, assert the rendered HTML contains the literal string `Měsíční platba` AND `/ měsíc` AND does NOT contain `Celková cena`. For a 14-day limited order, assert it contains `Celková cena` AND does NOT contain `Měsíční platba`. Use `MailerAssertionsTrait::getMailerMessages()` and read `$message->getHtmlBody()`. Mirror the same pair in `tests/Integration/Event/SendOrderCancelledEmailHandlerTest.php`.

The page templates are covered indirectly by the existing controller tests (no new fixtures needed); a manual walk-through in dev (see Acceptance) is the verification.

## Acceptance

- `docker compose exec web composer quality` is green.
- Email check (mailpit, dev environment):
  - Trigger an `OrderCreated` for an **unlimited** rental (e.g. complete the order form with `Pronájem na dobu neurčitou`). The "Potvrzení objednávky - …" email shows `Měsíční platba: 5 000,00 Kč / měsíc`. No occurrence of `Celková cena` in that template.
  - Trigger an `OrderCreated` for a **14-day** limited rental. The same email shows `Celková cena: <weekly-formula sum> Kč`. No occurrence of `Měsíční platba`.
  - Trigger an `OrderCreated` for a **90-day** limited rental. The email shows `Měsíční platba: 5 000,00 Kč / měsíc` (not 15 000 Kč as a sum, not 5 000 Kč labelled "Celková cena").
  - Trigger an `OrderCancelled` for any of the three above. The "Objednávka zrušena - …" email follows the same rule.
- Web check (Chrome on the dev box):
  - On `/objednavka/{place}/{type}/{storage}/prijmout` (recapitulation), the price line uses the same wording rule for all three rental types.
  - On `/objednavka/{order}/platba` (GoPay redirect screen), the price line and the "Zaplatit" button match. For recurring rentals the button reads `Zaplatit 5 000,00 Kč (první platba)`.
  - On `/objednavka/{order}/dokonceno` (success page), same wording.
  - On `/portal/objednavky/{order}`, `/portal/landlord/orders/{order}`, `/portal/admin/orders/{order}` — same wording. (Internal staff sees the same truth about what the customer was billed.)
- For an unlimited rental, the cancel email shows `Měsíční platba` even though the order was never paid — that's correct: it describes the cadence the customer would have been on, which is what `isRecurring` is for. (User explicitly requested no lifetime sum, so no separate "by jste zaplatili celkem X" line.)
- No regressions in `templates/email/{contract_ready,invoice,recurring_payment_*,payment_default_*}.html.twig`. Their price/debt wording stays untouched.

## Out of scope

- **Renaming `Order.totalPrice` → `Order.firstPaymentPrice` (column rename).** The field's name is misleading but renaming touches a migration, every command/query/repository reading it, every test fixture, and the JSON shape of `OrderCreated` events. Documented in this spec as a known wart; a separate refactor spec can address it.
- **Showing a lifetime-total estimate** (e.g. "Předpokládaný celkový závazek za 12 měsíců: 60 000 Kč") for limited recurring rentals. Explicitly excluded by the user. The contract DOCX itself states the rental term and monthly rate; that's enough.
- **List screens** (`portal/*/order/list.html.twig`, `admin/order/list.html.twig`, `portal/dashboard_*.html.twig`). They render a compact column with no caption — just `X Kč`. The same value is shown but without a misleading label, and adding a "/ měsíc" suffix to a list cell would crowd the layout. If staff want to disambiguate they can click into the detail.
- **Invoice emails / contract terms wording.** Invoices already itemise the right way (line items, subtotals); no change needed.
- **Translating to other languages.** Czech-only stack today.
- **Changing the GoPay charge amount** or the `PriceCalculator` formula. The number is correct; only the label was wrong.

## Open questions

None — proceed.
