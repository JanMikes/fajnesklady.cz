# 077 — Contract prolongation (prodloužení smlouvy) with payment-method switch

**Status:** done
**Type:** feature
**Scope:** large (~1 new entity + 4 controllers + command + event/handlers + 6 template touches + webhook branch)
**Depends on:** spec 076 (fixed-term-only model, availability guarantee, hard stop at endDate)

## Problem

After spec 076 every contract hard-stops at `endDate`: recurring charges cease, the token is voided, the cron terminates the contract, and the unit frees up. The only continuation path is the old spec-014 "renew" flow, which creates a **brand-new order** (new checkout, new contract, new signature). The business wants a one-click **prolongation of the existing contract**: if the unit isn't taken by someone else after the current end (guaranteed for card-recurring customers — that's the product promise from 076), the customer picks a new final date and the contract simply doesn't end. It must be effortless for the customer, allow keeping or switching the payment method (card ⇄ bank transfer), and be fully auditable (admin audit log + visible history on the admin order detail).

## Goal

- Customer opens a "Prodloužit smlouvu" page (signed link from the expiration e-mail, or from portal order detail / public `/stav`), sees the earliest-possible and latest-possible new end date, picks a date, optionally switches payment method, confirms — the contract's `endDate` moves, billing continues seamlessly, a confirmation e-mail arrives.
- Prolongation is possible until midnight of `date_to` (user decision). After that the contract is terminated and the page redirects the customer to the existing new-order renew flow.
- If they keep GoPay card, **the same token keeps charging** (user decision) — no new consent flow needed.
- Every prolongation is recorded append-only (`ContractProlongation`), in the audit log, and rendered on the admin order detail.
- The implementation is deliberately seam-ed so "prolong in place" can later be swapped for "create follow-up contract" without touching UI/CTAs: all mutations go through `ProlongContractCommand` — swapping strategy = swapping the handler.

## Context (current state)

- **Audit-trail pattern to mirror**: `src/Entity/ContractPriceChange.php` (immutable row), event `src/Event/ContractPriceChanged.php` recorded via `Contract::recordThat()`, persister `src/Event/PersistContractPriceChangeHandler.php`, repo `ContractPriceChangeRepository::findByContractOrderedByDate()`, admin partial `templates/admin/order/_price_change_history.html.twig` loaded in `AdminOrderDetailController.php:83-85`.
- **Auth pattern for the public page**: `OrderRenewController.php:56-61` — owner check OR `UriSigner->checkRequest()`; signed URL built by `OrderStatusUrlGenerator::generateRenewal()` (`src/Service/OrderStatusUrlGenerator.php:47-56`). Follow it exactly.
- **Expiration e-mail** (30/7/1 days, `SendExpirationRemindersCommand`): CTA currently `renewalUrl` → `public_order_renew` (`SendContractExpiringReminderHandler.php:43`, `templates/email/contract_expiring.html.twig:164-173`). Portal CTA: `portal/user/order/detail.html.twig:52-85`. `/stav` (`public/order_status.html.twig`) has **no** continuation CTA today.
- **Billing state after 076**: `Contract.nextBillingDate` goes `null` once the last (prorated) cycle is charged; `paidThroughDate` lands exactly on `endDate`. `Contract::recordBillingCharge()` no longer touches `endDate`. `findDueForTermination` reaps contracts past `endDate` (incl. card contracts with live tokens — voiding them). `RecurringAmountCalculator::calculate()` (`src/Service/Billing/RecurringAmountCalculator.php:37-62`) prorates against `getEffectiveEndDate()` using `nextBillingDate` as period start — moving `endDate` and re-seeding `nextBillingDate` is sufficient for both AUTO and MANUAL cadences to resume correctly.
- **Availability**: `StorageAvailabilityChecker::isAvailable()` supports contract exclusion (used by `StorageAssignment::findPreferredStorageForUser`, `src/Service/StorageAssignment.php:91` — verify the exact parameter signature before use). Card contracts with live tokens block open-endedly (076), so a card customer's prolongation window is always free; bank/one-time contracts block only to `endDate`, so a third party may hold `[endDate+…]`.
- **GoPay**: `GoPayClient` (`src/Service/GoPay/GoPayClient.php`) has `createRecurringPayment(Order, …)` (ON_DEMAND parent, amount = `order->firstPaymentPrice`) — there is **no generic amount-based recurring-parent primitive** (the one-shot side has `createOneTimeCharge`). `voidRecurrence()` cancels a token. Webhook dispatcher: `ProcessPaymentNotificationHandler` branch chain (order → debt → manual cycle → fine → parentId safety net) at `src/Command/ProcessPaymentNotificationHandler.php:80-169`.
- **Compliance** (`.claude/COMPLIANCE.md`): establishing a NEW recurring token requires the dedicated consent checkbox + parameter card (exact labels/order), PCI-DSS text below it, card/3DS/GoPay logos, and the `RecurringPaymentEstablished` confirmation e-mail within 2 working days (handler exists). Consent record (timestamp, IP, params) retained ≥12 months. Keeping an existing token needs none of that.
- Overdue signal: `Contract.failedBillingAttempts` / `outstandingDebtAmount` (used by `OverdueChecker`).

## Architecture

```
contract_expiring e-mail ──┐
portal order detail ───────┼──► GET /smlouva/{id}/prodlouzit  (signed OR owner)
public /stav ──────────────┘         │  shows: current end, date picker [min=end+1d, max=day before next conflict],
                                     │         payment method keep/switch radio, guarantee/no-guarantee notes
                                     ▼
                          POST (same route) ──► ProlongContractCommand
                                     │   handler: lock storage → availability re-check (exclude own contract+order)
                                     │            → Contract::prolong(newEnd) [records ContractProlonged]
                                     │            → card→bank switch (void token, MANUAL, VS)  [optional]
                                     │            → audit log 'prolonged'
                                     ▼
             ContractProlonged event ──► PersistContractProlongationHandler (append-only row)
                                     └─► SendContractProlongedEmailHandler (customer confirmation)

bank→card switch (optional extra step after prolong):
  GET  /smlouva/{id}/prodlouzit/karta   — consent card + parameters + PCI + logos (full compliance surface)
  POST /smlouva/{id}/prodlouzit/karta/iniciovat — createRecurringCharge(amount = next due cycle) → pendingCardSetupPaymentId
  GET  /smlouva/{id}/prodlouzit/karta/navrat    — re-dispatch ProcessPaymentNotificationCommand → redirect /stav
  webhook: new branch — PAID → token stored, billingMode→AUTO, recordBillingCharge(cycle), RecurringPaymentEstablished
```

## Requirements

### 1. `Contract::prolong()` + `ContractProlonged` event

```php
// src/Entity/Contract.php
public function prolong(\DateTimeImmutable $newEndDate, ?User $prolongedBy, \DateTimeImmutable $now): void
{
    if (null !== $this->terminatedAt) { throw new \DomainException('Cannot prolong a terminated contract.'); }
    if ($this->hasPendingTermination()) { throw new \DomainException('Cannot prolong a contract with a pending termination.'); }
    if ($newEndDate <= $this->endDate) { throw new \DomainException('New end date must be after the current end date.'); }

    $previousEndDate = $this->endDate;
    $this->endDate = $newEndDate;

    // Resume billing when the final (prorated) cycle already closed the schedule.
    if ($this->billingMode->isRecurring() && null === $this->nextBillingDate && !$this->isFree()) {
        $this->nextBillingDate = $this->paidThroughDate ?? $previousEndDate;
    }

    $this->recordThat(new ContractProlonged(
        contractId: $this->id,
        previousEndDate: $previousEndDate,
        newEndDate: $newEndDate,
        prolongedBy: $prolongedBy?->id,
        occurredOn: $now,
    ));
}
```

`ContractProlonged` is a `final readonly` event DTO (`src/Event/ContractProlonged.php`). Note: mid-term prolongation (nextBillingDate still set) needs no re-seed — the cadence continues and `RecurringAmountCalculator` prorates against the new `getEffectiveEndDate()` automatically. The billing anchor day may shift to the old endDate's day-of-month when re-seeded — accepted; the confirmation e-mail (req 6) shows the next charge date explicitly.

### 2. `ContractProlongation` entity + persister + repo (mirror `ContractPriceChange` 1:1)

Table `contract_prolongation`, index `(contract_id, prolonged_at)`. Immutable constructor-promoted fields: `Uuid $id`, `Contract $contract` (CASCADE), `\DateTimeImmutable $previousEndDate` (DATE), `\DateTimeImmutable $newEndDate` (DATE), `\DateTimeImmutable $prolongedAt`, `?User $prolongedBy` (SET NULL), `string $billingModeAfter` + `string $paymentMethodAfter` (snapshot for the history table). `PersistContractProlongationHandler` (`#[AsMessageHandler]` on `ContractProlonged`) loads the contract and appends the row. `ContractProlongationRepository`: `save()`, `findByContractOrderedByDate()`.

### 3. `ProlongContractCommand` + handler (the strategy seam)

`final readonly ProlongContractCommand(Uuid $contractId, \DateTimeImmutable $newEndDate, ?PaymentMethod $switchTo, ?Uuid $actorId)`.

Handler steps (this is the ONLY place that mutates; future "new contract instead" strategy replaces just this handler):
1. Load contract (or `ContractNotFound`); `storageRepository->lockForBooking($contract->storage)` (same lock as `OrderService::createOrder:102`).
2. Availability re-check: window `[contract->endDate, newEndDate]`, excluding the contract itself AND its order (`StorageAvailabilityChecker::isAvailable(...)` with exclusions — extend the checker with an `excludeOrder` param if only `excludeContract` exists; reuse the existing predicate plumbing, do not fork it). Failure → `StorageHasActiveRental`-style domain exception with Czech message `'Skladovací jednotku má po konci vaší smlouvy rezervovanou někdo jiný.'`
3. `$contract->prolong($newEndDate, $actor, $now)`.
4. `switchTo === BANK_TRANSFER` on an AUTO contract → `goPayClient->voidRecurrence()` (if live token) + `$contract->cancelRecurringPayment()` **then** re-seed `nextBillingDate` (cancel nulls it — set back to `paidThroughDate ?? previousEnd`) + `$contract->applyBillingMode(BillingMode::MANUAL_RECURRING)` + ensure `order->variableSymbol` (generate via `VariableSymbolGenerator` when null). A ONE_TIME contract being prolonged is converted the same way: `applyBillingMode(MANUAL_RECURRING)`, `paidThroughDate` treated as previous `endDate` (fully paid one-shot), `nextBillingDate = previousEnd`, VS ensured — the spec-036 manual cron takes over the extension cycles.
5. `switchTo === GOPAY` is NOT handled here — the card setup is asynchronous (req 5); the handler leaves the contract on its current mode.
6. Audit: new `AuditLogger::logContractProlonged(Contract $contract, \DateTimeImmutable $previousEnd): void` → `log('contract', …, 'prolonged', ['previous_end_date', 'new_end_date', 'billing_mode', 'payment_method'], orderId, userIdContext)`.

No manual `flush()` anywhere — command-bus middleware handles it.

### 4. Public prolongation page

`src/Controller/Public/ContractProlongController.php`, route `/smlouva/{contractId}/prodlouzit`, name `public_contract_prolong`, GET+POST, single action. No firewall — guard = owner OR `UriSigner` (copy `OrderRenewController.php:56-61`). New `OrderStatusUrlGenerator::generateProlongation(Contract $contract): string` (mirror `generateRenewal`).

Eligibility (GET+POST): contract not terminated, no pending termination, `endDate >= today` (prolong allowed through the last day — "do půlnoci date_to"), order status COMPLETED, place active. Ineligible because ended/terminated → render a friendly "Smlouva již skončila" state with CTA to `public_order_renew` (the new-order path). Additional guard: `failedBillingAttempts > 0` or `outstandingDebtAmount > 0` → blocked with `'Prodloužení je možné až po uhrazení dlužných plateb.'` (matches the no-prolongation-in-arrears principle of the contract terms).

GET renders `templates/public/contract_prolong.html.twig`:
- Contract summary (unit, place via `place_address`, current `endDate`, current price/cadence from `Contract::getEffectiveRecurringAmount()` — always `vč. DPH`).
- New end date: flatpickr, `min = endDate + 1 day`; `max` = day before the earliest conflicting block, computed server-side via new `StorageAvailabilityChecker::earliestConflictStart(Storage $storage, \DateTimeImmutable $from, Contract $exclude): ?\DateTimeImmutable` (min `startDate` over overlapping orders/contracts/unavailabilities for the open window `[from, null]`, excluding own contract+order; reuses the three existing `findOverlappingByStorage` methods). No conflict → unbounded. Conflict starting ≤ `endDate + 1 day` → page states the unit is taken and offers the renew-flow CTA instead of the form. For card contracts with a live token the window is always unbounded (076 guarantee) — assert that in a test.
- Payment method block reusing the two 076 texts: keep-current radio preselected; switch option shown for the other method (card→bank shows the surcharge + "Negarantujeme dostupnost…" note; bank→card shows the green guarantee sentence + note that card setup completes on the next page). Bank→card option hidden for free contracts and for `days-remaining` windows that would be < 31 days total? No — card eligibility here concerns the recurring cadence, which is monthly regardless; offer it whenever the contract isn't free.
- Submit POSTs `newEndDate` + `paymentChoice` (`keep` | `bank` | `gopay`); CSRF token (plain form, follow `ContractTerminateController`'s pattern); server re-validates everything (min/max are UX only).

POST: dispatch `ProlongContractCommand` (unwrap via `HandlerFailureUnwrap` when catching domain errors for flash messages — see `.claude/MESSENGER.md`). On success: `paymentChoice === 'gopay'` and contract not already AUTO → redirect to the card-setup page (req 5); otherwise flash `'Smlouva byla prodloužena do %s.'` and redirect to the signed `/stav` URL.

### 5. Bank→card switch: token setup payment

- New nullable column `Contract.pendingCardSetupPaymentId` (string) + `Contract::startCardSetup(string $paymentId)` / `Contract::completeCardSetup(...)` behavior methods.
- `GoPayClient` gains the missing generic primitive: `createRecurringCharge(int $amount, string $orderNumber, string $orderDescription, string $payerEmail, string $returnUrl, string $notificationUrl): GoPayPayment` — identical to `createOneTimeCharge` payload plus the `recurrence` block (`ON_DEMAND`, `recurrence_date_to: '2099-12-31'`) copied from `createRecurringPayment` (`GoPayApiClient.php:70-103`). Refactor `createRecurringPayment(Order …)` to delegate to it. Update `MockGoPayClient`/test doubles.
- `src/Controller/Public/ContractCardSetupController.php` — GET `/smlouva/{contractId}/prodlouzit/karta` (`public_contract_card_setup`), same guard chain as req 4 + contract must be MANUAL_RECURRING, not free, no live token. Renders the **full compliance surface** (copy the parameter card + dedicated consent checkbox + PCI-DSS block + logos from `order_accept.html.twig:386-486`, amounts from `Contract::getEffectiveRecurringAmount()`, "Doba trvání" = `'{N} platby do {endDate}'`), plus GoPay embed JS button wired like `order_payment.html.twig`.
- `src/Controller/Public/ContractCardSetupInitiateController.php` — POST `…/karta/iniciovat`: requires the consent checkbox value; computes `amount = RecurringAmountCalculator::calculate($contract, $now)` (this payment **replaces the next manual cycle**); calls `createRecurringCharge(...)`; stores id via `startCardSetup()`; audit `log('contract', …, 'recurring_consent_given', [amount, ip via AuditLogger])` (12-month retention obligation); returns `{gwUrl}` JSON (mirror `FinePaymentInitiateController`).
- `src/Controller/Public/ContractCardSetupReturnController.php` — GET `…/karta/navrat`: re-dispatch `ProcessPaymentNotificationCommand($contract->pendingCardSetupPaymentId)` synchronously (mirror `PaymentReturnController.php:46`), then redirect to signed `/stav` with a status flash.
- `ProcessPaymentNotificationHandler`: new branch after the fine branch — `contractRepository->findByPendingCardSetupPaymentIdForUpdate($id)`; on `isPaid()`: `setRecurringPayment($status->id, nextBillingDate, paidThroughDate)` (dates advanced one cadence from the old `nextBillingDate`, clamped to `endDate` exactly like `reconcileManualPayment` `:271-334` — reuse that math, don't duplicate: extract a small private helper if needed), `applyBillingMode(BillingMode::AUTO_RECURRING)`, `recordBillingCharge(...)`, clear `pendingCardSetupPaymentId`, dispatch `RecurringPaymentCharged` (invoice + Payment row) **and** `RecurringPaymentEstablished` (compliance confirmation e-mail), audit `recurring_established_on_prolong`. On terminal-cancelled status → just clear `pendingCardSetupPaymentId` (contract stays MANUAL; nothing lost).
- If the customer abandons the card setup, the prolongation stays valid on the bank/manual track — no cleanup needed beyond the terminal-status clear.

### 6. Confirmation e-mail

`SendContractProlongedEmailHandler` on `ContractProlonged` → `templates/email/contract_prolonged.html.twig`: subject `'Smlouva prodloužena do %s - %s'` (date, place). Body: unit + place, new end date, payment cadence line (AUTO: "platby kartou pokračují automaticky, další platba {nextBillingDate}"; MANUAL: "před další platbou {nextBillingDate} Vám pošleme e-mail s platebními údaji a QR kódem"), CTA to signed `/stav`. Log via the standard `EmailLogger` path with `X-Order-Id` header (spec 056 convention). No admin e-mail variant — admins see it in the audit timeline (stated decision).

### 7. CTAs

- `SendContractExpiringReminderHandler.php:43`: `renewalUrl` → `prolongUrl = $statusUrlGenerator->generateProlongation($contract)`; `contract_expiring.html.twig` button text stays "Prodloužit pronájem".
- `portal/user/order/detail.html.twig:52-85`: while the contract is prolongable (active, not terminated, endDate ≥ today) the primary button links to `public_contract_prolong` (owner-authenticated, unsigned path OK — guard allows owner); after the contract ended, the existing renew-flow button remains as "Objednat znovu".
- `public/order_status.html.twig`: in the active-contract state add a "Prodloužit smlouvu" CTA linking to the signed prolong URL (the page already receives the order/contract; generate the URL in `OrderStatusViewModelFactory` or the controller, whichever already assembles CTAs).

### 8. Admin visibility

- `AdminOrderDetailController`: load `contractProlongationRepository->findByContractOrderedByDate()` and render new partial `templates/admin/order/_prolongation_history.html.twig` ("Historie prodloužení": date, actor e-mail or "zákazník"/"systém", `previousEndDate → newEndDate`, billing mode + payment method after) — mirror `_price_change_history.html.twig` structure, placed next to it.
- `templates/admin/order/_activity_timeline.html.twig`: add `eventLabels` entries — `'prolonged' => 'Smlouva prodloužena'`, `'recurring_consent_given' => 'Souhlas s opakovanou platbou (prodloužení)'`, `'recurring_established_on_prolong' => 'Opakovaná platba nastavena (prodloužení)'` + green icons.

### 9. Migration

Generated only (`make:migration`): `contract_prolongation` table + `contract.pending_card_setup_payment_id`.

### 10. Tests

- Unit: `Contract::prolong()` guards + billing re-seed matrix (mid-term AUTO, post-final-charge AUTO, MANUAL, ONE_TIME conversion, free contract no re-seed), `ContractProlonged` recording.
- Integration: prolong page — owner 200, signed anonymous 200, unsigned anonymous 403/404, wrong user 403 (ControllerAccessTest additions per CLAUDE.md rules for signed public routes); POST happy path moves endDate + persists `ContractProlongation` + audit row + e-mail logged; conflict (another order after endDate) → blocked with message and max-date clamp; card contract window unbounded; terminated/pending-termination/overdue → blocked; card→bank voids token (mock assert) + VS assigned; card-setup webhook branch establishes token + `RecurringPaymentEstablished` dispatched + invoice issued; abandoned setup terminal status clears pending id.
- Full `composer test` (controller/template changes).

## Acceptance

- [ ] Expiration e-mail CTA opens the prolong page without login; picking `endDate + 6 months` on an active card contract prolongs it, keeps the same `goPayParentPaymentId`, and the next cycle charges up to the new end (verify `RecurringAmountCalculator` output in test).
- [ ] Bank contract with a third-party order starting 10 days after `endDate`: date picker max = day before that order's start; choosing a later date server-side → rejected.
- [ ] Bank contract, unit taken immediately after end → page explains and links to the new-order flow.
- [ ] ONE_TIME (short bank) contract prolongs → becomes MANUAL_RECURRING with `nextBillingDate = old endDate` and VS present; manual cron then e-mails the extension cycle with QR.
- [ ] Card→bank switch: token voided, MANUAL cycles resume, no card surface shown afterwards.
- [ ] Bank→card switch: consent page shows the full parameter card + PCI text + logos; after GoPay PAID webhook the contract is AUTO with token, an invoice + Payment row exist, and the `RecurringPaymentEstablished` confirmation e-mail is logged.
- [ ] Admin order detail shows "Historie prodloužení" with the row, and the activity timeline shows "Smlouva prodloužena".
- [ ] Prolongation on the last day (`endDate == today`) works; the day after (contract terminated by cron) the page shows the ended state.
- [ ] `composer quality` green and full `composer test` green; `doctrine:schema:validate` clean.

## Out of scope

- Admin-initiated prolongation UI (admin can advise the customer to use the link; a dedicated admin action is a follow-up spec).
- Price re-negotiation at prolongation — the contract keeps its locked amounts (`individualMonthlyAmount` / storage effective rates via existing `isLongTermMonthly()` recomputation only; note: crossing the 180-day total-duration threshold may change the effective monthly rate — that is existing `getEffectiveMonthlyAmount()` behavior, not new logic).
- Prolonging YEARLY contracts to a different frequency — frequency is locked; yearly contracts prolong with yearly cycles.
- Settling arrears inside the prolong flow — blocked with a message instead (spec 019 territory).
- "Create follow-up contract instead of prolonging" strategy — kept open via the `ProlongContractCommand` seam; not built now.

## Open questions

None — proceed.
