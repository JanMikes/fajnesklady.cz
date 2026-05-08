# 034 — Fakturoid: stop double-counting VAT (prices include VAT, not net)

**Status:** done
**Type:** bug fix (critical — billing correctness)
**Scope:** tiny (3 call sites in 1 file + 1 unit test)
**Depends on:** none

## Problem

Customer is invoiced 726 Kč instead of the contracted 600 Kč/month.

Every price in fajnesklady.cz is stored and displayed **včetně DPH** (gross). `Storage.price`, `Order.firstPaymentPrice`, `Contract.individualMonthlyAmount` — all gross, in haléře. The compliance ruleset (`CLAUDE.md` → `.claude/COMPLIANCE.md`) makes this explicit: "Prices always display with `vč. DPH`."

But `App\Service\Fakturoid\FakturoidApiClient` passes those gross figures to Fakturoid as `unit_price` together with `vat_rate: 21` and **omits `vat_price_mode`**. Fakturoid's default for that field is `without_vat`, which means *unit_price is interpreted as net and 21 % VAT is added on top*. The invoice total therefore comes out as `gross × 1.21` — for the user reporting this bug, `600 × 1.21 ≈ 726` Kč.

This affects every order invoice and every recurring invoice ever issued through this codebase. Self-billing follows the same pattern; spec covers it for consistency.

## Goal

Fakturoid invoices match the gross amount stored in our system: a 600 Kč/month contract produces a 600 Kč invoice (with the 21 % VAT calculated **out of** that 600 — line `unit_price_without_vat = 495.87`, `vat = 104.13`, `total_with_vat = 600.00`). No customer-facing prices, emails, or templates change. Only what we send to Fakturoid changes.

## Context (current state)

- `src/Service/Fakturoid/FakturoidApiClient.php`
  - `createInvoice()` at `:112-162` — builds the customer order invoice. Sends `unit_price = $order->getFirstPaymentPriceInCzk()` (gross, in CZK). Sends `vat_rate: $this->vatRate`. **No `vat_price_mode`.**
  - `createRecurringInvoice()` at `:164-215` — same pattern, `unit_price = $amount / 100` (gross haléře → CZK).
  - `createSelfBillingInvoice()` at `:257-295` — same pattern, `unit_price = $invoice->getNetAmountInCzk()` (where "net" here means **post-commission landlord payout**, NOT "without VAT" — see "Out of scope" note 3 below). Same bug shape.
- `FakturoidApiClient::__construct(int $vatRate)` is wired from `FAKTUROID_VAT_RATE=21` (`.env:66`, `config/services.php:55`). The rate value itself is correct; it's the **mode** that's wrong.
- `App\Value\FakturoidInvoice` carries `total` populated from Fakturoid's response (`(int) round((float) $body->total * 100)`). After the fix, that response total will equal the gross figure we sent — which is what we want; the persisted `Invoice.amount` then equals our internal price.
- Mock `tests/Mock/MockFakturoidClient.php` already returns `total: $order->firstPaymentPrice` (i.e. the gross sent in), so existing tests already encode the post-fix behaviour and will keep passing. No mock changes needed.
- Existing unit test: `tests/Unit/Service/InvoicingServiceTest.php` — uses the mock, so won't catch the real-API mode bug. We need a focused unit test on `FakturoidApiClient` that asserts the payload shape sent to the SDK.

### Verified Fakturoid API behaviour

Pulled from https://www.fakturoid.cz/api/v3/invoices: invoice field `vat_price_mode`, allowed values `"without_vat"` (default — VAT added on top) and `"from_total_with_vat"` (unit_price interpreted as gross, VAT calculated **out of** it). `null` inherits from account settings — do **not** rely on that, set it explicitly per request so the behaviour is independent of the Fakturoid account configuration.

## Requirements

### 1. Send `vat_price_mode: 'from_total_with_vat'` on every invoice creation

In `src/Service/Fakturoid/FakturoidApiClient.php`, add the field to the payload of all three creation methods. Place it as a top-level field on the invoice (sibling of `subject_id`, `lines`, `note`), not inside the line.

**`createInvoice()` (around line 119):**

```php
$response = $this->manager->getInvoicesProvider()->create([
    'subject_id' => $subjectId,
    'vat_price_mode' => 'from_total_with_vat',
    'lines' => [
        [
            'name' => sprintf(
                'Pronájem skladového boxu %s - %s (%s)',
                $storage->number,
                $storageType->name,
                $place->name,
            ),
            'quantity' => 1,
            'unit_price' => $order->getFirstPaymentPriceInCzk(),
            'vat_rate' => $this->vatRate,
        ],
    ],
]);
```

**`createRecurringInvoice()` (around line 171):** identical addition — `'vat_price_mode' => 'from_total_with_vat'` as a sibling of `subject_id`/`lines`.

**`createSelfBillingInvoice()` (around line 261):** identical addition — keep the existing `document_type`, `number`, `note`; add `'vat_price_mode' => 'from_total_with_vat'`.

No other change to these methods. No constants, no extra method — three identical literal additions inline.

### 2. Unit test for the payload shape

Add `tests/Unit/Service/Fakturoid/FakturoidApiClientTest.php` covering the three create methods. The test must:

- Construct a `FakturoidApiClient` with a `FakturoidManager` whose `getInvoicesProvider()` returns a stub/spy that captures the array passed to `create()`.
- Assert that the captured payload contains `vat_price_mode === 'from_total_with_vat'` for each of `createInvoice`, `createRecurringInvoice`, `createSelfBillingInvoice`.
- Assert that `unit_price` equals the gross CZK figure (e.g. `600.0` for a 60_000-haléře `firstPaymentPrice`) and `vat_rate` equals the configured rate (use 21).
- The stub's `create()` should return a minimal `Response` whose `getBody()` is an `\stdClass` with `id`, `number`, `total` so the method completes — pattern any of the existing integration tests for Fakturoid SDK responses if helpful, but a hand-rolled stub is fine here since this is a unit test.

This is the only test that proves the bug stays fixed against the real `FakturoidManager` interface — `MockFakturoidClient` short-circuits the SDK entirely.

### 3. Add a one-line comment above each `vat_price_mode` line

```php
// Prices in our system are gross (vč. DPH); Fakturoid must back-calculate VAT, not add it on top.
'vat_price_mode' => 'from_total_with_vat',
```

This is the kind of "would surprise a reader" the comment policy in `CLAUDE.md` calls for: anyone glancing at the payload would otherwise assume Fakturoid's default is fine.

## Acceptance

- [ ] All three `create...Invoice` methods in `FakturoidApiClient` send `vat_price_mode: 'from_total_with_vat'`.
- [ ] New unit test `FakturoidApiClientTest` proves it for each method.
- [ ] `docker compose exec web composer quality` is green.
- [ ] `docker compose exec web composer test` is green (full integration suite — controller/template tests must keep passing because customer-facing totals don't move; only the figure Fakturoid returns moves, and the mock already mirrors the post-fix behaviour).
- [ ] Manual verification (out-of-band, by the operator): a smoke order in the Fakturoid sandbox account produces an invoice whose `total_with_vat` equals the gross we sent, with VAT calculated *from* it (not added).

## Out of scope

1. **Backfill / correction of already-issued wrong invoices.** Invoices already delivered to customers (with the wrong 21 % uplift) are documents in the customer's hands and in Fakturoid's accounting ledger. Correcting them is a manual, per-customer reconciliation in the Fakturoid UI (issue an opravný daňový doklad / credit note + re-issue). Doing it from code is risky and unreversible. The operator handles those one by one.
2. **Customer-facing UI / email changes.** None of the gross figures the customer sees move. The only thing that changes is the breakdown line on the Fakturoid PDF.
3. **Self-billing VAT semantics for non-VAT-payer landlords.** A separate, pre-existing concern: `FAKTUROID_VAT_RATE=21` is hard-coded for self-billing too, even though many landlords are non-VAT-payers and their commission invoice should carry `vat_rate: 0` (mode irrelevant in that case). That's its own fix — track separately if/when it bites. **This spec only flips the mode; it does not touch the rate.** The mode flip is still correct for VAT-payer landlords (their commission figure is gross like the customer's) and inert for non-VAT-payers (with `vat_rate: 0`, mode doesn't matter).
4. **Storing `vat_price_mode` as configuration.** The value is a domain invariant of how this system models prices, not an operational toggle. Inline literal is correct; no env var, no service argument.

## Open questions

None — proceed.
