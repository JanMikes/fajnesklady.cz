# 085 — Prepaid onboarding: tell the customer what they'll pay, and fix the forced-EXTERNAL billing-mode drift

**Status:** done
**Type:** bugfix + UX/content
**Scope:** medium (~17 files: 2 entity helpers, 1 handler fix + command slim-down, 2 cron/handler guards, 1 data migration, 3 content VOs, 1 controller, 3 templates, ~6 test files)
**Depends on:** none (builds on specs 025 / 036 / 043 / 051 / 076)

## Problem

Direct user feedback from a real migration case (order `019f32f8-e7d0-7347-b4f3-f20f3eae0ea7` on production, signing page `/podpis/a0db185b…`). An existing customer from the old system is onboarded with a short external prepayment (`paidThroughDate = 2026-07-05`, i.e. days). At signing time the customer is told only *"Po podpisu smlouvy nemusíte nic platit. Po vypršení předplatného Vás kontaktujeme s pokyny pro další platby."* — **no amount, no date, no payment method, no next steps**. The price row on the signing page, in the signing e-mail, on the completion page and in the rental-activated e-mail is gated to `gopay_first_charge`, so a prepaid customer signs a multi-year contract without ever seeing the 1 820 Kč/month they'll owe from the day after the prepaid window ends. This is wrong: the system knows *exactly* what happens next (see Context), so "Vás kontaktujeme" is both vague and false.

While verifying the production case a second, harder bug surfaced: **`AdminOnboardingHandler` forces `paymentMethod` to EXTERNAL for prepaid/free orders but keeps the form-derived `billingMode`**. When the admin picked GOPAY (meaning "customer migrates to our payment system later"), the form derived `AUTO_RECURRING`; the handler then flipped the method to EXTERNAL, producing `external + auto_recurring` orders. Production has **8 such orders** (6 `created`, 2 `completed`, verified via SQL on lily). Their contracts hold no GoPay token, so `ProcessRecurringPaymentsCommand` never charges them, `findManualBillingCandidates()` filters them out (MANUAL/ONE_TIME only), **nobody ever asks the customer to pay, and `TerminateOverdueContractsCommand` terminates them for payment failure ~7 days after `nextBillingDate`** (= `paidThroughDate + 1`). The reported order's `nextBillingDate` is 2026-07-06 — the sweep would kill it around 2026-07-13.

## Goal

1. Every customer-facing surface of a prepaid onboarding (signing e-mail, signing page, completion page, rental-activated e-mail, `/stav` billing banner) states the full story: prepaid until *X*; from *X+1* the rent is *N* Kč / month (vč. DPH); before each due date we e-mail a payment request with bank details + QR code; or — when the prepayment covers the whole term — "no further payments".
2. Prepaid onboardings always land on the manual-billing track (`MANUAL_RECURRING`), regardless of which payment-method radio the admin picked; the 8 broken production rows are repaired by migration so no customer gets wrongly terminated.

## Context (current state)

**Billing mechanics for prepaid onboarding (all verified in code + prod DB):**

- `src/Form/AdminOnboardingFormData.php:304` — `deriveBillingMode()` callback sets `billingMode = BillingMode::derive(paymentMethod, frequency, rentalDays)`. `derive()` (`src/Enum/BillingMode.php`) maps GOPAY → AUTO_RECURRING, BANK_TRANSFER/EXTERNAL → MANUAL_RECURRING (monthly, ≥31 d).
- `src/Twig/Components/AdminOnboardingForm.php:436` — submit passes `$formData->billingMode` into `AdminOnboardingCommand`.
- `src/Command/AdminOnboardingHandler.php:65-73` — **the bug**: `$order->setBillingMode($command->billingMode)` runs *before* the force-EXTERNAL block: `$forceExternal = (free || paidThroughDate) && !hasDebt; $effectivePaymentMethod = $forceExternal ? EXTERNAL : $command->paymentMethod`. Billing mode is never re-derived from the effective method.
- `src/Service/OrderService.php:230-232` — `completeOrder()` calls `Contract::markExternallyPrepaid($order->paidThroughDate)`.
- `src/Entity/Contract.php:581` — `markExternallyPrepaid()` sets `nextBillingDate = paidThroughDate + 1 day` **unconditionally, even past `endDate`** (secondary defect: a contract prepaid to exactly `endDate` gets a phantom billing anchor at `endDate + 1`; the manual cron can fire a payment request for a period after contract end, and the overdue sweep can terminate an already-expired fully-prepaid contract).
- `src/Command/CompleteOrderHandler.php:53-71` — seeds `nextBillingDate` for MANUAL contracts *without* prepayment; **no `isFree()` guard**, so a free contract that arrives with MANUAL mode gets a billing anchor and later a 0 Kč payment-request e-mail (`RecurringAmountCalculator` has no zero guard either).
- `src/Console/SendManualBillingPaymentRequestsCommand.php:56` — loop over `findManualBillingCandidates()` (`src/Repository/ContractRepository.php:988`, filters `billingMode IN (manual_recurring, one_time)`); **no `isFree()` skip** (contrast `TerminateOverdueContractsCommand:62`, which has one).
- After the prepaid window: `DispatchManualBillingNotificationHandler` sends bank-transfer payment requests (QR + variable symbol, lazily generated at `:94`) at the place-configured offsets snapshotted on the order (`Order::$manualBillingOffsetInitial = -7` default, `src/Entity/Order.php:111`). **This is the true "next step" the customer must be told about.**
- `SendExternalPrepaymentEndingSoonCommand` deliberately skips MANUAL-track contracts (`ContractRepository::findExternalPrepaymentsEndingInRange:923`) — so no other e-mail covers this; the manual-billing requests are the only channel.

**Customer-facing surfaces (spec 043 content VOs — the seams to extend):**

- `src/Service/Order/CustomerBillingSituation.php` — 3-case enum; `EXTERNALLY_PREPAID` = `paidThroughDate !== null` (order) / `paidThroughDate && !goPayParentPaymentId` (contract).
- `src/Service/Order/SigningPriceViewModel.php` — feeds the signing page; `templates/public/customer_signing.html.twig:40-50` (banner with the "Vás kontaktujeme" copy) and `:93-99` (price row gated to `gopay_first_charge`).
- `src/Service/Order/SigningEmailContent.php:37-48` — prepaid branch sets `monthlyPriceInHaler: 0` and the vague `nextStepLine`; rendered by `templates/email/signing_link.html.twig:90-95` (banner) and `:141-146` (price row, again `gopay_first_charge`-gated).
- `src/Controller/Public/CustomerSigningCompleteController.php:44-53` — prepaid completion body: "Žádná další akce není potřeba."
- `src/Service/Order/RentalActivatedEmailContent.php:33-42` — prepaid body: "…Vás kontaktujeme s pokyny pro další platby."
- `templates/components/customer_billing_status.html.twig:66-79` — portal/`/stav` blue "Platby probíhají ručně" box (manual track). Shows mechanism + next date but **no amount** and no paid-through info (the spec-030 prepaid banners at `:88-119` are unreachable for manual-track contracts because the manual branch wins).
- `Order::$firstPaymentPrice` (`src/Entity/Order.php:184`, column `total_price`) is the locked-in per-cycle amount for recurring orders — monthly rate for MONTHLY (admin override honoured), yearly rate for YEARLY. `Contract::getEffectiveRecurringAmount()` (`src/Entity/Contract.php:553`) is the contract-side equivalent.
- Signing flow controller: `src/Controller/Public/CustomerSigningController.php` — `computeContext()` gates (`isPayFlow`), `renderForm()` passes `priceViewModel`.

**Production data (lily, `fajnesklady-db-1`, DB `fajnesklady`):**

```
payment_method | billing_mode     | status    | count
external       | auto_recurring   | completed | 2      ← contracts exist, orphaned
external       | auto_recurring   | created   | 6      ← incl. the reported order
external       | manual_recurring | completed | 1      ← correct (admin picked EXTERNAL radio)
external       | manual_recurring | created   | 3
```

Deployed revision at analysis time = `a888c5a` = local HEAD, so all file:line refs above match production.

**Existing tests to extend:** `tests/Unit/Service/Order/{SigningPriceViewModelTest,SigningEmailContentTest,RentalActivatedEmailContentTest,CustomerBillingSituationTest}.php`, `tests/Unit/Command/AdminOnboardingHandlerTest.php`, `tests/Integration/Controller/{CustomerSigningControllerTest,CustomerSigningCompleteControllerTest}.php`.

## Requirements

### 1. `AdminOnboardingHandler` derives billing mode itself — drop it from the command

`src/Command/AdminOnboardingCommand.php`: remove the `billingMode` field. `src/Twig/Components/AdminOnboardingForm.php`: remove the `billingMode:` argument and the `\assert(null !== $formData->billingMode)` at `:371`.

`src/Command/AdminOnboardingHandler.php`: after computing `$effectivePaymentMethod`, derive authoritatively:

```php
$rentalDays = (int) $command->startDate->diff($command->endDate)->days;
// Prepaid rental billing always runs on the manual (bank-transfer request) track —
// even when the method radio stays GOPAY/BANK_TRANSFER for a debt payment, no card
// token is ever established for the rental itself.
$billingMode = null !== $command->paidThroughDate
    ? BillingMode::derive(PaymentMethod::EXTERNAL, $command->paymentFrequency, $rentalDays)
    : BillingMode::derive($effectivePaymentMethod, $command->paymentFrequency, $rentalDays);
$order->setBillingMode($billingMode);
```

Move the existing `setBillingMode` call to this spot (it currently runs before the force-EXTERNAL block). Keep `AdminOnboardingFormData::deriveBillingMode()` — it drives the form's explanation card — but align it with the same rule so the admin preview matches reality: when `('free' !== $this->monthlyPriceMode) && ($this->isExternallyPrepaid || $this->startsInPast())`, derive with `PaymentMethod::EXTERNAL` instead of `$this->paymentMethod`.

### 2. Block the nonsensical prepaid + ONE_TIME combination

`AdminOnboardingFormData::validatePaidThroughDate()`: when the prepaid date is in play and `PaymentFrequency::ONE_TIME === $this->paymentFrequency`, add a violation at path `paymentFrequency`: `„Jednorázovou platbu předem nelze kombinovat s externím předplatným — zvolte měsíční či roční frekvenci, nebo nastavte „Předplaceno do" na konec smlouvy."` (Spec 078 already blocks EXTERNAL+ONE_TIME; this closes the BANK_TRANSFER+prepaid+ONE_TIME hole so `derive(EXTERNAL, ONE_TIME, …) = ONE_TIME` can never be produced by requirement 1.)

### 3. `Contract::markExternallyPrepaid()` caps the billing anchor at contract end

```php
public function markExternallyPrepaid(\DateTimeImmutable $paidThroughDate): void
{
    $this->paidThroughDate = $paidThroughDate;
    $resumesOn = $paidThroughDate->modify('+1 day');
    // Prepayment covering the whole term leaves no anchor: nothing to bill,
    // nothing for the manual cron to request, nothing for the overdue sweep.
    $this->nextBillingDate = $resumesOn > $this->endDate ? null : $resumesOn;
}
```

### 4. Free contracts never enter the manual-billing track

- `src/Command/CompleteOrderHandler.php:53`: extend the MANUAL branch condition with `&& !$contract->isFree()`.
- `src/Console/SendManualBillingPaymentRequestsCommand.php` loop: `if ($contract->isFree()) { continue; }` right after the `nextBillingDate` null-check (mirrors `TerminateOverdueContractsCommand:62`).

### 5. Data migration repairing the 8 production rows

Generate an empty migration via `docker compose exec web bin/console make:migration` (then strip the auto-diff if any appears — schema is unchanged) and add data-only statements (precedent: spec 032/058 backfills):

```sql
UPDATE orders SET billing_mode = 'manual_recurring'
WHERE payment_method = 'external' AND billing_mode = 'auto_recurring';

UPDATE contracts c SET billing_mode = 'manual_recurring'
FROM orders o
WHERE c.order_id = o.id
  AND o.payment_method = 'external'
  AND c.billing_mode = 'auto_recurring'
  AND c.go_pay_parent_payment_id IS NULL;
```

(`derive()` can never yield AUTO for EXTERNAL, so the predicate is exact. The token guard is belt-and-braces. Check the actual FK column name on `contracts` before writing — verify with `bin/console dbal:run-sql "\d contracts"` or the entity mapping.)

### 6. Entity helpers for the "what happens next" data

`src/Entity/Order.php` — two behavior helpers next to the existing prepaid fields:

```php
public function billingResumesOn(): ?\DateTimeImmutable  // paidThroughDate?->modify('+1 day')
public function prepaidCoversWholeTerm(): bool           // paidThroughDate !== null && endDate !== null && paidThroughDate >= endDate
```

### 7. `SigningPriceViewModel` — extend with the resume story

New readonly fields, all computed in `fromOrder()`:

```php
public ?\DateTimeImmutable $billingResumesOn,   // Order::billingResumesOn(), null unless EXTERNALLY_PREPAID
public bool $prepaidCoversWholeTerm,
public int $recurringAmountInHaler,             // $order->firstPaymentPrice
public string $cadenceLabel,                    // PaymentFrequency::YEARLY === $order->paymentFrequency ? 'rok' : 'měsíc'
public int $reminderDaysBefore,                 // abs($order->manualBillingOffsetInitial)
```

### 8. Signing page (`templates/public/customer_signing.html.twig`)

Rewrite the prepaid banner (`:40-44`):

```twig
{% if priceViewModel.situation.value == 'externally_prepaid' %}
    <div class="rounded-lg bg-green-50 border-2 border-green-300 p-4 mb-6 text-center">
        <strong class="text-green-900 text-lg">Pronájem je již předplacen externě do {{ priceViewModel.paidThroughDate|date('d.m.Y') }}</strong>
        {% if priceViewModel.prepaidCoversWholeTerm %}
            <p class="mt-2 text-green-800 text-sm">Předplatné pokrývá celou dobu trvání smlouvy (do {{ order.endDate|date('d.m.Y') }}). Po podpisu smlouvy Vás už žádné platby nečekají.</p>
        {% else %}
            <p class="mt-2 text-green-800 text-sm">Po podpisu smlouvy nyní nic neplatíte. Od <strong>{{ priceViewModel.billingResumesOn|date('d.m.Y') }}</strong> činí nájemné <strong>{{ (priceViewModel.recurringAmountInHaler / 100)|number_format(0, ',', ' ') }} Kč / {{ priceViewModel.cadenceLabel }}</strong> <span class="text-xs">vč. DPH</span>.</p>
            <p class="mt-1 text-green-800 text-sm">Před každou splatností ({{ priceViewModel.reminderDaysBefore }} {{ priceViewModel.reminderDaysBefore == 1 ? 'den' : (priceViewModel.reminderDaysBefore <= 4 ? 'dny' : 'dní') }} předem) Vám pošleme e-mail s platebními údaji a QR kódem pro bankovní převod — nemusíte nic nastavovat.</p>
        {% endif %}
    </div>
```

Extend the summary price block (`:93-99`): keep the `gopay_first_charge` row as-is and add a sibling branch:

```twig
{% elseif priceViewModel.situation.value == 'externally_prepaid' and not priceViewModel.prepaidCoversWholeTerm %}
    <hr class="border-gray-200 my-2">
    <div class="flex justify-between font-semibold">
        <span>{{ priceViewModel.cadenceLabel == 'rok' ? 'Roční' : 'Měsíční' }} platba od {{ priceViewModel.billingResumesOn|date('d.m.Y') }}:</span>
        <span>{{ (priceViewModel.recurringAmountInHaler / 100)|number_format(0, ',', ' ') }} Kč / {{ priceViewModel.cadenceLabel }} <span class="text-xs text-gray-500 font-normal">vč. DPH</span></span>
    </div>
{% endif %}
```

FREE stays without a price row (nothing is ever due).

### 9. Signing e-mail (`SigningEmailContent` + `templates/email/signing_link.html.twig`)

`EXTERNALLY_PREPAID` branch of `fromOrder()`:
- `monthlyPriceInHaler: $order->firstPaymentPrice` (was `0`).
- New fields on the VO (nullable, `null` for the other two situations): `public ?\DateTimeImmutable $billingResumesOn`, `public ?string $futureBillingLine`, `public string $cadenceLabel` (default `'měsíc'`).
- `nextStepLine` — covers-whole-term: `sprintf('Pronájem je předplacen externě do konce smlouvy (%s) — po podpisu Vás už žádné platby nečekají.', …endDate…)`; otherwise keep the current sentence and set `futureBillingLine` to: `sprintf('Od %s činí nájemné %s Kč / %s (vč. DPH). Před každou splatností (%s předem) Vám pošleme e-mail s platebními údaji a QR kódem pro bankovní převod.', resumeDate, amount, cadence, daysCzechPlural)`. Use the same Czech plural rule as requirement 8 (1 den / 2–4 dny / 5+ dní), formatted in PHP.

Template: render `content.futureBillingLine` as a second line inside the green banner (`:90-95`) when non-null; extend the summary price row gate (`:141-146`) to also render for `externally_prepaid` with non-null `billingResumesOn`, label `{{ content.cadenceLabel == 'rok' ? 'Roční' : 'Měsíční' }} platba od {{ content.billingResumesOn|date('d.m.Y') }}:`.

### 10. Completion page (`CustomerSigningCompleteController`)

Build the prepaid body from `SigningPriceViewModel::fromOrder($order)` (add the import; the VO is order-based so it works here):
- covers whole term → body: `'Předplatné pokrývá celou dobu trvání smlouvy — žádné další platby Vás nečekají. Detail pronájmu a všechny dokumenty najdete na následující stránce.'`
- otherwise → body: `sprintf('Od %s činí nájemné %s Kč / %s (vč. DPH) — před každou splatností Vám pošleme e-mail s platebními údaji a QR kódem pro bankovní převod. Nyní není potřeba žádná další akce. Detail pronájmu a všechny dokumenty najdete na následující stránce.', …)`

### 11. Rental-activated e-mail (`RentalActivatedEmailContent::fromContract`)

`EXTERNALLY_PREPAID` branch — replace the "Vás kontaktujeme" body. Data comes from the contract: resume date = `$contract->nextBillingDate` (post-requirement-3 it is `null` exactly when the prepayment covers the whole term), amount = `$contract->getEffectiveRecurringAmount()`, cadence via `$contract->isYearly()`, reminder days via `abs($contract->order->manualBillingOffsetInitial)`:
- `nextBillingDate === null` → `sprintf('Pronájem je předplacen externě do konce smlouvy (%s) — žádné další platby Vás nečekají. V příloze tohoto e-mailu najdete podepsanou smlouvu a všechny související dokumenty.', …paidThroughDate…)`
- otherwise → `sprintf('Pronájem je předplacen externě do %s. Od %s činí nájemné %s Kč / %s (vč. DPH) — před každou splatností (%s předem) Vám pošleme e-mail s platebními údaji a QR kódem pro bankovní převod. V příloze tohoto e-mailu najdete podepsanou smlouvu a všechny související dokumenty.', …)`

### 12. `/stav` + portal billing banner (`templates/components/customer_billing_status.html.twig`)

In the blue "Platby probíhají ručně" variant (`:66-79`), enrich the sentence (keep the amber pay-now variant untouched — it already shows the amount):

```twig
<strong>Platby probíhají ručně.</strong>
{% if contract.paidThroughDate is not null and contract.paidThroughDate|date('Y-m-d') >= now|date('Y-m-d') %}
    Zaplaceno do {{ contract.paidThroughDate|date('d.m.Y') }}.
{% endif %}
Výše platby: <strong>{{ (contract.getEffectiveRecurringAmount() / 100)|number_format(0, ',', ' ') }} Kč / {{ contract.isYearly() ? 'rok' : 'měsíc' }}</strong> vč. DPH.
Před každou platbou (7 dní předem) Vám pošleme e-mail s platebními údaji a QR kódem pro bankovní převod.
{% if nextManualDate is defined and nextManualDate is not null %}
    Příští: <strong>{{ nextManualDate|date('d.m.Y') }}</strong>.
{% endif %}
```

(Neutral "Zaplaceno do" is deliberately used instead of "Předplaceno externě" — `paidThroughDate` advances on every ordinary manual cycle too, and the wording is correct for both. The hardcoded "7 dní předem" here matches the two existing occurrences of this copy; per-order offsets on this shared contract-level partial are out of scope.)

### 13. Tests

- `SigningPriceViewModelTest`: prepaid-with-resume (fields populated, cadence, reminder days), prepaid-covers-whole-term, gopay situation leaves new fields null/default.
- `SigningEmailContentTest`: prepaid branch — `monthlyPriceInHaler` equals `firstPaymentPrice`; `futureBillingLine` contains the amount, the resume date, and `QR kódem`; covers-whole-term produces the "žádné další platby" `nextStepLine` and null `futureBillingLine`.
- `RentalActivatedEmailContentTest`: both prepaid bodies (assert amount + resume date substring; no "Vás kontaktujeme" anywhere).
- `AdminOnboardingHandlerTest`: GOPAY method + `paidThroughDate` set ⇒ order ends up `PaymentMethod::EXTERNAL` **and** `BillingMode::MANUAL_RECURRING`; free (`individualMonthlyAmount = 0`) + GOPAY ⇒ EXTERNAL + MANUAL_RECURRING; GOPAY without prepaid ⇒ AUTO_RECURRING unchanged.
- `Contract` unit test (existing entity test file if any, else in `CompleteOrderHandler`'s): `markExternallyPrepaid` with `paidThroughDate = endDate` ⇒ `nextBillingDate === null`; with earlier date ⇒ `paidThroughDate + 1 day`.
- `CompleteOrderHandler` test: free MANUAL contract gets **no** `nextBillingDate`.
- `CustomerSigningControllerTest`: prepaid onboarding order — response contains the amount string (e.g. `1 820 Kč / měsíc`) and the resume date; covers-whole-term variant shows `žádné platby nečekají`.
- `CustomerSigningCompleteControllerTest`: prepaid body contains amount + `QR kódem`.
- `AdminOnboardingFormData` test (`tests/Unit/Form/...` — follow spec 075's file): prepaid + ONE_TIME frequency ⇒ violation at `paymentFrequency`.

## Acceptance

- [ ] `docker compose exec web composer quality` green.
- [ ] Full `docker compose exec web composer test` green (controller/template changes — spec-wide rule).
- [ ] Onboarding created with GOPAY radio + "Externí předplatné" produces an order with `payment_method = external`, `billing_mode = manual_recurring`.
- [ ] Signing page for a prepaid order (fixture or manual) shows: prepaid-until date, resume date, amount `X Kč / měsíc vč. DPH`, and the bank-transfer/QR next-step sentence — before the customer signs.
- [ ] Signing e-mail, completion page and rental-activated e-mail carry the same story; the string "kontaktujeme" no longer appears in any prepaid-situation copy (`grep -rn "kontaktujeme" src templates` — remaining hits must be unrelated surfaces, e.g. the ending-soon e-mail / spec-030 amber banner).
- [ ] Migration flips exactly the `external + auto_recurring` rows; after deploy, on lily: `SELECT count(*) FROM orders WHERE payment_method='external' AND billing_mode='auto_recurring'` returns 0 (same for contracts).
- [ ] `markExternallyPrepaid` with `paidThroughDate >= endDate` leaves `nextBillingDate` NULL (no phantom payment request / no overdue-sweep exposure for fully-prepaid contracts).
- [ ] Free contracts get no billing anchor and are skipped by the manual-billing cron.

## Out of scope

- **Contract DOCX legal master** (`templates/documents/contract_template.docx`): stating the concrete rent amount inside the contract body would change the lawyer-approved "cena určena Ceníkem" clause structure (and the Ceník-by-reference is already inaccurate for individual price overrides — a known VOP-audit follow-up). The price is now visible directly above the contract text on the signing page, in the summary and in both e-mails; the DOCX change needs operator/lawyer sign-off first.
- **GoPay/card conversion after the prepaid window**: no token exists, so a card path would need the spec-077 card-setup consent flow exposed outside prolongation. Deferred (spec 026 note in `Contract::markExternallyPrepaid` docblock stands); bank-transfer requests are the one honest channel today, and that is exactly what the new copy promises.
- **`SendExternalPrepaymentEndingSoonCommand` removal**: after requirement 1 every prepaid onboarding is MANUAL-track, so this cron's query matches (almost) nothing. Leave it — it still covers legacy AUTO contracts with a token gap, and deleting crons is a separate cleanup.
- **Making the "7 dní předem" copy dynamic in the shared contract-level partial** (requirement 12): the per-order snapshot is used where the order is at hand (signing surfaces); unifying the two existing hardcoded occurrences is cosmetic.
- **Zero-amount guard inside `RecurringAmountCalculator`**: requirement 4 prevents free contracts from ever reaching it; a defensive guard there is redundant hardening.
- **Notifying the 8 affected customers/admin about the repair**: the migration is invisible to customers (nothing was sent to them yet); admin learns from this spec's summary.

## Open questions

None — proceed.
