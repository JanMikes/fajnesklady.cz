# 091 — Manual bank-transfer pairing + partial-payment allocation

**Status:** done
**Type:** feature
**Scope:** large (~18 files + 1 migration + tests)
**Depends on:** 090 (VS leading-zero fix — implement and deploy first)

## Problem

Three gaps, all on the same surface.

**1. Nothing can be paired by hand.** `/portal/admin/bankovni-platby` renders exactly two actions (`templates/admin/bank_payments/index.html.twig:88-119`): **Ignorovat** on `unmatched` rows and **Obnovit** on `ignored` rows. `matched` and `amount_mismatch` rows get a literal `—`. There is no `PairBankTransactionCommand`, no pairing route, and `BankTransaction::pairToOrder()` / `pairToContract()` are only ever called from `ProcessIncomingBankTransactionHandler`, always with `pairedBy: null` — so the `paired_by` column is dead except when set by `markIgnored()`. Spec 078 deferred this explicitly (`BACKLOG.md:92`); spec 049 line 14 still *claims* the page has "manual pairing for edge cases", which is false and should be corrected.

When auto-matching misses — wrong VS typed, no VS, a third party paying on the customer's behalf, a rounded amount — the only recourse is to record the payment on the order via `AdminOrderRecordExternalPaymentController` (`:25`) or `AdminOrderSettleDebtController` (`:19`) and then **Ignorovat** the bank row. That splits the audit trail across two entities and leaves the bank row permanently unpaired.

**2. Money is allocated in the wrong order.** `matchToOrder()` (`ProcessIncomingBankTransactionHandler.php:235`) cascades **cycle first, then debt**: the `usesManualBillingTrack()` branch at `:253` returns unconditionally, so `:316`'s `$order->hasUnpaidDebt()` is unreachable for any customer who has both. A customer who owes an onboarding debt *and* has an active manual-billing contract has every transfer consumed by the rental cycle while the debt is never touched.

**3. Partial and over-payments have no model.** `tryAccumulatePartialPayments()` (`:436-480`) sums prior `amount_mismatch` rows and promotes them **only when the running total lands exactly** on the expected amount. Anything else — a customer paying 2 000 of 3 100 and then 1 500, or rounding 3 100 up to 3 500 — never resolves. There is no place to hold unallocated money and no admin-visible "received X of Y".

## Goal

One allocation waterfall, used identically by the FIO cron and by an admin pairing by hand: settle debt first, then the current billing obligation, and park any surplus as a credit that offsets the next cycle. Under-payments accumulate against the same credit instead of being stranded. The admin can pair any `unmatched` or `amount_mismatch` transaction to an order from the bank-payments page, sees exactly what the money will settle before confirming, and optionally teaches the system to recognise that sender account next time.

## Context (current state)

**Admin surface**
- `src/Controller/Admin/AdminBankPaymentsController.php:14` — route `/portal/admin/bankovni-platby`, name `admin_bank_payments`, `#[IsGranted('ROLE_ADMIN')]`. Reads `?filter=`, calls `findAll($filter)`, `countUnmatched()`, `countIgnored()`.
- `src/Controller/Admin/AdminBankTransactionIgnoreController.php:20` — the pattern to copy: single-action, `#[CurrentUser] User $admin`, `requirements: ['id' => '[0-9a-f-]{36}']`, `methods: ['POST']`, preserves the `filter` POST field on redirect, guards state before dispatching, flashes Czech.
- `src/Command/IgnoreBankTransactionCommand.php` + `IgnoreBankTransactionHandler.php` — command/handler shape, incl. `AuditLogger::log(entityType: 'bank_transaction', …)`.
- `templates/admin/bank_payments/index.html.twig` — filter chips `:15-30`, columns `:42-51`, status badges `:63-76`, Objednávka cell `:78-86`, Akce cell `:88-119`.

**Entity** — `src/Entity/BankTransaction.php`: `pairToOrder()` `:67`, `pairToContract()` `:76`, `markAmountMismatch()` `:86`, `markAmountMismatchContract()` `:95`, `promoteToMatched()` `:105` (throws unless currently `amount_mismatch`), `markIgnored()` `:115`, `unignore()` `:123`, predicates `:131-146`. **`status` is a raw string** (`:23-24`, values `unmatched` / `matched` / `amount_mismatch` / `ignored`) with no enum — contrary to the "Enums over magic strings" rule in `CLAUDE.md`. Both `pairTo*` methods already accept `?User $pairedBy`; production only ever passes `null`.

> `pairToContract()` `:78` also sets `pairedOrder = $contract->order`, so contract-paired rows still render an order link.

**Matcher** — `src/Command/ProcessIncomingBankTransactionHandler.php`: `attemptAutoMatch()` `:84` (VS→order `:87`, VS→fine `:115`, account mapping `:158`), `matchToOrder()` `:235`, `tryAccumulatePartialPayments()` `:436`, `dispatchPaymentCommand()` `:481`. Match-method strings persisted to `match_method` (varchar 30): `variable_symbol`, `variable_symbol_fine`, `account_mapping`.

**Downstream** — `ProcessBankTransferPaymentHandler` branches on `usesManualBillingTrack()` → `reconcileBankTransferRecurring()` (advances the anchor via `Contract::recordBillingCharge()`, marks the `ManualPaymentRequest` paid, dispatches `RecurringPaymentCharged`), else `OrderService::confirmPayment()` + `CompleteOrderCommand`. `ProcessBankTransferDebtPaymentHandler` → `DebtPaymentService::confirmDebtPaid()`.

**Two different debts — do not conflate**
- `Order.onboardingDebtInHaler` (spec 051) — onboarding debt. `hasUnpaidDebt()` `Order.php:593`, `markDebtPaid()` `:605`. This is the one the matcher's `:316` branch handles.
- `Contract.outstandingDebtAmount` (spec 087) — post-termination debt. `Contract.php:36`; `settleDebt()` around `:191` **already supports partial settlement** (`$remaining = $outstandingDebtAmount - $amountInHaler`, nulled at zero); `hasOutstandingDebt()` `:207`.

**BankAccountMapping — dead code.** `src/Entity/BankAccountMapping.php` is fully formed (`accountNumber`, `user`, `order`, `createdBy`, `createdAt`; `#[ORM\UniqueConstraint(fields: ['accountNumber', 'order'])]`) and `BankAccountMappingRepository` has `save()` / `find()` / `delete()` / `findByAccountNumber()` / `findByOrder()`. But `new BankAccountMapping` appears **nowhere in the repo**, so no row can ever exist and the `:158` auto-match branch never fires. `findByAccountNumber()` uses `setMaxResults(1)` with **no `ORDER BY`** — once one account maps to several orders it routes money to an arbitrary one.

**Order search to reuse** — `OrderRepository::searchOrderIds()` `:640-663`, raw SQL matching the order reference (last `-` segment against the leading hex of order id **and** contract id) plus `LOWER(first_name || ' ' || last_name)` and email. Driven by `findAdminFiltered()` `:545` / `buildAdminFilteredQueryBuilder()` `:576`. It does **not** currently match variable symbol.

**Prod baseline (2026-07-21):** `bank_transaction` is `1 unmatched / 5 matched / 2 ignored` — **zero `amount_mismatch` rows.** The status-semantics change in requirement 3 therefore migrates no live data, which is why it is safe to do now.

**Conventions** — `CLAUDE.md`: single-action controllers with class-level routes; `final readonly` commands; no `flush()` outside handlers; **never handwrite migrations** (`bin/console make:migration`); UUID v7 via `ProvideIdentity`; Czech UI with full diacritics; `.form-input` for form controls and no undefined DaisyUI classes (`btn-warning`, `select-bordered`, … render as nothing); every controller needs an integration test; `composer quality` skips integration tests so run `composer test`.

**Decisions already taken (do not relitigate)** — no password gate (matches Ignorovat; pairing is the routine daily reconciliation action); no unpair action (pairing dispatches payments, invoices and e-mails that an unpair could not reverse — invest in the pre-confirm summary instead); debt-before-cycle applies to **both** the auto-matcher and manual pairing; surplus is credited forward on the contract.

### Decisions taken 2026-07-21 (during implementation) — these override the spec text where they conflict

**D1 — A card order's first payment can never be settled by bank transfer, not even by an admin.**
An `AUTO_RECURRING` order's recurring mandate exists only if the first charge is paid by card; there is no other way to obtain the token. The auto-matcher already declines such transfers (added while implementing 089 — `ProcessIncomingBankTransactionHandler`, `auto_match_declined_card_order`). **Manual pairing must decline them too**: `PairBankTransactionHandler` throws when the target order is `AUTO_RECURRING` and `canBePaid()`. The admin's route is to refund the customer and ask them to pay by card. Chosen for billing integrity and because it is the easily reversible choice — allowing the pairing (with a downgrade to the manual track) can be added later without unpicking anything. **Consequence to accept:** such rows stay in the pairing queue with no action that clears them. Make the UI say why, in Czech, rather than leaving the admin guessing.

**D2 — Partial first payments keep auto-accumulating, and every allocation is recorded.**
Today two partial wires (2 000 then 1 100 of 3 100) auto-complete an order via `tryAccumulatePartialPayments()`. That must keep working, including for orders with no contract yet. Since credit lives on `Contract` and a pre-completion order has none, accumulation is instead derived from **recorded allocations**: each bank transaction stores what each part of it was allocated to (new `BankTransactionAllocation` rows, requirement 1b). "Already paid toward the first payment" is then `SUM(allocations WHERE order = X AND type = FIRST_PAYMENT)`.

This is also the fix for the **double-count bug found reviewing 089**: `BankTransactionRepository::sumReceivedByOrder()` is a single undifferentiated per-order pool, but it feeds two different obligations (the debt remainder on `/platba/dluh` and the first-payment remainder on `/platba`). A partial wire toward a debt that is then finished by card leaves an orphan `amount_mismatch` row that silently discounts the *first payment*, letting an order complete under-collected. Typed allocations make the two pools disjoint by construction. **`sumReceivedByOrder()` must lose both of its remaining callers** (`OrderPaymentController`, `InitiateDebtPaymentHandler`) in favour of type-scoped sums.

**D3 — Credit reduces the amount we ask for.**
A contract holding 400 Kč credit facing a 3 100 Kč cycle is asked for **2 700 Kč** — in the QR, the payment-request e-mail and the portal. Implemented as a display/request-side subtraction only:

- the allocator's `BILLING_CYCLE` **expected** stays the full cycle amount (`RecurringAmountCalculator::calculate()`), and available money stays `creditBalance + incoming`;
- a new `RecurringAmountCalculator::amountToRequest(Contract, \DateTimeImmutable): int` returns `max(0, calculate(…) - creditBalance)` and is what every customer-facing surface renders.

Both sides then agree: customer pays 2 700, allocator sees 400 credit + 2 700 incoming = 3 100 expected → cycle settled, credit drained to 0. Do **not** also subtract credit inside the allocator — that double-counts it.

**D4 — Waterfall order confirmed:** onboarding debt → contract debt → current cycle / first payment → credit. As already specified.

## Architecture

```
                       ┌──────────────────────────────┐
FIO cron ──────────────▶                              │
                       │      PaymentAllocator        │
Admin manual pairing ──▶  plan(order, amount, now)    │
                       │                              │
                       └──────────────┬───────────────┘
                                      │ AllocationPlan (pure, no side effects)
                                      ▼
             ┌────────────────────────────────────────────────┐
             │ 1. Order.onboardingDebtInHaler   (partial ok)  │
             │ 2. Contract.outstandingDebtAmount (partial ok) │
             │ 3. current obligation:                         │
             │      manual-billing contract → cycle amount    │
             │      else order payable      → firstPayment    │
             │ 4. surplus → Contract.creditBalance            │
             └────────────────────────┬───────────────────────┘
                                      ▼
                        apply() → dispatch existing commands
                    (ProcessBankTransferDebtPayment / …Payment)
```

The allocator is **pure**: it reads state and returns a plan. Nothing mutates until `apply()`. That is what lets the admin confirm screen render the exact same plan that will be executed.

## Requirements

### 1. `Contract.creditBalance` — where unallocated money lives

New column on `src/Entity/Contract.php`, alongside `outstandingDebtAmount` `:36`:

```php
/**
 * Money received but not yet consumed by an obligation, in haléře. Fed by
 * over-payments and by under-payments that could not complete a cycle;
 * drained by the next allocation before any new money is considered.
 */
#[ORM\Column(options: ['default' => 0])]
public private(set) int $creditBalance = 0;
```

Behaviour methods (no setters):

```php
public function addCredit(int $amountInHaler, \DateTimeImmutable $now): void
public function consumeCredit(int $amountInHaler, \DateTimeImmutable $now): int  // returns actually consumed
```

`addCredit` rejects `<= 0`. `consumeCredit` clamps to the available balance and returns what it took, so callers never have to guard. Both bump `updatedAt`.

Generate the migration with `bin/console make:migration` — **do not handwrite it.** Existing rows default to `0`.

> Orders with no contract yet (pre-completion) have nowhere to hold credit. For those, surplus stays in the transaction's unallocated remainder and the row is flagged for admin attention (requirement 3) rather than silently absorbed. Do not add a credit field to `Order`.

### 2. New `src/Service/Payment/PaymentAllocator.php`

```php
final readonly class PaymentAllocator
{
    public function plan(Order $order, ?Contract $contract, int $amountInHaler, \DateTimeImmutable $now): AllocationPlan;
    public function apply(AllocationPlan $plan, BankTransaction $tx, \DateTimeImmutable $now): void;
}
```

`AllocationPlan` and `AllocationStep` are `final readonly` DTOs in `src/Service/Payment/`. A step carries `type` (new `AllocationStepType` enum: `ONBOARDING_DEBT`, `CONTRACT_DEBT`, `BILLING_CYCLE`, `FIRST_PAYMENT`, `CREDIT`), the `expected` amount, the `allocated` amount, and `fullySettled: bool`. The plan exposes `steps`, `totalAllocated`, `unallocated`, and `settlesEverything(): bool`.

**Waterfall order — this is the behavioural change.** Available money is `creditBalance + incoming`:

1. `ONBOARDING_DEBT` — while `$order->hasUnpaidDebt()`. Partial allowed.
2. `CONTRACT_DEBT` — while `$contract?->hasOutstandingDebt()`. Partial allowed (`Contract::settleDebt()` already handles it).
3. `BILLING_CYCLE` if `$contract?->usesManualBillingTrack()`, expected = `RecurringAmountCalculator::calculate($contract, $now)`; **else** `FIRST_PAYMENT` if `$order->canBePaid()`, expected = `$order->firstPaymentPrice`. This step is **all-or-nothing**: a cycle is either paid or it is not, so if the remaining money does not cover it the step records `allocated < expected`, `fullySettled: false`, and **no cycle command is dispatched**. The shortfall stays as credit.
4. `CREDIT` — whatever is left.

`apply()` dispatches the *existing* commands, unchanged: `ProcessBankTransferDebtPaymentCommand` for a fully-settled `ONBOARDING_DEBT`, `ProcessBankTransferPaymentCommand` for a fully-settled `BILLING_CYCLE` / `FIRST_PAYMENT` (passing `totalAmount: $step->expected`). Partial steps dispatch nothing and only move the credit balance. `CONTRACT_DEBT` calls `Contract::settleDebt()` directly — there is no command for it today and this spec does not add one.

Audit every application: `entityType: 'bank_transaction'`, `eventType: 'allocated'`, payload carrying the full step breakdown, `credit_before`, `credit_after`.

> **`CLAUDE.md` trap:** `apply()` runs inside a command handler, so the `doctrine_transaction` middleware flushes it. Do **not** call `flush()`, and do not mutate anything after a nested `dispatch()` returns.

### 3. Rework the matcher onto the allocator

In `src/Command/ProcessIncomingBankTransactionHandler.php`:

- `matchToOrder()` `:235` — replace the hand-rolled cascade at `:253` / `:316` / `:378` with `PaymentAllocator::plan()` + `apply()`. The debt-before-cycle reordering falls out of the allocator; the old branch order disappears with it.
- **Delete `tryAccumulatePartialPayments()`** `:436-480` and its `sumAmountMismatchByOrder()` call. The credit balance subsumes it and doing both would double-count. `BankTransactionRepository::sumAmountMismatchByOrder()` / `findAmountMismatchByOrder()` become unused *by the matcher* — check `OrderPaymentController` and `OrderPaymentOverviewFactory` before removing either (spec 089 notes `sumReceivedByOrder()` is used for remaining-amount QR display).
- **Status semantics.** A transaction whose money we successfully attributed to an order is `matched`, even if it only partially settled an obligation — we know whose it is. `amount_mismatch` narrows to: *paired, but this transfer alone did not fully settle its expected obligation* — i.e. the badge stays useful as an admin signal, backed by `expectedAmountInHaler`. Prod has zero such rows, so nothing migrates.
- Keep `BankTransferAmountMismatch` firing (and its admin e-mail) when a plan leaves `BILLING_CYCLE` unsettled.

**Introduce `App\Enum\BankTransactionStatus`** (`UNMATCHED`, `MATCHED`, `AMOUNT_MISMATCH`, `IGNORED`) per the `CLAUDE.md` enums rule, and switch `BankTransaction::$status` to `enumType:`. Update the repository's string parameters and the template's `tx.status == 'matched'` comparisons. This is a mapping-only change; generate a migration and confirm `doctrine:schema:validate` is green (column stays `varchar(20)` with the same values, so the migration may well be empty — that is fine).

### 4. Manual pairing — command + handler

`src/Command/PairBankTransactionCommand.php`:

```php
final readonly class PairBankTransactionCommand
{
    public function __construct(
        public Uuid $transactionId,
        public Uuid $orderId,
        public Uuid $adminId,
        public bool $rememberSenderAccount,
        public ?string $note,
    ) {}
}
```

`PairBankTransactionHandler` mirrors `IgnoreBankTransactionHandler`:

- Load transaction / order / admin, throw `\DomainException` on any miss.
- Guard: only `unmatched` or `amount_mismatch` may be paired — anything else throws.
- An `amount_mismatch` row is already paired to an order. If the admin targets the **same** order, this is a re-allocation; if a **different** one, clear the old pairing first. Handle both; do not assume `pairedOrder` is null.
- Build and apply the plan via `PaymentAllocator`, then `pairToOrder($order, 'manual', $admin, $now)` — **`$admin`, not `null`.** This is the first code path that populates `paired_by`.
- New `match_method` value `'manual'` (fits varchar 30).
- Audit `eventType: 'manually_paired'` with the plan breakdown, the admin id, and the note.
- If `rememberSenderAccount` and the transaction has a `senderAccountNumber`: create a `BankAccountMapping` (id from `ProvideIdentity`) — see requirement 6.

### 5. Manual pairing — controller + UI

Two routes, both `#[IsGranted('ROLE_ADMIN')]`, both `requirements: ['id' => '[0-9a-f-]{36}']`:

| Controller | Route | Name | Methods |
|---|---|---|---|
| `AdminBankTransactionPairController` | `/portal/admin/bankovni-platby/{id}/sparovat` | `admin_bank_transaction_pair` | GET, POST |

A dedicated page, not a modal — the confirm summary needs room and the order picker needs a search box.

- **GET, no `order` param** — render the picker: transaction details (date, amount, VS, sender name + account, comment) above a search field and results table (order reference, customer, unit, place, status, VS, expected amount). Reuse `OrderRepository::searchOrderIds()`, **extended to also match `o.variable_symbol`** so an admin can paste the symbol from the bank statement. Empty search shows the most recent 20 orders.
- **GET with `?order={uuid}`** — render the confirm screen: the `AllocationPlan` in full ("Dluh z onboardingu: 1 500 Kč ze 1 500 Kč — uhrazeno", "Nájem za období …: 1 600 Kč ze 3 100 Kč — **neuhrazeno**, zbývá 1 500 Kč", "Přeplatek 400 Kč bude převeden na další období"), a warning callout when the plan does not settle everything, the "Zapamatovat si účet odesílatele" checkbox, an optional note field, and a **Spárovat** submit. Back link returns to the picker.
- **POST** — CSRF-protected, dispatches the command, flashes Czech success, redirects to `admin_bank_payments` preserving `filter` exactly as the ignore controller does.

Template `templates/admin/bank_payments/pair.html.twig`. Use `.form-input` on the search input, note textarea and any select; the checkbox keeps the `h-4 w-4 … border-gray-300 rounded` pattern. Only use component classes defined in `assets/styles/app.css` — verify each before use.

In `index.html.twig`, extend the Akce cell `:88-119`:

```twig
{% if tx.isUnmatched or tx.isAmountMismatch %}
    <a href="{{ path('admin_bank_transaction_pair', {id: tx.id, filter: filter}) }}" class="btn btn-primary btn-sm">Spárovat</a>
{% endif %}
{% if tx.isUnmatched %}
    {# existing Ignorovat modal, unchanged #}
{% elseif tx.isIgnored %}
    {# existing Obnovit form, unchanged #}
{% endif %}
```

So `amount_mismatch` rows gain **Spárovat** (they currently show `—`) and `unmatched` rows show **Spárovat** + **Ignorovat**. `matched` still shows `—`; there is no unpair.

Surface partial state where it is actionable: on `amount_mismatch` rows show `přijato X z Y` under the badge using `expectedAmountInHaler`, and add the contract's `creditBalance` to the admin order detail payments overview when non-zero.

### 6. Revive `BankAccountMapping` safely

- **Creation** — only from `PairBankTransactionHandler` when the admin ticks the box. Unchecked by default: remembering an account is a standing instruction to route future money, which should be a deliberate act.
- Skip silently when `senderAccountNumber` is null, or when a mapping for that (`accountNumber`, `order`) pair already exists — the unique constraint would otherwise throw on a second pairing of the same payer.
- **Fix the lookup.** `BankAccountMappingRepository::findByAccountNumber()` currently does `setMaxResults(1)` with no `ORDER BY`. Replace it with:

```php
/** @return BankAccountMapping[] */
public function findAllByAccountNumber(string $accountNumber): array
```

ordered by `createdAt DESC`, and change the matcher at `ProcessIncomingBankTransactionHandler.php:158-161` to auto-match **only when exactly one mapping exists**. Two or more mappings for one account means the payer funds several orders and we cannot know which this transfer is for — fall through to `unmatched` and let a human decide. Log an audit event (`account_mapping_ambiguous`) so the ambiguity is visible rather than silent.

Keep `findByAccountNumber()` only if something else uses it; otherwise remove it with the call site.

### 7. Correct the stale spec claim

`.claude/specs/049-fio-bank-transfer-payments.md:14` asserts the admin page has "manual pairing for edge cases". It never shipped. Amend that line to note it was deferred by 078 and delivered by this spec. `BACKLOG.md:92`'s deferral note stays as history.

### 8. Tests

**Unit — `PaymentAllocator` is the core; cover it hard.**
- Debt before cycle: order with a 1 500 debt and an active manual-billing contract at 3 100 receiving 1 500 settles the **debt**, not the cycle. This is the regression test for the reordering.
- Exact cycle payment settles the cycle and leaves zero credit.
- Under-payment (2 000 of 3 100) leaves the cycle unsettled, credit 2 000, no `ProcessBankTransferPaymentCommand` dispatched.
- Follow-up 1 100 drains credit to 0 and settles the cycle — the case `tryAccumulatePartialPayments()` used to handle, now via credit.
- Over-payment (3 500 of 3 100) settles the cycle and leaves 400 credit.
- Waterfall spill: 5 000 against a 1 500 debt + 3 100 cycle settles both and credits 400.
- Order with no contract: surplus is reported as `unallocated`, not silently dropped.
- Plans are pure — calling `plan()` twice mutates nothing.

**Integration**
- `AdminBankTransactionPairController`: `ROLE_ADMIN` GET → 200 for both picker and confirm; POST → 302 to `admin_bank_payments` and the transaction becomes `matched` with `paired_by` set to the acting admin (assert this explicitly — it is the column that has never been populated).
- **Authorization, per `CLAUDE.md`:** unauthenticated → redirect to `/login`; `ROLE_USER` and `ROLE_LANDLORD` → 403. `/portal/admin/*` is only `ROLE_USER` at the firewall, so the `ROLE_ADMIN` gate is the controller's and must be tested.
- Pairing a `matched` transaction → rejected.
- Pairing an `amount_mismatch` row to a different order clears the previous pairing.
- `rememberSenderAccount: true` creates exactly one `BankAccountMapping`; pairing the same payer to the same order twice does not violate the unique constraint.
- Two mappings on one account → the matcher does **not** auto-match and the row stays `unmatched`.
- Add the pair route to `tests/Integration/Controller/ControllerAccessTest.php` data providers where its shape fits.

## Acceptance

- [ ] An admin can pair an `unmatched` transaction to an order from `/portal/admin/bankovni-platby` and the row becomes `matched` with `paired_by` = that admin and `match_method` = `manual`.
- [ ] `amount_mismatch` rows show **Spárovat** instead of `—`.
- [ ] The confirm screen shows the same allocation that is executed on submit.
- [ ] A payment against an order with both a debt and an active cycle settles the **debt** first — via the cron and via manual pairing, identically.
- [ ] Paying 2 000 then 1 100 against a 3 100 cycle settles the cycle on the second payment; `creditBalance` returns to 0.
- [ ] Paying 3 500 against a 3 100 cycle settles it and leaves `creditBalance = 400`, which offsets the next cycle.
- [ ] Ticking "Zapamatovat si účet odesílatele" creates a `BankAccountMapping`; an account with two mappings no longer auto-matches.
- [ ] `bin/console doctrine:schema:validate` is green; the migration was generated by `make:migration`, not handwritten.
- [ ] `composer quality` is green.
- [ ] `composer test` is green.
- [ ] No `flush()` added outside the `CLAUDE.md` exceptions.
- [ ] `049-fio-bank-transfer-payments.md:14` no longer claims manual pairing already exists.

## Out of scope

- **Unpairing / reversing a pairing.** Pairing dispatches payments, Fakturoid invoices and customer e-mails that an unpair cannot roll back; a button implying otherwise is worse than none. Mis-pairings are corrected through the existing refund/credit-note process.
- **Password gate on pairing.** Decided against — pairing is routine daily reconciliation and a prompt on every transfer trains admins to click through it. `Ignorovat` set this precedent in 078.
- **Refunding a credit balance to the customer.** Credit offsets future cycles only. Cash refunds stay a manual bank operation.
- **Exposing `creditBalance` to the customer** on `/stav` or the portal. Admin-visible first; customer-facing copy needs its own wording pass.
- **Pairing to a `Fine`.** Fines auto-match on their own VS (`variable_symbol_fine`); no observed failures. Add later if one appears.
- **Retro-allocating historical `matched` rows** through the new waterfall. Past reconciliations stand.
- **Removing `Order.onboardingDebtInHaler` in favour of the credit model.** Different concept, different spec.
- **The VS leading-zero fix.** Spec 090 — deploy that first.

## Open questions

None — proceed.
