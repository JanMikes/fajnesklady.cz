# 076 — Remove limited/unlimited rental type; card = recurring monthly only; availability guarantee by payment method

**Status:** done
**Type:** feature / refactor (system-wide payment-model change)
**Scope:** large (~60 src/template files + ~78 test files churn + 2 migrations)
**Depends on:** none (supersedes behavior from specs 036, 044, 045, 052, 058 in the areas below)

## Problem

Today a customer chooses between a LIMITED rental (fixed `endDate`, capped at 1 year) and an UNLIMITED rental (no `endDate`, auto-extending contract). GoPay cards can pay one-shot (<31 days), auto-recurring, or per-cycle one-time links (MANUAL track + yearly). Storage blocking is inconsistent: an UNLIMITED **order** row blocks a unit forever (`endDate IS NULL`), but once the contract exists it only blocks ~1 cadence ahead (endDate advances per charge) — a future booking placed >1 month out can collide with an auto-extending contract. The business has decided on a final model: **every rental is fixed-term**, **cards may only be used for automatic monthly recurring payments** (never one-shot, not even yearly), and **unit availability guarantee is the card product's selling point** — card-recurring rentals block the unit indefinitely while the contract lives; everything else frees the unit the day the rental ends.

## Goal

- `RentalType` and `ExpectedDuration` are gone. Every new order requires `endDate`. No 1-year cap.
- Payment matrix (the ONLY combinations that can exist for new orders):

| Duration `d` | GoPay card | Bank transfer |
|---|---|---|
| 7 ≤ d < 31 days | **not offered** (radio disabled + hint) | ONE_TIME (whole prorated amount, QR) |
| d ≥ 31 days | AUTO_RECURRING + MONTHLY (forced; no billing-mode radio anywhere) | MANUAL_RECURRING + MONTHLY |
| d ≥ 360 days | (yearly NOT payable by card) | MANUAL_RECURRING + MONTHLY **or** YEARLY (−10 % badge) |

- Admin onboarding: same rules, plus `EXTERNAL → MANUAL_RECURRING` (billing-mode radio removed there too).
- **No contract auto-extends.** Recurring charges stop at `endDate` (last one prorated — already the case). A card contract past `endDate` is terminated by cron **and its GoPay token voided**. Customers get the existing 30/7/1-day expiration reminders (prolongation CTA arrives in spec 077).
- Availability: AUTO_RECURRING orders/contracts block their storage **open-endedly** while alive; every other order/contract blocks only `[startDate, endDate]`. This is the "guarantee" — nobody can pre-book a card customer's unit for any future window.
- Order form UX: quick presets 3/6/12 months, datepicker end cap removed, guarantee note under "Způsob platby", no-guarantee note for bank transfer, static "−10 %" pictogram on yearly.
- Legacy production data is **migrated, not grandfathered** (user decision): manual-cycle GoPay card links disappear (those contracts flip to bank transfer + VS), ex-UNLIMITED contracts keep their current (frozen) `endDate` and flow into the reminder→terminate→prolong lifecycle.

## Context (current state)

Read `.claude/specs/PROJECT_MAP.md` first. Everything below was verified 2026-07-02.

**Enums & entities**
- `src/Enum/RentalType.php` (LIMITED/UNLIMITED), `src/Enum/ExpectedDuration.php` (SHORT/MEDIUM/LONG) — to delete.
- `src/Entity/Order.php:184-185` non-null `rentalType` column; `:159-160` nullable `expectedDuration`; `:332-335` `isUnlimited()`; `:464-467` `setExpectedDuration()`. `Order.endDate` is nullable (legacy UNLIMITED rows are NULL and must stay NULL — historical truth).
- `src/Entity/Contract.php:130-131` non-null `rentalType`; `:228-232` `recordBillingCharge()` advances `endDate` for UNLIMITED recurring (the auto-extension); `:389-399` `isLongTermMonthly()` branches on UNLIMITED; `:201-204` `isUnlimited()`. `Contract.endDate` is nullable in schema but **every row has a value** since spec 058's backfill (`migrations/Version20260527130100.php`).
- `src/Enum/BillingMode.php` — ONE_TIME / AUTO_RECURRING / MANUAL_RECURRING; stays, gains `derive()`.

**Payment matrix today** — `src/Form/OrderFormData.php`: `validateDates()` `:213-256` (7-day floor + 1-year ceiling `'Doba určitá může být maximálně 1 rok…'`), `validateExpectedDuration()` `:258-270`, `validateBillingMode()` `:272-313` (mutates billingMode; bank→MANUAL, short→ONE_TIME, UNLIMITED→AUTO violation), `validatePaymentFrequency()` `:315-344` (yearly ≥360d, pins MANUAL). `OrderFormType.php`: `rentalType` `:155-163`, `expectedDuration` `:164-176`, `billingMode` radio `:194-208`, `paymentFrequency` `:209-220`, `paymentMethod` `:221-230`. Live Component `src/Twig/Components/OrderForm.php`: `resolveWindow()` `:130-152`, `isEligibleForBillingModeChoice()` `:224-240`, `isEligibleForFrequencyChoice()` `:247-263`, `getApplicableRate()` `:268-297`, `getPaymentSchedule()` `:318-342`, `submit()` nulls endDate for UNLIMITED `:394-421`.
- Admin: `src/Form/AdminOnboardingFormData.php` (`validateDates` `:165-191` — no 1-year cap, keep it that way; `validateBillingMode` `:224-252`; `validatePaymentFrequency` `:254-275`; `validateExpectedDuration` `:277-289`; `validatePaidThroughDate` LIMITED branch `:356-363`), `AdminOnboardingFormType.php` (`rentalType` `:101-110`, `expectedDuration` `:111-117`, `billingMode` `:140-150`), `src/Twig/Components/AdminOnboardingForm.php` (`resolveWindow` `:165-187`, `submit` `:346-469`), `src/Command/AdminOnboardingHandler.php:33-111` (forced EXTERNAL for free/prepaid — keep).
- Locking: `OrderAcceptController.php:386-403` sets billingMode/paymentMethod/VS on the Order; `OrderService::completeOrder()` `:187-241` copies to Contract; `CompleteOrderHandler.php:24-77` seeds `nextBillingDate`.

**GoPay** — `src/Service/GoPay/GoPayClient.php` + `GoPayApiClient.php`: `createPayment` (one-shot), `createOneTimeCharge` (one-shot primitive), `createRecurringPayment` (ON_DEMAND parent), `createRecurrence` (token charge), `voidRecurrence`. Creation sites: `InitiatePaymentHandler.php:34-45` (branches on billingMode — ONE_TIME/MANUAL → one-shot), `DispatchManualBillingNotificationHandler.php:138-168` (per-cycle one-shot for card MANUAL contracts — **to remove**), `InitiateFinePaymentHandler.php:33` + `InitiateDebtPaymentHandler.php:26` (one-shot — **keep**, out of scope), `ChargeRecurringPaymentHandler.php:98` (token charge — keep).

**Availability** — `src/Service/StorageAvailabilityChecker.php` (`isAvailable` `:39-84`, `availabilityForStorages` `:97-133`, `decide` `:141-160`); predicates in `OrderRepository::findOverlappingByStorage(s)` `:250-323` (blocking statuses CREATED/RESERVED/AWAITING_PAYMENT/PAID; `o.endDate IS NULL OR o.endDate >= :startDate`), `ContractRepository::findOverlappingByStorage(s)` `:198-223`, `:349-380` (`c.terminatedAt IS NULL` + date overlap), `StorageUnavailabilityRepository` `:75-99`, `:167-193`. **The infinite block today comes only from `orders.end_date IS NULL`** — completed UNLIMITED contracts block just ~1 cadence ahead. `CreateStorageUnavailabilityHandler.php:32-76` rejects blocks overlapping active rentals (will automatically respect the new open-ended windows).

**Lifecycle** — `Contract::isActive()` `:176-189` + `isInBillingGrace()` `:376-387`; `ChargeRecurringPaymentHandler.php:185` and `ProcessPaymentNotificationHandler.php:199,:280` stop-billing guards with `!$contract->isUnlimited()` escape; `ContractRepository::findDueForTermination()` `:509-527` (AUTO reaped only when `goPayParentPaymentId IS NULL`); `ContractService::findContractsExpiringOnDay()` `:136-164` skips auto-extending contracts from reminders; `ContractService::terminateContract()` `:61-84` already voids a live token.

**UI/JS** — `assets/controllers/duration_preset_controller.js:3` `PRESETS = [1, 3, 6]`; buttons + `max-months-value="12"` at `templates/components/OrderForm.html.twig:264-289`; admin wrapper `AdminOnboardingForm.html.twig:109-113` (`max-months-value="0"`). OrderForm sections: Typ pronájmu `:202-244` (incl. the amber "Negarantujeme dostupnost stejné skladovací jednotky…" note at `:221` and UNLIMITED note `:232`), Období `:246-295`, Frekvence `:297-313`, **Způsob platby `:315-334`** (surcharge amber box `:324-333`), billingMode/yearly `:336-353`, schedule preview `:355-409`, Ceník `:561-636` ("Zvýhodněná cena" badge `:614-629`), submit `:474-484`. Recurring consent on `/prijmout`: `order_accept.html.twig:358-371` (MANUAL info card) and `:386-473` (AUTO parameters + dedicated checkbox) — gating unchanged. `customer_signing.html.twig:81` shows `Doba neurčitá/určitá`; showManualInfo copy mentions a payment **link**.

**Pricing** — `src/Service/PriceCalculator.php`: thresholds `:17-19` (31/180/360), `isEligibleForYearly(RentalType, …)` `:202-213`, `buildPaymentSchedule` `:237-311` + `buildScheduleFromOrder` `:322-382` (null-end open-ended branches — **keep**, legacy orders still render), `needsRecurringBilling` `:218-225`. Yearly price = independently configured (`StorageType.defaultPricePerYear`, required int; `Storage.pricePerYear` override). Surcharge is **display-only** (`PlatformSettings.bankTransferSurchargeInHaler`, rendered at OrderForm `:330`).

**Display surfaces branching on rentalType/isUnlimited** — order detail templates (`admin/order/detail.html.twig:272-297`, `portal/landlord/order/detail.html.twig:123-148`, `portal/user/order/detail.html.twig:145-171` + renew gate `:54-56`), `public/order_status.html.twig:274-276`, `public/customer_signing.html.twig:81`, `email/contract_expiring.html.twig:168-171` (`isLimited` var from `SendContractExpiringReminderHandler.php:66`), occupancy/calendar "↻ Automatické prodlužování" markers (`portal/dashboard_user.html.twig:101`, `portal/place/contracts.html.twig:84`, `portal/place/occupancy.html.twig:146`, `portal/storage/list.html.twig:196`, `portal/storage_type/occupancy.html.twig:171,256`, `portal/calendar/index.html.twig:254,321`), `PlaceOccupancyMap.php:132` + `storage_map_controller.js:605,690` (`isUnlimited` → "neomezeně"), `RentalSpan.php:25-32`, `StorageRentalView.php:45-48`, Excel exports "Délka pronájmu" (`AdminOrderExportController.php:55,79`, `LandlordOrderExportController.php:47,67`), `AuditLogger.php:48,156` payload keys, `ContractDocumentGenerator.php` (already branches on endDate, rentalType param unused `:219-232`), `OrderRenewController.php:92-105`.

**Fixtures** — `OrderFixtures` (UNLIMITED at `:145-166` → `REF_ORDER_COMPLETED_UNLIMITED`, `:230-242`), `ContractFixtures` (`:99-125` → `REF_CONTRACT_UNLIMITED`, `:188-198`), `OnboardingFixtures` `:149,:172`, `InvoiceFixtures` (transitively). `.claude/FIXTURES.md` documents them.

**Compliance** — `.claude/COMPLIANCE.md` lines 48-96 codify the OLD matrix (one-shot <28 dní, 1-rok cap, open-ended recurring, yearly = one-shot GoPay). Must be updated **in the same commit** (its own rule). User has confirmed: no changes needed to legal contract text / VOP wording for the hard-stop change.

## Architecture

```
Customer picks dates (endDate REQUIRED, ≥ start+7d, no upper cap)
        │
        ▼
Payment method radio (Způsob platby)
  ├─ GoPay card  ──► requires d ≥ 31 ──► BillingMode::AUTO_RECURRING + MONTHLY
  │                                        first payment = ON_DEMAND parent (token)
  │                                        monthly createRecurrence until endDate (last prorated)
  │                                        storage block = OPEN-ENDED while contract alive  ◄── the guarantee
  │                                        at endDate: cron voids token + terminates (unless prolonged, spec 077)
  └─ Bank transfer ──► d < 31  ──► ONE_TIME  (QR, whole amount)          block = [start, endDate]
                   ──► d ≥ 31  ──► MANUAL_RECURRING + MONTHLY (QR cycles) block = [start, endDate]
                   ──► d ≥ 360 ──► optionally YEARLY (−10 % badge)        block = [start, endDate]
(admin only) EXTERNAL ──► MANUAL_RECURRING (or free/prepaid coercion as today)
```

`billingMode` stops being a user choice everywhere — it is **derived**. The derivation lives in one place and both forms + tests use it.

## Requirements

### 1. `BillingMode::derive()` — single source of truth for the new matrix

In `src/Enum/BillingMode.php` add:

```php
public static function derive(PaymentMethod $paymentMethod, PaymentFrequency $frequency, int $rentalDays): self
{
    if (PaymentFrequency::YEARLY === $frequency) {
        return self::MANUAL_RECURRING;                       // yearly is always manual (bank-only, enforced by form validation)
    }
    return match ($paymentMethod) {
        PaymentMethod::GOPAY => self::AUTO_RECURRING,        // card = recurring monthly, nothing else
        PaymentMethod::BANK_TRANSFER => $rentalDays < PriceCalculator::WEEKLY_THRESHOLD_DAYS
            ? self::ONE_TIME
            : self::MANUAL_RECURRING,
        PaymentMethod::EXTERNAL => self::MANUAL_RECURRING,   // admin onboarding only
    };
}
```

Unit-test the full matrix. (`ONE_TIME` label and `isRecurring()` stay as-is — ONE_TIME survives as the bank-transfer short-rental mode.)

### 2. Delete `RentalType` + `ExpectedDuration`; entity changes

- Delete `src/Enum/RentalType.php` and `src/Enum/ExpectedDuration.php`.
- `Order`: drop the `rentalType` constructor param/column and `expectedDuration` column + `setExpectedDuration()`. Rename `isUnlimited()` → `isOpenEnded(): bool { return null === $this->endDate; }` with a docblock stating it can only be true for pre-2026-07 legacy rows (new orders always carry endDate). Callers that need "legacy open-ended" semantics (`PriceCalculator::buildScheduleFromOrder`, `RentalSpan`, `StorageRentalView`, status templates) switch to it.
- `Contract`: drop `rentalType`; make `endDate` **non-nullable** (`\DateTimeImmutable $endDate`) — every row has a value post-058-backfill; assert this in the schema migration. Remove the auto-extension block from `recordBillingCharge()` (`:228-232`) entirely — endDate is never advanced by billing. `isLongTermMonthly()` drops the UNLIMITED branch (pure `endDate − startDate >= 180` check). Remove `Contract::isUnlimited()`; `RecurringAmountCalculator.php:45` drops the `|| $contract->isUnlimited()` escape (effectiveEndDate is now always non-null → proration always applies on the tail).
- `CreateOrderCommand` / `CreateOrderHandler` / `AdminOnboardingCommand` / `AdminOnboardingHandler` / `OrderService::createOrder()` / `OrderService::completeOrder()`: remove `rentalType` + `expectedDuration` params and the `$order->endDate ?? startDate->modify($cadenceStep)` fallback in `completeOrder()` (order endDate is guaranteed; keep a defensive `\assert(null !== $order->endDate)`).
- `OrderService::createOrder()` gains a hard guard: `null === $endDate` → `\InvalidArgumentException` (new orders must be fixed-term).

### 3. Public order form (`OrderFormData` / `OrderFormType` / `OrderForm` component)

`src/Form/OrderFormData.php`:
- Remove `rentalType`, `expectedDuration` props + `validateExpectedDuration()`. Session round-trip (`toSessionArray`/`fromSessionArray`) drops both keys.
- `validateDates()`: endDate is now always required — message `'Vyberte datum konce pronájmu.'`; keep the 7-day floor; **delete the 1-year ceiling** block.
- Replace `validateBillingMode()` + the pinning halves of `validatePaymentFrequency()` with:
  - `validatePaymentMethod()` (new): when dates valid and `GOPAY` selected and `days < WEEKLY_THRESHOLD_DAYS` → violation on `paymentMethod`: `'Platba kartou je dostupná pro pronájmy od 31 dnů. Kratší pronájem zaplatíte bankovním převodem.'`; when `YEARLY` selected and method is `GOPAY` → violation on `paymentFrequency`: `'Roční platbu lze platit pouze bankovním převodem.'`
  - `deriveBillingMode()` (callback, runs last): when method + frequency + valid dates present, set `$this->billingMode = BillingMode::derive(...)`; otherwise leave the default. `billingMode` stays a property (session + `OrderAcceptController` locking use it) but is **no longer a form field**.
  - Keep the yearly eligibility rule: `YEARLY` with `days < YEARLY_THRESHOLD_DAYS` → existing violation `'Roční platba je dostupná pouze pro pronájem na 12 měsíců a déle.'` (endDate-based only).

`src/Form/OrderFormType.php`: remove `rentalType`, `expectedDuration`, `billingMode` fields. Keep `paymentMethod` (2 choices) and `paymentFrequency`.

`src/Twig/Components/OrderForm.php`:
- `resolveWindow()`: valid window ⇔ startDate + endDate > startDate (no rentalType branch; never returns a null end).
- Delete `isEligibleForBillingModeChoice()`. `isEligibleForFrequencyChoice()` = dates valid && `days >= YEARLY_THRESHOLD_DAYS`.
- New `isCardEligible(): bool` = dates valid && `days >= WEEKLY_THRESHOLD_DAYS` (template uses it to disable the card radio + show the hint; server validation from req 3 is the backstop).
- `getApplicableRate()` / `getPaymentSchedule()`: drop UNLIMITED branches (schedule always has a concrete end).
- `submit()`: delete the `endDate = null` UNLIMITED line.

### 4. Order form template (`templates/components/OrderForm.html.twig`)

- Delete the whole "Typ pronájmu" section (`:202-244`) including both notes and the `expectedDuration` handling.
- "Období pronájmu": `endDate` always rendered (no `rentalIsLimited` gate). Preset wrapper: `data-duration-preset-max-months-value="0"` (no cap). Buttons loop `[3, 6, 12]`, label rule: `3 → '3 měsíce'`, `6 → '6 měsíců'`, `12 → '1 rok'`.
- "Frekvence platby" (only when `isEligibleForFrequencyChoice()`): next to/under the YEARLY radio render a static badge `<span class="badge badge-success">−10 %</span>` and note `Roční platba předem se slevou. Lze platit pouze bankovním převodem.` When `paymentMethod == 'gopay'` and yearly selected, the server violation from req 3 shows; additionally render the yearly radio option visually disabled for card (progressive enhancement, server rules win).
- **"Způsob platby" section** — immediately under the `<h2>` heading, ALWAYS visible, prominent (green `alert-success`-styled box, bold lead):
  > **Při volbě automatické platby kartou garantujeme dostupnost Vaší skladovací jednotky v případě potřeby prodloužení pronájmu.**
- When bank transfer is selected, the existing amber box keeps the surcharge sentence and gains a second line (note: user supplied "dostupnosti", correct Czech is "dostupnost"):
  > Negarantujeme dostupnost vaší skladovací jednotky v případě potřeby prodloužení pronájmu.
- Card radio: when `not this.isCardEligible()` render it disabled with the short-rental hint from req 3.
- billingMode block (`:336-353`): delete (field gone). Keep the yearly info note ("Roční platba se vždy odbavuje ručně…") under the frequency section; add the same style note when bank+monthly: reuse existing copy.
- Schedule preview (`:355-409`): remove the UNLIMITED open-ended branch; branches left: YEARLY fixed, one-shot (<31d), fixed-end monthly.
- Ceník panel: yearly row badge becomes `Zvýhodněná cena −10 %` (same green badge, static text per user decision); explainer paragraph `:631-635` updated (no "doba neurčitá" mention anywhere).
- Sidebar/legal blocks unchanged (button label `OBJEDNÁVÁM a zaplatím` on `/prijmout` untouched — compliance).

### 5. `duration_preset_controller.js`

`PRESETS = [3, 6, 12]` (`:3`). No other logic change (clamping/`maxMonths=0` already supported). Applies to both forms since the template renders the buttons.

### 6. Admin onboarding form

Mirror req 3/4 on `AdminOnboardingFormData` / `AdminOnboardingFormType` / `AdminOnboardingForm` / `AdminOnboardingForm.html.twig`:
- Remove `rentalType` + `expectedDuration` fields/validation; endDate required always (`validateDates` keeps 7-day floor, still **no upper cap** for admins); `validatePaidThroughDate` drops the LIMITED branch condition (applies always: `paidThroughDate ≤ endDate`).
- Remove the `billingMode` field; derive via `BillingMode::derive()` in a callback (EXTERNAL → MANUAL_RECURRING). Keep `validateYearlyHasNoCustomPrice`, external/debt rules, free/prepaid EXTERNAL coercion in `AdminOnboardingHandler` untouched.
- New rules: GOPAY + `days < 31` → same violation as public; GOPAY + YEARLY → violation `'Roční platbu lze platit pouze bankovním převodem.'` (EXTERNAL + YEARLY stays allowed).
- Template: drop section 3 (Typ pronájmu) + expectedDuration; presets `[3,6,12]` (keep `max-months-value="0"`); frequency card keeps yearly/bank notes, loses the billingMode row; add the same −10 % badge on yearly.
- `getStoragesJson()` hardcoded `'isUnlimited' => false` — rename key per req 8.

### 7. Availability: open-ended block for AUTO_RECURRING

- `OrderRepository::findOverlappingByStorage()` `:250-282` + bulk twin `:295-323`: replace the end-overlap condition with
  `(o.billingMode = :autoRecurring OR o.endDate IS NULL OR o.endDate >= :startDate)` (keep `o.startDate <= :endDate` for finite requests, keep blocking-status filter, keep the NULL-request-end branch shape). `o.endDate IS NULL` stays for legacy rows.
- `ContractRepository::findOverlappingByStorage()` `:349-380` + bulk twin `:198-223`: replace end condition with
  `((c.billingMode = :autoRecurring AND c.goPayParentPaymentId IS NOT NULL AND c.terminatesAt IS NULL) OR c.endDate >= :startDate)` — i.e. a live-token card contract without a pending termination blocks every future window; a cancelled-token or notice-terminated card contract, and every bank/external contract, blocks only to its (effective) end. Use `COALESCE(c.terminatesAt, c.endDate) >= :startDate` for the finite part so pending terminations free the tail (matches `getEffectiveEndDate()`).
- `StorageAvailabilityChecker` needs no logic change (it consumes the repos), but update its docblock describing the three predicates. Add integration tests: card order blocks a window starting years later; bank contract does not block after endDate; card contract with cancelled recurring does not block after endDate.
- Side effect (intended, document in code comment): landlords cannot create a `StorageUnavailability` overlapping ANY future window of a live card contract (`CreateStorageUnavailabilityHandler` uses the same predicates).

### 8. Occupancy/rental-view surfaces

- `RentalSpan::isUnlimited()` and `StorageRentalView::isUnlimited` → replace with `hasAvailabilityGuarantee` derived from Contract: `BillingMode::AUTO_RECURRING === billingMode && null !== goPayParentPaymentId && !isTerminated()` (Order source: `AUTO_RECURRING === billingMode`). Add helper `Contract::hasAvailabilityGuarantee(): bool`.
- `PlaceOccupancyMap.php:132` payload key `isUnlimited` → `hasGuarantee` (same derivation); `storage_map_controller.js:605,690` "Pronajato: neomezeně" → `Garance dostupnosti (platba kartou)` rendered when `hasGuarantee`.
- The "↻ / automat. / Automatické prodlužování" markers in the six templates listed in Context: replace with a `↻`-free guarantee marker only where the view exposes it (tooltip `Garance dostupnosti — platba kartou`); where the marker only signaled "auto-extends" (dashboard_user `(automat.)`, gantt tooltips), delete the branch — the plain `do {date}` display is now always correct.

### 9. Hard-stop lifecycle (reverses spec 058 auto-extension)

- `ChargeRecurringPaymentHandler` `:185` and `ProcessPaymentNotificationHandler` `:199,:280`: remove the `!$contract->isUnlimited()` escapes — every recurring contract stops billing once `nextBillingDate >= effectiveEndDate` (nextBillingDate → null; last charge prorated as today).
- `ContractRepository::findDueForTermination()` `:509-527`: widen case 3 so token-holding card contracts terminate at end:
  `(c.endDate <= :now AND c.billingMode = :autoRecurring AND c.failedBillingAttempts = 0 AND c.pendingRecurringPaymentId IS NULL)` (drop `goPayParentPaymentId IS NULL`). `ContractService::terminateContract()` already voids a live token — verify the void happens for this path and the audit `logContractTerminated` fires. Contracts mid-retry keep being handled by `app:retry-failed-payments` (unchanged).
- `ContractService::findContractsExpiringOnDay()` `:136-164`: delete the auto-extend skip block — every non-terminated contract whose endDate hits the 30/7/1 offsets gets a reminder; keep skipping `hasPendingTermination()` contracts is NOT desired — they are genuinely ending, keep sending (current code sends them; preserve). Update the `'will auto-extend'` comment in `ContractService.php:152`.
- `Contract::isInBillingGrace()`: keep (still correct for the retry window past endDate before cron reaps).
- `SendContractExpiringReminderHandler.php:66` — drop `isLimited`; `email/contract_expiring.html.twig:168-171` renders the renewal CTA unconditionally (spec 077 will repoint it from `generateRenewal` to the prolongation URL; here keep the existing renew link).

### 10. GoPay: card is recurring-only

- `InitiatePaymentHandler.php:34-45`: `match` becomes `AUTO_RECURRING => createRecurringPayment(...)`, `default => throw new \LogicException('Card payments are recurring-only; non-recurring orders are paid by bank transfer.')`. (Bank orders never call this endpoint — `OrderPaymentController` renders QR without GoPay JS. Verify `order_payment.html.twig`/`OrderDebtPaymentController` still only expose the card JS for card orders.)
- `DispatchManualBillingNotificationHandler.php`: delete the GoPay branch (`:138-168`) and `isGoPayPaymentTerminal()`; the handler always follows the bank-transfer path (records stage, VS, dispatches event). Guard: if the contract's order has no `variableSymbol`, generate+assign one via `VariableSymbolGenerator` before dispatching (belt-and-braces after the data migration).
- `SendManualBillingPaymentRequestedEmailHandler.php:89` + `SendManualBillingPaymentOverdueEmailHandler.php:85`: remove `gatewayUrl` (always bank data + QR). Update both email templates accordingly.
- `ManualPaymentRequest`: keep `goPayPaymentId`/`goPayGatewayUrl` columns (historical rows) but nothing writes them anymore; `ProcessPaymentNotificationHandler` manual-cycle branch (`:133-138`) stays (legacy in-flight links may still complete right after deploy).
- Copy updates: `/prijmout` MANUAL info card (`order_accept.html.twig:358-371`) and `customer_signing.html.twig` `showManualInfo` card — replace "e-mail s odkazem k zaplacení" wording with bank-transfer wording: `Před každou další platbou Vám 7 dní předem pošleme e-mail s platebními údaji a QR kódem pro bankovní převod.`
- Fines (`InitiateFinePaymentHandler`) and onboarding debt (`InitiateDebtPaymentHandler`) keep one-shot GoPay — explicitly out of scope.

### 11. Remaining display/plumbing cleanup

- Order detail templates (admin/landlord/user) + `order_status.html.twig` + `customer_signing.html.twig:81`: remove rentalType/expectedDuration rows and "(prodlužuje se automaticky)" suffixes; "Typ pronájmu" row → show billing label instead: `order.billingMode.label()` (already Czech).
- `portal/user/order/detail.html.twig:54-56` renew gate: `canRenew = status in ['paid','completed'] and order.endDate is not null` (drop rentalType test).
- Excel exports: drop the "Délka pronájmu" column (both controllers).
- `AuditLogger.php:48,156`: drop `rental_type` payload keys.
- `ContractDocumentGenerator`: remove the unused `rentalType` params (`:54,:99,:114`); `formatRentalDuration()` already keys on endDate.
- `OrderRenewController.php:92-105`: delete the UNLIMITED branch; always prefill `startDate = newStart`, `endDate = newStart + previousDays` (previousDays fallback 30 stays). Drop the `rentalType` prefill.
- `PriceCalculator`: `isEligibleForYearly(?\DateTimeImmutable $start, ?\DateTimeImmutable $end)` — drop the RentalType param (UNLIMITED escape gone); `needsRecurringBilling`, null-end schedule branches, `calculatePrice(…, null)` **stay** (legacy orders + place-detail rate display rely on them).
- `_price_aggregate.html.twig` untouched (already keyed on `PaymentSchedule.isOpenEnded`).

### 12. Migrations (two files)

1. **Schema** (generated via `docker compose exec web bin/console make:migration` AFTER entity edits): drops `orders.rental_type`, `orders.expected_duration`, `contract.rental_type`; sets `contract.end_date` NOT NULL. Never handwrite this one.
2. **Data** (handwritten, separate `Version…` file, PHP-level like `Version20260527130100.php`, NO DDL) — runs BEFORE the schema one chronologically is not required; order them data-first so `end_date` NOT NULL succeeds if any stray NULL exists (defensive `UPDATE contract SET end_date = start_date + interval '1 month' WHERE end_date IS NULL`):
   - Flip legacy card-manual contracts to bank: for every order `o` joined to contract `c` with `c.billing_mode = 'manual_recurring'` and `o.payment_method = 'gopay'` → `o.payment_method = 'bank_transfer'`.
   - Flip **unpaid** non-auto card orders (statuses `created`,`reserved`,`awaiting_payment`, `billing_mode != 'auto_recurring'`, `payment_method = 'gopay'`) → `bank_transfer`.
   - Assign `variable_symbol` where NULL for every order now on `bank_transfer` **and** every order backing a `manual_recurring` contract (iterate rows in PHP, reuse the exact CRC32 algorithm of `App\Service\Payment\VariableSymbolGenerator` inline; keep uniqueness by re-rolling on collision the same way the service does).
   - Ex-UNLIMITED contracts need no data change (endDate already = paid-through; freezing it IS the migration — auto-extension code is gone). Their customers will start receiving expiration reminders; operator communication is out of code scope (flag in PR description).

### 13. Fixtures + FIXTURES.md

- `OrderFixtures`/`ContractFixtures`: convert both UNLIMITED pairs to fixed-term AUTO_RECURRING with a live token (e.g. 12-month span around the MockClock date) — rename refs `REF_ORDER_COMPLETED_UNLIMITED` → `REF_ORDER_COMPLETED_RECURRING`, `REF_CONTRACT_UNLIMITED` → `REF_CONTRACT_RECURRING` (grep-rename across tests + `InvoiceFixtures::REF_INVOICE_UNLIMITED` → `REF_INVOICE_RECURRING`). `OnboardingFixtures` `:149,:172` get concrete endDates. Remove all `rentalType:`/`expectedDuration:` args. Update `.claude/FIXTURES.md` rows.
- One fixture must exercise the new open-ended block (card contract with token) so availability tests have data.

### 14. COMPLIANCE.md update (same commit)

Rewrite the "Billing modes" table + hard rules (lines 48-63): three shapes become — One-shot bank (<31 dní, převod only), Recurring fixed-end card (AUTO, monthly, any length ≥31 dní, poslední splátka poměrná, hard stop at endDate), Manual bank (monthly/yearly výzvy s QR). Delete the 1-rok cap rule; delete "Recurring open-ended"; update the yearly section (lines 88-96): yearly je **pouze bankovním převodem** — no GoPay one-shot link, no card; remove the per-cycle "fresh one-shot GoPay link" sentence (line 95). Note the two new availability-guarantee texts as fixed wording. Leave button-label/consent/logo rules untouched.

### 15. Tests

- New: `BillingModeDeriveTest` (full matrix), availability open-ended block tests (req 7), termination-of-token-holding-contract test (req 9), form validation tests (card <31d, yearly+card, endDate required, no ceiling — e.g. 3-year rental passes).
- Rewrite/delete per discovery: `OrderFormDataTest` (unlimited/expectedDuration cases go), `OrderFormDataBillingModeValidationTest` (matrix re-targets `derive()`), `OrderFormDataPaymentFrequencyTest`, `AdminOnboardingFormDataTest` (`validData()` gets endDate), `AdminOnboardingHandlerTest`, `OrderWorkflowTest` (unlimited cases), `OrderRenewControllerTest` (rentalType assertions), `OrderFormTest` (`limitedWindowValues` → plain window), `OrderBillingModeTest` helper, `ControllerAccessTest::createOrder()` helper, plus ~60 mechanical constructor-arg removals (Tier 2 list in discovery).
- `composer quality` AND full `composer test` must pass (controller/template changes ⇒ integration tests run).

## Acceptance

- [ ] `grep -r "RentalType\|ExpectedDuration\|rentalType\|expectedDuration" src/ templates/ assets/ fixtures/` returns nothing (except migration history).
- [ ] Public order form: no rental-type radio, endDate required, presets 3/6/12 ("1 rok"), end datepicker unbounded, 3-year order passes validation.
- [ ] Card selected with a 20-day window → inline violation + disabled radio hint; bank+20 days → ONE_TIME order with VS + QR page.
- [ ] Card + 6-month window → order locks AUTO_RECURRING/MONTHLY without any billing-mode radio; `/prijmout` shows recurring consent card exactly as before.
- [ ] Yearly selectable only ≥360 days; yearly+card → violation; yearly+bank → MANUAL_RECURRING; −10 % badge visible on the frequency option and Ceník row.
- [ ] "Způsob platby" heading is followed by the green guarantee sentence; selecting bank shows surcharge + "Negarantujeme dostupnost…" note.
- [ ] Availability: with a live card contract on storage X, `StorageAvailabilityChecker::isAvailable(X, endDate+2y, endDate+2y+1m)` is false; same query on a bank contract is true.
- [ ] `app:process-contract-terminations` terminates a card contract past endDate with a live token and voids the token (assert `voidRecurrence` called in integration test with mock client).
- [ ] Recurring charge on the last cycle sets `nextBillingDate = null` for card contracts (no auto-extension anywhere).
- [ ] Manual-billing cycle email contains bank details + QR and NO GoPay link, for a contract that was GOPAY+MANUAL before migration (data migration flipped it and assigned VS).
- [ ] `doctrine:schema:validate` clean; migrations-up-to-date CI job green; `contract.end_date` NOT NULL.
- [ ] `.claude/COMPLIANCE.md` + `.claude/FIXTURES.md` updated in the same commit.
- [ ] `composer quality` green and full `composer test` green.

## Out of scope

- **Prolongation flow** — spec 077 (depends on this spec).
- Fines + onboarding-debt one-shot GoPay payments — different legal surface, unchanged.
- Early customer cancellation of recurring mid-term (contract currently runs unpaid to endDate) — pre-existing behavior, tracked by draft spec 019.
- Legal document wording (VOP DOCX, on-page contract čl. IV auto-prolongation prose) — user explicitly confirmed no changes needed.
- `Storage.status` column demotion leftovers (spec 071 out-of-scope list) — unchanged.
- Customer communication campaign for migrated ex-UNLIMITED / ex-card-manual contracts — operational, not code.
- Repricing yearly rates to exactly −10 % — badge is static text by explicit user decision; a computed variant may come later.

## Open questions

None — proceed.
