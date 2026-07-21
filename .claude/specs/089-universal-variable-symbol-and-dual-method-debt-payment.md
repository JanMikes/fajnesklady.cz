# 089 — Always generate a variable symbol; let the debt be paid by card OR bank transfer

**Status:** done
**Type:** feature
**Scope:** medium (~10 files + 1 console command + tests)
**Depends on:** 049 (FIO bank transfers), 051 (onboarding debt), 088 (deferred payment choice)

## Problem

`Order.variableSymbol` is only assigned when `Order.paymentMethod === BANK_TRANSFER`. Every other order — GoPay recurring, admin EXTERNAL onboarding — has `variableSymbol = null`, and `Order::applyCustomerPaymentChoice()` actively **nulls a previously assigned VS** when the customer flips bank → card (`src/Entity/Order.php:701`).

The consequence surfaces on the debt page. `OrderDebtPaymentController.php:63` reads:

```php
$isBankTransfer = PaymentMethod::BANK_TRANSFER === $order->paymentMethod;
```

and fans every view variable out of that single ternary (`:71-76`). A GoPay-method order therefore renders **only** the GoPay inline gateway, with no account number, no VS, no QR. Production order `/objednavka/019f5d05-338d-7e06-9b30-d6d0345aeb96/platba/dluh` is exactly this: a customer who wants to wire the debt has no way to do so, and support has no VS to hand them. The debt-request e-mail (`src/Event/SendDebtPaymentRequestEmailHandler.php:47-66`) has the same gate.

This is arbitrary. The debt is a **one-off** settlement of a previous contract — it has nothing to do with the billing track the customer picked for the *new* rental. And the FIO matcher does not actually care about `paymentMethod`: `ProcessIncomingBankTransactionHandler::matchToOrder()` branches purely on order/contract **state** (manual-billing contract → unpaid debt → payable order), and its own comment at `:243-252` already documents that GOPAY-first orders legitimately arrive on the bank-transfer path. The only thing stopping a GoPay order from being paid by wire is that it has no VS to route on.

## Goal

Every `Order` gets a variable symbol at creation, unconditionally and permanently — VS becomes an identity attribute of the order, not a property of a payment method. The debt payment page and the debt-request e-mail then present **both** payment options side by side on every order: the GoPay inline gateway *and* account number + VS + QR code. A customer with a GoPay rental can wire their debt; the FIO matcher pairs it on VS and `DebtPaymentService::confirmDebtPaid()` runs exactly as it does today. `Order.paymentMethod` is never mutated by how the debt got settled.

## Context (current state)

**VS generation**
- `src/Service/Payment/VariableSymbolGenerator.php` — `generate(Uuid $orderId)` `:19`, `computeVs()` `:51` is `str_pad(abs(crc32($input) % 10_000_000_000), 10, '0', STR_PAD_LEFT)`. **Deterministic** for a given order id → re-running a backfill is idempotent. Collision retry appends `-{attempt}` (max 10) and checks uniqueness across `Order` (`:58`) **and** `Fine` (`:69`) via DQL.
- `src/Entity/Order.php:91-92` — `#[ORM\Column(length: 10, nullable: true, unique: true)] public private(set) ?string $variableSymbol`; setter `assignVariableSymbol()` `:528`.

**The five current assignment sites** (all gated on `BANK_TRANSFER` except the lazy ones):
1. `src/Service/OrderService.php:129-134` — `createOrder()` constructs the `Order`. **Assigns no VS today.** This is where the new unconditional assignment goes.
2. `src/Command/SetOrderPaymentPreferencesHandler.php:33-37` — public order form; gated on `BANK_TRANSFER`.
3. `src/Command/ChooseOnboardingPaymentHandler.php:65-69` — customer choice at signing; passes `null` for card, which **clears** the VS.
4. `src/Command/AdminOnboardingHandler.php:115-119` — admin onboarding; honours `variableSymbolOverride`, else generates, gated on `BANK_TRANSFER`.
5. Lazy backfills that already ignore payment method: `src/Command/DispatchManualBillingNotificationHandler.php:94-95` and `src/Command/ProlongContractHandler.php:99-100`.

**Debt payment surface**
- `src/Controller/Public/OrderDebtPaymentController.php` — route `/objednavka/{id}/platba/dluh`, name `public_order_debt_payment`. Guards: valid uuid, order exists, `hasAcceptedTerms()` + `hasSignature()`, `hasUnpaidDebt()` (else redirect). No firewall — the guard is the accepted-terms/signature check.
- `templates/public/order_debt_payment.html.twig` — bank branch `:37-71`, GoPay branch `:72-128`, identification block `:130-139`, GoPay JS wrapped in `{% if not isBankTransfer and goPayEmbedJs %}` at `:147`.
- `src/Controller/Public/DebtPaymentInitiateController.php:23` — `POST .../dluh/iniciovat`, AJAX, returns `{paymentId, gwUrl}`. **Unchanged by this spec** — it already works for any order; the template just never called it on bank orders.
- `src/Event/SendDebtPaymentRequestEmailHandler.php:47-66` + `templates/email/debt_payment_request.html.twig:93-127` — same `isBankTransfer` gate.

**Debt domain** — `Order.onboardingDebtInHaler` `:158-165`, `hasUnpaidDebt()` `:593`, `markDebtPaid()` `:605` (idempotent), `getDebtAmountInCzk()` `:631`. Confirmation converges on `src/Service/Onboarding/DebtPaymentService::confirmDebtPaid()` `:22` from **both** the GoPay webhook (`ProcessPaymentNotificationHandler.php:179-187`) and the bank path (`ProcessBankTransferDebtPaymentHandler.php:29`). No change needed there.

> ⚠️ Do **not** confuse `Order.onboardingDebtInHaler` (this spec) with `Contract.outstandingDebtAmount` (post-termination debt, admin-only settle/waive, spec 087). Different concepts, different columns.

**Matcher** — `src/Command/ProcessIncomingBankTransactionHandler.php`: `attemptAutoMatch()` `:84` tries `orderRepository->findByVariableSymbol()` `:87`, then fines `:115`, then `BankAccountMapping` `:158`. `matchToOrder()` `:235` cascade is manual-billing contract `:253` → unpaid debt `:316` → payable order `:378`. Debt branch expects exactly `onboardingDebtInHaler`, with `tryAccumulatePartialPayments()` `:436` promoting accumulated `amount_mismatch` rows when the running total lands. **No matcher change is required by this spec** — it already handles a VS on a GoPay order.

**QR** — `src/Service/Payment/QrPaymentGenerator.php`: account `2603478520/2010` `:15-16`, `generateDataUri()` `:24` (inline pages), `generateImageUrl()` `:34` (signed absolute URL, for e-mails — data URIs don't render in mail clients). `getBankAccountFormatted()` for display.

**Partial-payment display asymmetry** — `OrderPaymentController.php:116-120` subtracts `bankTransactionRepository->sumReceivedByOrder($order)` (which counts both `matched` **and** `amount_mismatch` rows, `BankTransactionRepository.php:116-129`) so the QR encodes the *remaining* amount. The debt page (`OrderDebtPaymentController.php:74-75`) does **not** — it always QRs the full original debt, even though the matcher supports partial debt accumulation (`:320`). Once we start actively promoting bank transfer for debt on every order this will bite.

**Conventions in play** — see `CLAUDE.md`: no `flush()` outside the documented exceptions (console commands are one, with an inline justification comment); never handwrite migrations; Czech UI text with full diacritics; every controller needs an integration test; `composer quality` does **not** run integration tests, so run `composer test`.

**Compliance** (`.claude/COMPLIANCE.md`) — card + 3D Secure + GoPay logos must appear at every payment surface. The GoPay block already includes `components/payment_logos.html.twig` at `:85`; after this change it renders unconditionally, which strengthens compliance. The Mekmann identification block `:130-139` already renders unconditionally — keep it.

## Architecture

```
Order creation (any path)
  OrderService::createOrder()  ── new: assignVariableSymbol(generate(order.id))  ALWAYS
        │
        ├─ public order form  → SetOrderPaymentPreferencesHandler  (VS already set, no-op)
        ├─ admin onboarding   → AdminOnboardingHandler  (override wins, else keep)
        └─ customer signing   → ChooseOnboardingPaymentHandler  (never clears)

Legacy rows  ── app:backfill-variable-symbols ──▶ same deterministic crc32(orderId)

/objednavka/{id}/platba/dluh   (both blocks ALWAYS rendered)
  ┌─ GoPay block ─────────┐        ┌─ Bank block ──────────────┐
  │ pay-button → POST     │        │ account 2603478520/2010   │
  │ .../dluh/iniciovat    │        │ VS  <order.variableSymbol>│
  │ → _gopay.checkout()   │        │ QR  (debt − partials)     │
  └───────────┬───────────┘        └───────────┬───────────────┘
              │                                │
    GoPay webhook                    FIO poll → matchToOrder()
              │                          hasUnpaidDebt() branch
              └────────────┬───────────────────┘
                DebtPaymentService::confirmDebtPaid()
                (order.paymentMethod NEVER mutated)
```

Ordering on the page follows the order's own `paymentMethod`: `BANK_TRANSFER` → bank block first, everything else (`GOPAY`, `EXTERNAL`) → GoPay block first. Both are always fully visible — no tabs, no accordion, no JS toggle.

## Requirements

### 1. `src/Service/OrderService.php` — assign a VS to every new order

Inject `VariableSymbolGenerator`. In `createOrder()`, immediately after `$order->setManualBillingSchedule(...)` and **before** `$this->orderRepository->save($order)`:

```php
// Spec 089: the variable symbol is an identity attribute of the order, not a
// property of a payment method. Every order gets one at creation so a debt or
// a rental payment can always be settled by bank transfer, whatever billing
// track the customer picked. Deterministic on the order id (crc32).
$order->assignVariableSymbol($this->variableSymbolGenerator->generate($order->id));
```

This covers every creation path — the public order form, admin onboarding (`AdminOnboardingHandler` calls `OrderService::createOrder()`), prolongation, and fixtures.

### 2. `src/Entity/Order.php` — stop clearing the VS on a payment-method flip

`applyCustomerPaymentChoice()` (`:686-702`) takes `?string $variableSymbol` purely so a bank→card flip can null it. That reason is gone. **Drop the parameter entirely** and delete the assignment + the `// null clears any stale VS` comment at `:701`. Update the docblock at `:680-685` to note that the VS is creation-assigned and immutable.

Update the four call sites in `tests/Unit/Entity/OrderTest.php` (`:838, :862, :878, :903`) and add a case asserting the VS survives a `BANK_TRANSFER → GOPAY` flip.

### 3. `src/Command/ChooseOnboardingPaymentHandler.php` — drop the VS branch

Delete lines `:65-67` (the `$variableSymbol` ternary), the `VariableSymbolGenerator` constructor dependency, and its `use` import. Pass four arguments to `applyCustomerPaymentChoice()`.

### 4. `src/Command/SetOrderPaymentPreferencesHandler.php` — drop the VS branch

Delete the `if (PaymentMethod::BANK_TRANSFER === ...)` block at `:33-37`, the `VariableSymbolGenerator` dependency, and now-unused imports. The handler keeps only its billing-mode and payment-method sets.

### 5. `src/Command/AdminOnboardingHandler.php` — override only

The admin form still allows pinning a VS (`AdminOnboardingCommand.php:23`), e.g. to match a legacy paper contract. Replace `:115-119` with an override-only assignment, no method gate:

```php
// The order already carries a generated VS (OrderService::createOrder). An
// explicit admin override — e.g. a VS printed on a legacy paper contract —
// replaces it; otherwise the generated one stands.
if (null !== $command->variableSymbolOverride && '' !== $command->variableSymbolOverride) {
    $order->assignVariableSymbol($command->variableSymbolOverride);
}
```

Drop the `VariableSymbolGenerator` dependency if nothing else in the handler uses it.

### 6. New `src/Console/BackfillVariableSymbolsCommand.php`

`#[AsCommand(name: 'app:backfill-variable-symbols', description: 'Assign a variable symbol to every order that lacks one (spec 089).')]`

- Query orders `WHERE o.variableSymbol IS NULL`, ordered by `createdAt`, via `EntityManagerInterface` QueryBuilder (per `CLAUDE.md`: no `getRepository()`/`findBy()`).
- For each, `$order->assignVariableSymbol($this->generator->generate($order->id))`.
- **Flush every 50 rows.** `VariableSymbolGenerator::existsInOrders()` runs a DQL query, so unflushed in-batch assignments are invisible to the collision check; batching bounds the window in which two orders could compute the same VS. The `unique` constraint on the column is the hard backstop — a collision that slips through throws loudly at flush rather than silently duplicating.
- Manual `flush()` is permitted here (console command, no messenger envelope) — add the inline justification comment required by `CLAUDE.md`.
- Support `--dry-run` (report the count, assign nothing) and print a `<info>Assigned N variable symbols.</info>` summary.
- Deterministic + idempotent: re-running assigns nothing because every order now has a VS.

The existing lazy backfills in `DispatchManualBillingNotificationHandler.php:94-95` and `ProlongContractHandler.php:99-100` become effectively dead but stay as harmless idempotent guards — **do not remove them**.

**No migration.** The column stays `nullable: true, unique: true`; every template and controller already null-guards `variableSymbol`, and making it non-nullable would need a deploy-ordering dance for zero benefit.

### 7. `src/Controller/Public/OrderDebtPaymentController.php` — offer both methods

Inject `BankTransactionRepository`. Replace the `$isBankTransfer` fan-out at `:63-77`:

```php
$debtAmount = (int) $order->onboardingDebtInHaler;

// Mirror the first-payment page (OrderPaymentController:116-120): a customer
// who wired part of the debt must be QR'd the remainder, not the original
// figure. sumReceivedByOrder() counts matched + amount_mismatch rows, which is
// exactly what the matcher accumulates in tryAccumulatePartialPayments().
$partiallyPaid = $this->bankTransactionRepository->sumReceivedByOrder($order);
$remainingAmount = $partiallyPaid > 0 ? max(0, $debtAmount - $partiallyPaid) : null;
$effectiveDebtAmount = $remainingAmount ?? $debtAmount;

return $this->render('public/order_debt_payment.html.twig', [
    // …existing keys…
    'debtAmountCzk'         => $order->getDebtAmountInCzk(),
    'remainingDebtCzk'      => null !== $remainingAmount ? intdiv($remainingAmount, 100) : null,
    'goPayEmbedJs'          => $this->goPayClient->getEmbedJsUrl(),
    'bankAccount'           => $this->qrPaymentGenerator->getBankAccountFormatted(),
    'qrCodeDataUri'         => null !== $order->variableSymbol
        ? $this->qrPaymentGenerator->generateDataUri($order->variableSymbol, $effectiveDebtAmount)
        : null,
    'bankFirst'             => PaymentMethod::BANK_TRANSFER === $order->paymentMethod,
    'statusUrl'             => $this->orderStatusUrlGenerator->generate($order),
]);
```

`isBankTransfer` is removed from the context entirely. `bankFirst` is presentation-only — it controls block order, nothing else.

Match `getDebtAmountInCzk()`'s existing haléře→Kč convention when computing `remainingDebtCzk` (read `Order.php:631` and mirror it rather than assuming `intdiv`).

### 8. `templates/public/order_debt_payment.html.twig` — render both blocks

- Extract the bank block (`:37-71`) and the GoPay block (`:72-128`) into two `{% macro %}`s or two included partials in the same file, then emit them in `bankFirst` order. Both **always** render — delete the `{% if isBankTransfer %} … {% else %} … {% endif %}` wrapper.
- Give each block a heading so the choice is unambiguous. Czech, full diacritics:
  - Bank: `Zaplatit bankovním převodem`
  - GoPay: `Zaplatit kartou online`
- Above them, a one-line lead: `Dluh můžete uhradit kartou online nebo bankovním převodem — vyberte si, co vám vyhovuje.`
- When `remainingDebtCzk` is set, show a note in the bank block: `Část dluhu už evidujeme jako uhrazenou. Zbývá doplatit {{ remainingDebtCzk|number_format(0, ',', ' ') }} Kč.` and encode that figure in the QR (the controller already does).
- Move the GoPay `<script>` guard at `:147` from `{% if not isBankTransfer and goPayEmbedJs %}` to `{% if goPayEmbedJs %}`.
- Keep `components/payment_logos.html.twig` inside the GoPay block and keep the Mekmann identification block at the bottom.
- Use only component classes that exist in `assets/styles/app.css` — see `CLAUDE.md`, DaisyUI is **not** installed and undefined classes compile to nothing. The existing markup already sticks to plain Tailwind utilities + `card` / `btn btn-primary btn-lg`; stay in that vocabulary.

### 9. `src/Event/SendDebtPaymentRequestEmailHandler.php` + e-mail template

Drop `$isBankTransfer` (`:47`). Always pass `bankAccount`, `variableSymbol`, and `qrCodeDataUri` (via `generateImageUrl()` — signed absolute URL, not a data URI). Keep the debt-payment-page link.

In `templates/email/debt_payment_request.html.twig`, change the gates at `:93`, `:99`, `:108` to test only the value (`{% if bankAccount %}` etc.), and replace the branching button label at `:117` with a single neutral one: `Zaplatit dluh online`. Add one line above the button making it explicit that the wire details in the table are an equally valid alternative.

### 10. Tests

- **Unit** — `tests/Unit/Entity/OrderTest.php`: VS survives `applyCustomerPaymentChoice()` with `GOPAY` after having been set (per §2).
- **Integration** — new `tests/Integration/Controller/OrderDebtPaymentControllerTest.php`:
  - GoPay-method order with unpaid debt → `200`, response contains the account number, the order's `variableSymbol`, **and** the GoPay pay button (`id="pay-button"`). This is the regression test for the reported production bug.
  - Bank-transfer-method order with unpaid debt → `200`, contains both blocks, bank block appears **before** the GoPay block in the markup.
  - Order without accepted terms / without signature → `404`.
  - Order with no unpaid debt → `302` to `public_order_payment` or the status URL.
  - Partially paid debt (seed a `BankTransaction` in `amount_mismatch` for part of the amount) → the remaining-amount note renders.
- **Integration** — new `tests/Integration/Console/BackfillVariableSymbolsCommandTest.php` (or extend an existing console test): an order with `variableSymbol = null` gets one; a second run assigns nothing; `--dry-run` mutates nothing.
- **Integration** — an order created through `OrderService::createOrder()` has a non-null `variableSymbol` regardless of the payment method later chosen.
- Check `tests/Integration/Controller/ControllerAccessTest.php` data providers for an existing `public_order_debt_payment` row and reconcile with the dedicated test file rather than duplicating.
- Fixtures in `.claude/FIXTURES.md` may assert on VS being null for card orders — grep `tests/` and `src/DataFixtures/` for `variableSymbol` and fix any assertion this invalidates.

## Acceptance

- [ ] Every order created via `OrderService::createOrder()` has a non-null, unique `variableSymbol`, regardless of `paymentMethod`.
- [ ] `docker compose exec web bin/console app:backfill-variable-symbols` assigns a VS to all legacy `variable_symbol IS NULL` orders; a second run reports `0`; `--dry-run` writes nothing.
- [ ] Changing the payment choice from bank transfer to card at signing no longer clears the VS (`applyCustomerPaymentChoice()` has no VS parameter).
- [ ] `/objednavka/{id}/platba/dluh` on a **GoPay-method** order with unpaid debt renders the account number, VS, QR code **and** the working GoPay pay button. Verified in the repo against the production case `019f5d05-338d-7e06-9b30-d6d0345aeb96` shape (GoPay order + `onboardingDebtInHaler > 0`).
- [ ] On a bank-transfer-method order the bank block renders first; on GoPay/EXTERNAL the GoPay block renders first. Both are always present.
- [ ] A wire arriving with a GoPay order's VS for exactly the debt amount is auto-matched by `app:process-fio-transactions`, `markDebtPaid()` fires, and `order.paymentMethod` is **unchanged** (still `GOPAY`).
- [ ] A partial wire against the debt leaves the page QR encoding the remainder, not the original figure.
- [ ] The debt-request e-mail contains account number + VS + QR image URL for a GoPay-method order.
- [ ] `docker compose exec web composer quality` is green (phpstan level 8 + unit tests).
- [ ] `docker compose exec web composer test` is green — required, since this touches controllers and templates.
- [ ] `docker compose exec web bin/console doctrine:schema:validate` is green (no schema drift; no migration should be needed).

## Out of scope

- **The first-payment page `/objednavka/{id}/platba`.** Deliberately unchanged: it still mirrors `order.paymentMethod`. Paying the *first* charge of an `AUTO_RECURRING` (card monthly) order by wire would leave no card token, silently downgrading the contract to the manual track — a bigger behavioural decision than this spec. Its partial-payment handling already works.
- **Making `variable_symbol` NOT NULL.** Needs deploy ordering (backfill must land before the constraint) for zero functional gain; every consumer already null-guards.
- **`Contract.outstandingDebtAmount`** (post-termination debt, spec 087). Still admin-only settle/waive; no customer payment page exists and none is added here.
- **Lifting `AdminOnboardingFormData::validateDebtPaymentMethod()`** (`:511-528`, "Při dluhu nelze použít externě"). This spec technically makes the restriction unnecessary — an EXTERNAL order can now be paid either way — but relaxing it changes what admins can record, which the user did not ask for. Leave it; revisit separately.
- **Flipping `order.paymentMethod` when the debt is settled by the "other" method.** Explicitly decided against: the payment method describes the rental billing track, not how a one-off debt was cleared.
- **Removing the lazy VS backfills** in `DispatchManualBillingNotificationHandler` and `ProlongContractHandler`. Harmless and idempotent once the creation-time assignment lands; leave them.
- **Changing `ProcessIncomingBankTransactionHandler`.** Its cascade already branches on order state, not payment method (see its own comment at `:243-252`). No change required.
- **QR/VS on the fine payment page.** Fines already get a VS unconditionally (`IssueFineHandler.php:59-60`) and `FinePaymentController` already renders bank details for all of them.

## Open questions

None — proceed.
