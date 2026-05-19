# 043 — Onboarding journey, end-to-end clarity: situation-aware emails, signing page, completion page, contract attachment, reminders

**Status:** done
**Type:** UX + bug-fix bundle + cron
**Scope:** large (~26 files: 3 value objects + 1 form validator + 1 controller + 4 templates (signing page, signing complete, signing_link.html.twig + .txt.twig, rental_activated.html.twig + .txt.twig, new onboarding_payment_reminder.html.twig + .txt.twig) + 2 email handlers + 1 attachments service + 1 contract service + 1 new entity + 1 new repository + 1 new repo method + 1 new cron + 1 new event + 1 new event handler + migration + fixtures + tests + PROJECT_MAP/BACKLOG update)
**Depends on:** spec 025 (`Contract.individualMonthlyAmount`, `Order.paidThroughDate`), spec 020 (signed status URLs via `OrderStatusUrlGenerator`), spec 030 (`customer_billing_status.html.twig`), spec 036 (`ManualPaymentRequest`-style idempotency pattern), spec 041 (passwordless-safe customer URLs)

## Problem

QA reports concentrate on **one root issue**: the onboarding flow doesn't speak a coherent language about *what the customer has paid for, and what remains to do*. Every customer-facing surface ignores the four billing situations the admin can configure and treats every onboarding as if the customer is about to pay through GoPay right now. The result is a stream of contradictory, confusing messages within minutes.

Concrete bugs:

1. **"Pri onboardingu se zobrazuje nutnost platby i pri zaplaceni najmu k datu po 5-ti denním limitu k zaplaceni."** A customer onboarded as externally prepaid through `2026-12-31` lands on the signing page and sees `Měsíční platba: 1 500 Kč / měsíc` — read straight from the storage rate, ignoring both `Order.firstPaymentPrice` (the locked-in monthly that may differ) and `Order.paidThroughDate`. Then they receive a "Potvrzení objednávky" e-mail that says "rezervace platná do {expiresAt}, pokud nezaplatíte" — but they've already paid. The `expiresAt` value is the onboarding's 30-day window from `AdminCreateOnboardingHandler::ONBOARDING_EXPIRATION_DAYS`; for places with a 5-day default it can be even shorter and even more frightening to a customer who isn't supposed to pay anything.

2. **"V jednom případě neprisly zadne pokyny k platbe, v druhem pripade prislo rovnou dekujeme za platbu (bez dalsího kroku nebo provedeni platby ale system hlasi smlouvu po splatnosti)."** Two separate symptoms:
   - GoPay-onboarded customer signs, gets redirected to `/objednavka/{id}/platba`, closes the tab. Receives the standard "Potvrzení objednávky" e-mail (status-URL button, no recurring nudge), then silence. Order expires at +30 days. From the customer's perspective: *I got a signing link, signed, then nothing.*
   - Externally-prepaid / free customer signs, the EXTERNAL branch in `CustomerSignOnboardingHandler` auto-completes (`confirmPayment` + `CompleteOrderCommand`) in the same transaction, and the customer immediately receives `rental_activated.html.twig` with the headline **"Vaše platba byla úspěšně zpracována — Děkujeme za Vaši platbu"** even though they paid nothing through us. Worse, when `paidThroughDate` is `< now-1d`, `OverdueChecker` correctly (per spec 025) flags the contract as overdue — so the customer just got a "thanks for paying" e-mail and admin sees them as po splatnosti.

3. **"Pri onboardingu neni soucasti emailu nahrana smlouva."** `OrderEmailAttachmentsService::attachLegalDocuments()` only attaches a contract PDF when `$order->hasSignature()` is true. The migrate flow attaches the uploaded paper PDF to `Contract::documentPath` (`AdminMigrateCustomerHandler.php:97`) but never sets `$order->signaturePath` — so migrate's own legal pack ships **without** the actual signed paper contract. The customer gets VOP + poučení + podmínky but not the contract they care about.

Plus a structural reframing from the user: **external payment IS prepayment**. There's no such thing as "external payment without a paid-through date" — the admin only has admin-knowledge that the customer paid because *something* (cash, bank transfer) reached them, *covering some period*. The current form lets admin tick `paymentMethod = EXTERNAL` while leaving `isExternallyPrepaid` unchecked and `paidThroughDate` blank, which produces an order with no recurring schedule, no prepayment, and no GoPay token — a contract in limbo. Today this is just a footgun; from the customer's perspective the whole onboarding becomes incomprehensible.

## Goal

Every customer-facing surface tells the same story, situation by situation, with **zero contradictions between consecutive touchpoints**. The four billing situations the admin can pick — *GoPay first charge / externally prepaid / free / migrate (= externally prepaid via paper)* — get unambiguous wording at:

1. **Signing-link e-mail** — subject + body + button label reflect the situation. The customer knows before they click whether they'll be asked to pay.
2. **Signing page** (`/podpis/{token}`) — price block replaced by a calm green banner for prepaid/free; for GoPay flows the price is read from `Order.firstPaymentPrice` (the locked-in monthly), never recomputed from storage rate. **No `Měsíční platba: X Kč` text ever appears for a customer who isn't actually expected to pay.**
3. **Signing completion page** (`/podpis/dokonceno/{id}`) — situation-aware confirmation, with a status-URL CTA (signed) instead of the dead-end "Přihlásit se" button (admin-onboarded customers are passwordless per spec 041 and can't log in).
4. **"Potvrzení objednávky" e-mail** — suppressed entirely when the onboarding already completed in the same transaction (migrate, prepaid/free digital). For GoPay onboardings that legitimately stay in RESERVED, it stays.
5. **Onboarding-payment reminder cron** — daily, D+2 / D+5 after signing, only for GoPay-signed-but-unpaid orders. Idempotent.
6. **"Pronájem zahájen" e-mail** — three branches (`GoPay charged` / `Externally prepaid` / `Free`) with situation-matching subject + headline + body. "Děkujeme za Vaši platbu" only appears when actual money changed hands through us.
7. **Migrate flow** — receives only the rental-activated e-mail (no duplicate order-placed); the uploaded paper PDF is attached.
8. **Admin form** — `paymentMethod = EXTERNAL` requires either `paidThroughDate` (when monthlyPriceMode ≠ free) or `monthlyPriceMode = free`. Removes the limbo state.

Plus a loose-end consistency fix: `ContractService::calculateOutstandingDebt` reads from `Contract::getEffectiveMonthlyAmount()` instead of `Contract::$storage->getEffectivePricePerMonth()` — the exact drift spec 025 fixed on the hot recurring-charge path, applied to the colder termination-debt path.

## Customer billing situations (the spine of this spec)

Three situations cover every customer e-mail / page from this spec. (`Migrate` is a sub-case of `EXTERNALLY_PREPAID`, distinguished only by whether the contract PDF is paper-uploaded or digitally generated — invisible at the wording level.)

| Code | Trigger | First charge | Recurring charge | Subject line tone |
|---|---|---|---|---|
| `GOPAY_FIRST_CHARGE` | `Order.paymentMethod = GOPAY` and (digital flow only) | Customer pays via GoPay now | AUTO_RECURRING token or MANUAL_RECURRING monthly link | "zaplatíte" / "Děkujeme za Vaši platbu" |
| `EXTERNALLY_PREPAID` | `Order.paidThroughDate IS NOT NULL` | Admin recorded externally | None until `paidThroughDate` passes; cron asks customer 7 days before (spec 025) | "předplaceno do {date}" |
| `FREE` | `Order.individualMonthlyAmount = 0` | None | None | "bezplatný pronájem" |

Detection ordering (free wins over prepaid, prepaid wins over GoPay — mirrors `customer_billing_status.html.twig` from spec 030 exactly):

```
isFree  → FREE
elseif paidThroughDate  → EXTERNALLY_PREPAID
else  → GOPAY_FIRST_CHARGE
```

Used by `CustomerBillingSituation::fromOrder(Order)` (req. 1) and `::fromContract(Contract)` (same enum, picking from contract-level fields).

## End-state customer journey (what the customer experiences after this spec)

```
                          ┌─────────────────────────────────────────────────┐
                          │  Situation 2 — GoPay (digital admin onboarding) │
                          └─────────────────────────────────────────────────┘
  ┌────────────────┐    ┌────────────────┐    ┌────────────────┐    ┌──────────────┐
  │ signing_link   │ →  │ /podpis/{tok}  │ →  │ /objednavka/   │ →  │ rental_      │
  │ "Podepište a   │    │ shows price    │    │ {id}/platba    │    │ activated    │
  │ zaplaťte"      │    │ (Order.first…) │    │ + GoPay        │    │ "Vaše platba │
  │ + order summary│    │ + Submit→Pay   │    │                │    │ zpracována"  │
  └────────────────┘    └────────────────┘    └────────────────┘    └──────────────┘
                                  │  abandon                ↑
                                  ▼                         │
                          ┌────────────────┐                │
                          │ order_placed   │                │
                          │ (kept, with    │                │
                          │ statusUrl→ Pay)│                │
                          └────────────────┘                │
                                  │                         │
                          ┌────────────────┐                │
                          │ D+2/D+5 cron   │   ─────────────┘
                          │ reminder       │
                          │ "Stále čeká"   │
                          └────────────────┘

                          ┌─────────────────────────────────────────────────────┐
                          │  Situation 3 — Externally prepaid (digital onboard) │
                          └─────────────────────────────────────────────────────┘
  ┌────────────────────┐    ┌────────────────────┐    ┌────────────────────┐    ┌──────────────────────┐
  │ signing_link       │ →  │ /podpis/{tok}      │ →  │ /podpis/dokonceno  │    │ rental_activated     │
  │ "Podepište —       │    │ GREEN banner       │    │ /{id}              │    │ "Pronájem zahájen —  │
  │ předplaceno        │    │ "Předplaceno do    │    │ "Vše vyřízeno —    │    │ předplaceno do {d}"  │
  │ do {date}"         │    │ {date} — nemusíte  │    │ předplaceno do {d}"│    │ + signed contract    │
  │ + order summary    │    │ nic platit"        │    │ + statusUrl CTA    │    │ + paper if migrate   │
  └────────────────────┘    └────────────────────┘    └────────────────────┘    └──────────────────────┘
                                                            ▲
                          (order_placed SUPPRESSED — admin-created + COMPLETED at handler-run time)

                          ┌────────────────────────────────────┐
                          │  Situation 4 — Free                │
                          └────────────────────────────────────┘
  Same as situation 3 with wording: "bezplatný pronájem" / "Pronájem zdarma — nemusíte nic platit"

                          ┌────────────────────────────────────────────────┐
                          │  Situation 5 — Migrate (paper, no signing UI)  │
                          └────────────────────────────────────────────────┘
  (no signing_link, no signing page, no completion page)
  ┌──────────────────────────────────────────────────┐
  │ rental_activated                                 │
  │ "Pronájem zahájen — předplaceno do {paidThrough}"│
  │ + paper PDF attached (from Contract.documentPath)│
  │ (order_placed SUPPRESSED)                        │
  └──────────────────────────────────────────────────┘
```

## Context (current state)

### Bug 1 — signing page reads storage rate, not Order

`src/Controller/Public/CustomerSigningController.php` (single-action, route `/podpis/{token}`) recomputes the price from storage in three places:

- `:50-54` (initial GET render)
- `:110-114` (POST validation-error re-render)
- `:152-156` (POST exception fallback)

Each call: `$firstPaymentPrice = $this->priceCalculator->calculateFirstPaymentPrice($order->storage, $order->startDate, $order->endDate);`. The order **already carries the locked-in monthly** at `Order.firstPaymentPrice` (set by `OrderService::createOrder()` with the admin's `monthlyPriceOverride`, `src/Service/OrderService.php:84`). It also carries `Order.paidThroughDate` and `Order.individualMonthlyAmount` (set by `Order::setOnboardingBillingTerms` in both onboarding handlers — `AdminCreateOnboardingHandler.php:80-84`, `AdminMigrateCustomerHandler.php:76-80`).

Template `templates/public/customer_signing.html.twig:66-70` is the price-display block — no situational branching today, every customer sees the same `Měsíční platba: X Kč / měsíc` line.

### Bug 2 — `rental_activated` headline is unconditional

`templates/email/rental_activated.html.twig:89-93`:

```twig
<div class="success-message">
    <strong>Vaše platba byla úspěšně zpracována — pronájem skladu je aktivní.</strong>
</div>
<p>Děkujeme za Vaši platbu. V příloze tohoto e-mailu najdete kompletní sadu dokumentů …</p>
```

No branch for "external prepayment" or "free". `SendRentalActivatedEmailHandler` already detects `isRecurring = $contract->hasActiveRecurringPayment()` (which is correctly `false` for prepaid contracts because they have no GoPay token) so the "Potvrzení opakované platby" inner block is hidden — but the headline line and the "Děkujeme za platbu" paragraph are hard-coded.

Subject line at `SendRentalActivatedEmailHandler.php:92`: `'Pronájem zahájen - '.$place->name` — also unconditional.

### Bug 3 — migrate paper contract not attached

`src/Service/OrderEmailAttachmentsService.php:37-56`:

```php
if ($order->hasSignature()) {
    $contractBytes = $this->contractGenerator->renderBytesForOrder(...);
    ...
}
```

For migrate, `Order::$signaturePath` is never set — only `Contract::$documentPath` (the uploaded paper PDF at `%kernel.project_dir%/var/contracts/contract_{rfc4122}.{ext}`). Both `SendOrderPlacedEmailHandler` and `SendRentalActivatedEmailHandler` call `$this->attachments->attachLegalDocuments($email, $order);`, so the customer gets the legal pack **without** their actual signed contract.

Dispatch order in migrate's single transaction: `OrderPlaced` → `OrderPaid` → `OrderCompleted`. All three queue during the handler; `DispatchDomainEventsMiddleware` releases them post-commit. By the time any handler runs, `Contract` has already been signed and `documentPath` populated — we can safely look up the contract from the order at handler time.

### Duplicate "Potvrzení objednávky" for one-transaction onboardings

In `AdminMigrateCustomerHandler::__invoke()`:

```php
$order->reserve($now);                                  // → OrderPlaced
$this->orderService->confirmPayment($order, $command->paidAt, $command->totalPrice);   // → OrderPaid
$contract = $this->orderService->completeOrder($order, $now);   // → OrderCompleted
```

In `CustomerSignOnboardingHandler::__invoke()` when `PaymentMethod::EXTERNAL`:

```php
$order->reserve($now);                                  // → OrderPlaced
$this->orderService->confirmPayment($order, $now);      // → OrderPaid
$this->commandBus->dispatch(new CompleteOrderCommand(order: $order));   // → OrderCompleted
```

All three events queue together; by the time `SendOrderPlacedEmailHandler` reloads the order from the repository, `$order->status === OrderStatus::COMPLETED`. In the normal customer flow they fire across **separate** transactions (reserve → payment-init → webhook → complete), so OrderPlaced sees `RESERVED` and the e-mail is genuinely useful (status URL with pay button).

Discriminator: `$order->status === OrderStatus::COMPLETED && $order->isAdminCreated === true`.

### Bug 4 — GoPay onboarding stalls have no follow-up

`AdminCreateOnboardingCommand::paymentMethod === GOPAY` → `CustomerSignOnboardingHandler` leaves the order in `RESERVED` after signing and redirects the customer to `/objednavka/{id}/platba`. If they close the tab, only the order_placed e-mail ever lands (status-URL button, no urgency framing). Order silently expires at `now + 30 days` via `app:expire-orders`.

Mirror crontab pattern: spec 036's `app:send-manual-billing-payment-requests` + the `ManualPaymentRequest.sentStages` idempotency. Same shape: one entity row per (order, reminder-stage) with `sentAt` so a re-run / parallel cron / mid-loop crash never double-sends.

### Bug 5 — completion page dead-ends passwordless customers

`templates/public/customer_signing_complete.html.twig` says "Nyní se můžete přihlásit do svého účtu" with a button to `/login`. Per spec 041, admin-onboarded customers don't have a password (created by `GetOrCreateUserByEmailHandler`) — `/login` is a dead end for them. Every customer CTA in this spec must use signed URLs (`OrderStatusUrlGenerator::generate`).

### Bug 6 — admin form lets EXTERNAL exist without a paid-through date

`AdminCreateOnboardingFormData`:
- `$paymentMethod` defaults to `PaymentMethod::EXTERNAL`.
- `$isExternallyPrepaid` defaults to `false`.
- `$paidThroughDate` defaults to `null`.
- `validatePaidThroughDate()` only fires when `isExternallyPrepaid === true`.

So admin can submit: `paymentMethod = EXTERNAL`, `isExternallyPrepaid = false`, `paidThroughDate = null`, `monthlyPriceMode = standard`. The downstream handler creates a contract with no recurring schedule, no prepayment, no GoPay token. From the customer's perspective: indecipherable.

### Bug 7 — `ContractService::calculateOutstandingDebt` storage drift

`src/Service/ContractService.php:106`: `$monthlyRate = $contract->storage->getEffectivePricePerMonth();`. Identical to the recurring-charge bug spec 025 fixed; here it applies to mid-cycle termination debt. Cold path but the same principle.

### Existing infrastructure we reuse

- `Order.individualMonthlyAmount`, `Order.paidThroughDate`, `Order.isAdminCreated`, `Order.createdByAdmin`, `Order.signedAt` — spec 025 / spec 032 fields, all written at onboarding-creation time.
- `Contract.individualMonthlyAmount`, `Contract.paidThroughDate`, `Contract.getEffectiveMonthlyAmount()`, `Contract.isFree()`, `Contract.daysUntilExternalPrepaymentEnds()` — spec 025 / spec 030 plumbing.
- `OrderStatusUrlGenerator::generate(Order)` — UriSigner-signed status URL; spec 020.
- `OrderStatusViewModelFactory.php:58-65` — already exposes `payNowUrl` for orders in RESERVED/AWAITING_PAYMENT/CREATED, pointing at `public_order_payment`. The status page (`templates/public/order_status.html.twig`) already renders a "Zaplatit nyní" CTA when present. The reminder cron just needs to point customers back at that signed status URL.
- `customer_billing_status.html.twig` (spec 030) — already renders the per-situation banner on portal order detail + public status page; we reuse the *detection logic* (free wins over prepaid) but the partial itself doesn't need touching.
- `ManualBillingReminderSchedule::fromOrder(Order)`, `ManualPaymentRequest` entity — spec 036's idempotency pattern to mirror.

## Architecture

```
                ┌───────────────────────────────────────────────────────────────┐
                │  Central abstraction: CustomerBillingSituation                │
                │  (enum-like value object — used by every customer surface)    │
                │                                                               │
                │    GOPAY_FIRST_CHARGE  |  EXTERNALLY_PREPAID  |  FREE         │
                │                                                               │
                │  ::fromOrder(Order)     — used pre-contract creation          │
                │  ::fromContract(Contract) — used post-contract creation       │
                └────────────────────┬──────────────────────────────────────────┘
                                     │
       ┌─────────────────────────────┼─────────────────────────────┬───────────────────────────┐
       ▼                             ▼                             ▼                           ▼
┌──────────────┐          ┌──────────────────┐         ┌──────────────────┐        ┌────────────────────┐
│SigningPrice  │          │SigningEmailContent│        │CompletionPage    │        │RentalActivated      │
│ViewModel     │          │(subject + body +  │        │ViewModel         │        │EmailContent         │
│(signing pg)  │          │ CTA label)        │        │(signing complete)│        │(rental_activated)   │
└──────────────┘          └──────────────────┘         └──────────────────┘        └────────────────────┘
       │                          │                            │                            │
       ▼                          ▼                            ▼                            ▼
templates/public/      templates/email/             templates/public/             templates/email/
customer_signing.      signing_link.html.twig       customer_signing_            rental_activated.
html.twig              + .txt.twig                  complete.html.twig            html.twig + .txt.twig


                ┌──────────────────────────────────────────────────────────────┐
                │  Suppress duplicate order_placed                             │
                │                                                              │
                │  SendOrderPlacedEmailHandler                                 │
                │    early-return when isAdminCreated && status===COMPLETED    │
                └──────────────────────────────────────────────────────────────┘


                ┌──────────────────────────────────────────────────────────────┐
                │  Paper contract attached for migrate                         │
                │                                                              │
                │  OrderEmailAttachmentsService                                │
                │    1. if Order.hasSignature → render digital contract (today)│
                │    2. else if Contract.hasDocument → attach paper PDF (NEW)  │
                └──────────────────────────────────────────────────────────────┘


                ┌──────────────────────────────────────────────────────────────┐
                │  D+2 / D+5 reminders for signed-but-unpaid GoPay onboardings │
                │                                                              │
                │  Daily cron: SendOnboardingPaymentRemindersCommand           │
                │    → OrderRepository::findUnpaidSignedOnboarding($now)       │
                │    → OnboardingReminderSchedule::stageDueOn(now, signedAt)   │
                │    → DispatchOnboardingReminderCommand                       │
                │  Handler: pessimistic-lock against OnboardingReminderSent    │
                │    → record FIRST, then dispatch event                       │
                │  Event handler: SendOnboardingPaymentReminderEmailHandler    │
                │    → email with statusUrl (payNowUrl on the status page)     │
                └──────────────────────────────────────────────────────────────┘


                ┌──────────────────────────────────────────────────────────────┐
                │  Form: EXTERNAL implies prepaid (or free)                    │
                │                                                              │
                │  AdminCreateOnboardingFormData::validateExternalIsPrepaid    │
                │    if paymentMethod === EXTERNAL && monthlyPriceMode !== free│
                │    then paidThroughDate required (+ isExternallyPrepaid)     │
                └──────────────────────────────────────────────────────────────┘
```

## Requirements

Sections 1-5 introduce the central abstraction and the four content value objects. Sections 6-9 are the customer-touchpoint rewrites that consume them. Sections 10-15 cover the reminder cron (entity + repo + cron + command + event + handler). Sections 16-17 are the form fix + outstanding-debt fix. Sections 18-19 are tests + project-map.

### 1. New value object: `App\Service\Order\CustomerBillingSituation`

`src/Service/Order/CustomerBillingSituation.php` — the spine. Three constants, two factory methods. PHP enum (not a `final readonly class`) because it has only-cases-no-state semantics:

```php
enum CustomerBillingSituation: string
{
    case GOPAY_FIRST_CHARGE  = 'gopay_first_charge';
    case EXTERNALLY_PREPAID  = 'externally_prepaid';
    case FREE                = 'free';

    public static function fromOrder(Order $order): self
    {
        if (0 === $order->individualMonthlyAmount) {
            return self::FREE;
        }
        if (null !== $order->paidThroughDate) {
            return self::EXTERNALLY_PREPAID;
        }
        return self::GOPAY_FIRST_CHARGE;
    }

    public static function fromContract(Contract $contract): self
    {
        if ($contract->isFree()) {
            return self::FREE;
        }
        if (null !== $contract->paidThroughDate && null === $contract->goPayParentPaymentId) {
            return self::EXTERNALLY_PREPAID;
        }
        return self::GOPAY_FIRST_CHARGE;
    }
}
```

Detection ordering mirrors `customer_billing_status.html.twig` exactly. A unit test pins both factories across all branches (`tests/Unit/Service/Order/CustomerBillingSituationTest.php`).

**Note**: `EnumType::class` is *not* used for this in any form; it's purely an internal classification.

### 2. New value object: `App\Service\Order\SigningPriceViewModel`

`src/Service/Order/SigningPriceViewModel.php` — feeds `customer_signing.html.twig` and the controller's three render paths:

```php
final readonly class SigningPriceViewModel
{
    public function __construct(
        public CustomerBillingSituation $situation,
        public int $monthlyPriceInHaler,
        public bool $isRecurring,
        public ?\DateTimeImmutable $paidThroughDate,
    ) {}

    public static function fromOrder(Order $order): self
    {
        return new self(
            situation: CustomerBillingSituation::fromOrder($order),
            monthlyPriceInHaler: $order->firstPaymentPrice,
            isRecurring: $order->isRecurring(),
            paidThroughDate: $order->paidThroughDate,
        );
    }
}
```

Always reads from `Order.firstPaymentPrice` — never recomputes from storage.

### 3. New value object: `App\Service\Order\SigningEmailContent`

`src/Service/Order/SigningEmailContent.php` — feeds `signing_link.html.twig` + handler. Carries subject, headline, body intro, situation-aware "next step" line, button label.

```php
final readonly class SigningEmailContent
{
    public function __construct(
        public CustomerBillingSituation $situation,
        public string $subject,
        public string $headline,
        public string $nextStepLine,
        public string $buttonLabel,
        public ?\DateTimeImmutable $paidThroughDate,
        public int $monthlyPriceInHaler,
    ) {}

    public static function fromOrder(Order $order): self
    {
        $situation = CustomerBillingSituation::fromOrder($order);
        $placeName = $order->storage->place->name;

        return match ($situation) {
            CustomerBillingSituation::GOPAY_FIRST_CHARGE => new self(
                situation: $situation,
                subject: 'Podepište smlouvu a zaplaťte — pronájem skladu v '.$placeName,
                headline: 'Podpis smlouvy a platba',
                nextStepLine: 'Po podpisu budete přesměrováni na platební bránu GoPay (karta + 3D Secure). Po úspěšné platbě je pronájem aktivní.',
                buttonLabel: 'Podepsat a zaplatit',
                paidThroughDate: null,
                monthlyPriceInHaler: $order->firstPaymentPrice,
            ),
            CustomerBillingSituation::EXTERNALLY_PREPAID => new self(
                situation: $situation,
                subject: 'Podepište smlouvu — předplaceno do '.$order->paidThroughDate->format('d.m.Y'),
                headline: 'Podpis smlouvy',
                nextStepLine: sprintf('Pronájem je předplacen externě do %s — po podpisu nemusíte nic platit.', $order->paidThroughDate->format('d.m.Y')),
                buttonLabel: 'Podepsat smlouvu',
                paidThroughDate: $order->paidThroughDate,
                monthlyPriceInHaler: 0,
            ),
            CustomerBillingSituation::FREE => new self(
                situation: $situation,
                subject: 'Podepište smlouvu — bezplatný pronájem',
                headline: 'Podpis smlouvy',
                nextStepLine: 'Bezplatný pronájem — po podpisu nemusíte nic platit.',
                buttonLabel: 'Podepsat smlouvu',
                paidThroughDate: null,
                monthlyPriceInHaler: 0,
            ),
        };
    }
}
```

Unit test pins all three branches.

### 4. New value object: `App\Service\Order\CompletionPageViewModel`

`src/Service/Order/CompletionPageViewModel.php` — feeds `customer_signing_complete.html.twig` + controller. The completion page is only reached for the EXTERNAL branch of `CustomerSignOnboardingHandler` (GoPay goes straight to payment), so the GoPay case is moot here — but we keep the value object honest with all three cases for any future re-routing.

```php
final readonly class CompletionPageViewModel
{
    public function __construct(
        public CustomerBillingSituation $situation,
        public string $headline,
        public string $body,
        public string $statusUrl,        // signed UriSigner URL
    ) {}
}
```

The factory needs the status URL, so it lives in the controller (not on the VO). Controller builds:

- `GOPAY_FIRST_CHARGE`: headline "Smlouva podepsána, čekáme na platbu", body "Pro dokončení prosím dokončete platbu. Po úspěšné platbě je pronájem aktivní.", CTA "Zaplatit nyní" → status URL (which has `payNowUrl` per spec 020).
- `EXTERNALLY_PREPAID`: headline "Vše vyřízeno — pronájem je předplacen do {date}", body "Žádná další akce není potřeba. Detail pronájmu a všechny dokumenty najdete na následující stránce.", CTA "Zobrazit pronájem".
- `FREE`: headline "Vše vyřízeno — bezplatný pronájem aktivní", body "Žádná další akce není potřeba. Detail pronájmu a všechny dokumenty najdete na následující stránce.", CTA "Zobrazit pronájem".

### 5. New value object: `App\Service\Order\RentalActivatedEmailContent`

Same shape as `SigningEmailContent` but for `rental_activated.html.twig`. Subjects:

- GoPay: "Pronájem zahájen — platba zpracována — {placeName}"
- Prepaid: "Pronájem zahájen — předplaceno do {date} — {placeName}"
- Free: "Pronájem zahájen — bezplatný pronájem — {placeName}"

Headlines (inside `.success-message` block):

- GoPay: "Vaše platba byla úspěšně zpracována — pronájem skladu je aktivní" (today's wording, unchanged).
- Prepaid: "Pronájem byl zahájen"
- Free: "Pronájem byl zahájen — bezplatný pronájem"

Sub-headline paragraphs:

- GoPay: "Děkujeme za Vaši platbu. V příloze tohoto e-mailu najdete kompletní sadu dokumentů…" (today's wording).
- Prepaid: "Pronájem je předplacen externě do {date}. Po vypršení předplatného Vás kontaktujeme s pokyny pro další platby. V příloze tohoto e-mailu najdete podepsanou smlouvu a všechny související dokumenty."
- Free: "U této smlouvy se neúčtuje žádné měsíční nájemné. V příloze tohoto e-mailu najdete podepsanou smlouvu a všechny související dokumenty."

`fromContract(Contract)` returns one of three pre-built instances. Subject keeps `placeName` as a free template substitution. Unit test pins all three.

### 6. `CustomerSigningController` rewrite

`src/Controller/Public/CustomerSigningController.php`:

- **Drop** the `PriceCalculator` constructor dep and import (no longer needed; PHPStan will catch the unused service).
- **Drop** all three `priceCalculator->calculateFirstPaymentPrice($order->storage, …)` calls.
- **Build** `$priceViewModel = SigningPriceViewModel::fromOrder($order);` once at the start of `__invoke()` and once before each re-render.
- Pass to template as `priceViewModel` (replaces `firstPaymentPrice` + `isRecurring`).

### 7. Template rewrite: `templates/public/customer_signing.html.twig`

Replace the price block (lines 66-70) with situation-aware content. **Crucially, render the green banner BEFORE the order-summary card** so the customer reads the situation first; the summary is supporting detail.

Insert the banner at the very top of the card body (line 28), and remove the price-block from inside the summary table:

```twig
<div class="card-body">
    {# Situation banner — first thing the customer reads. #}
    {% if priceViewModel.situation.value == 'externally_prepaid' %}
        <div class="rounded-lg bg-green-50 border-2 border-green-300 p-4 mb-6 text-center">
            <strong class="text-green-900 text-lg">Pronájem je již předplacen externě do {{ priceViewModel.paidThroughDate|date('d.m.Y') }}</strong>
            <p class="mt-2 text-green-800 text-sm">Po podpisu smlouvy nemusíte nic platit. Po vypršení předplatného Vás kontaktujeme s pokyny pro další platby.</p>
        </div>
    {% elseif priceViewModel.situation.value == 'free' %}
        <div class="rounded-lg bg-green-50 border-2 border-green-300 p-4 mb-6 text-center">
            <strong class="text-green-900 text-lg">Bezplatný pronájem</strong>
            <p class="mt-2 text-green-800 text-sm">U této smlouvy se neúčtuje žádné nájemné. Po podpisu smlouvy nemusíte nic platit.</p>
        </div>
    {% endif %}

    <h1 class="text-2xl font-bold text-gray-900 mb-6">Podpis smlouvy</h1>
    ...
```

Inside the summary table, the price-row branches:

```twig
<hr class="border-gray-200 my-2">
{% if priceViewModel.situation.value == 'gopay_first_charge' %}
    <div class="flex justify-between font-semibold">
        <span>{{ priceViewModel.isRecurring ? 'Měsíční platba:' : 'Celková cena:' }}</span>
        <span>{{ (priceViewModel.monthlyPriceInHaler / 100)|number_format(0, ',', ' ') }} Kč{% if priceViewModel.isRecurring %} / měsíc{% endif %}</span>
    </div>
{% else %}
    {# No price row for prepaid/free — the banner above already covers it. #}
{% endif %}
```

The submit button label stays "Podepsat smlouvu" — `CustomerSignOnboardingHandler` already auto-completes EXTERNAL (covers prepaid + free).

### 8. `signing_link` e-mail rewrite

`src/Event/SendSigningLinkEmailHandler.php`:

- Add `OrderRepository $orderRepository` injection (needed to load `Order` from event — today we only have `userId` + `signingToken`).
- Add a new field to `AdminOnboardingInitiated`: `public Uuid $orderId`. Wire `AdminCreateOnboardingHandler:89-95` to pass `$order->id` into the event.
- In `__invoke()`: load order, build `$content = SigningEmailContent::fromOrder($order);`, pass into the template:

```php
$email = (new TemplatedEmail())
    ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
    ->to(new Address($event->customerEmail, $user->fullName))
    ->subject($content->subject)
    ->htmlTemplate('email/signing_link.html.twig')
    ->context([
        'name' => $user->fullName,
        'signingUrl' => $signingUrl,
        'content' => $content,
        'order' => $order,           // for the order-summary block
        'storage' => $order->storage,
        'place' => $order->storage->place,
        'storageType' => $order->storage->storageType,
    ]);
```

`templates/email/signing_link.html.twig` (`templates/email/signing_link.txt.twig` mirrors): rewrite to include the order-summary table (place, storage type, dates, monthly price ONLY if `content.situation.value == 'gopay_first_charge'`), the green situation banner (mirror the signing-page banner content), and the situation-specific button label `{{ content.buttonLabel }}`. The status-URL "track your order" line stays.

### 9. `customer_signing_complete` rewrite

`src/Controller/Public/CustomerSigningCompleteController.php`:

- Inject `OrderRepository` + `OrderStatusUrlGenerator`.
- Load order by `id`. Throw `NotFoundHttpException` if missing.
- Build a `CompletionPageViewModel` (factory in the controller, per req. 4).
- Pass to template.

`templates/public/customer_signing_complete.html.twig`:

```twig
<div class="max-w-lg mx-auto text-center py-12">
    <div class="mb-6">
        <svg class="mx-auto h-16 w-16 text-green-500" ...></svg>
    </div>
    <h1 class="text-2xl font-bold text-gray-900 mb-4">{{ viewModel.headline }}</h1>
    <p class="text-gray-600 mb-8">{{ viewModel.body }}</p>
    <a href="{{ viewModel.statusUrl }}" class="btn btn-primary">
        {{ viewModel.situation.value == 'gopay_first_charge' ? 'Zaplatit nyní' : 'Zobrazit pronájem' }}
    </a>
</div>
```

The signed status URL works for passwordless customers (spec 020). The `/login` CTA disappears entirely.

### 10. `rental_activated` e-mail rewrite

`src/Event/SendRentalActivatedEmailHandler.php`:

- Build `$content = RentalActivatedEmailContent::fromContract($contract);` near the top of `__invoke()`.
- Replace the hard-coded `->subject('Pronájem zahájen - '.$place->name)` with the situation-aware subject.
- Add to the e-mail context: `'content' => $content`, `'externalPaidThroughDate' => $contract->paidThroughDate?->format('d.m.Y')`.

`templates/email/rental_activated.html.twig`:

Replace the `.success-message` block (lines 89-93) and the "Děkujeme za Vaši platbu" paragraph (line 93):

```twig
<div class="success-message" style="background-color: {{ content.situation.value == 'gopay_first_charge' ? '#d1fae5' : (content.situation.value == 'free' ? '#d1fae5' : '#dbeafe') }}; border-color: {{ content.situation.value == 'gopay_first_charge' or content.situation.value == 'free' ? '#34d399' : '#3b82f6' }};">
    <strong style="color: {{ content.situation.value == 'externally_prepaid' ? '#1e3a8a' : '#065f46' }};">{{ content.headline }}</strong>
</div>
<p>{{ content.subline }}</p>
```

The "Potvrzení opakované platby" block (line 149+) keeps its existing `{% if isRecurring %}` guard — it's correctly hidden for prepaid/free.

### 11. `OrderEmailAttachments` paper-contract fallback

`src/Service/OrderEmailAttachments.php` — interface signature unchanged.

`src/Service/OrderEmailAttachmentsService.php`:

- Inject `ContractRepository $contractRepository` and `string $contractsDirectory` (= `%kernel.project_dir%/var/contracts`, mirror `ContractDownloadController:47`).
- Extract the digital-signature branch into `attachContractDocument(TemplatedEmail, Order): bool`.
- New `attachUploadedPaperContract(TemplatedEmail, Order): bool` that:

```php
$contract = $this->contractRepository->findByOrder($order);
if (null === $contract || !$contract->hasDocument() || null === $contract->documentPath) {
    return false;
}
// documentPath may be absolute or relative (mirror ContractDownloadController:50-54).
$filePath = str_starts_with($contract->documentPath, '/')
    ? $contract->documentPath
    : $this->contractsDirectory.'/'.$contract->documentPath;
if (!file_exists($filePath)) {
    return false;
}
$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION) ?: 'pdf');
$mime = 'pdf' === $ext ? 'application/pdf' : 'application/octet-stream';
$documentNumber = $this->contractGenerator->formatDocumentNumberForOrder($order);
$email->attachFromPath($filePath, sprintf('smlouva_%s.%s', $documentNumber, $ext), $mime);
return true;
```

- Replace the top-level call in `attachLegalDocuments`:

```php
$result['hasContract'] = $this->attachContractDocument($email, $order)
    || $this->attachUploadedPaperContract($email, $order);
```

- Wire `$contractsDirectory` in `config/services.php` (alongside the existing `OrderEmailAttachmentsService` binding).

### 12. Suppress duplicate "Potvrzení objednávky"

`src/Event/SendOrderPlacedEmailHandler.php` — top of `__invoke()`:

```php
public function __invoke(OrderPlaced $event): void
{
    $order = $this->orderRepository->get($event->orderId);

    // Admin onboardings that complete in a single transaction (migrate;
    // digital EXTERNAL/prepaid; digital free) queue OrderPlaced → OrderPaid
    // → OrderCompleted together. By the time this handler runs post-commit,
    // the order is already COMPLETED and SendRentalActivatedEmailHandler is
    // about to send the richer e-mail. Suppressing this one avoids a
    // near-duplicate "Potvrzení objednávky" with a misleading "rezervace
    // platná do" warning landing seconds before "Pronájem zahájen".
    if (true === $order->isAdminCreated && OrderStatus::COMPLETED === $order->status) {
        return;
    }

    ...
}
```

### 13. New entity: `OnboardingReminderSent`

`src/Entity/OnboardingReminderSent.php` — per-stage idempotency row:

```php
#[ORM\Entity]
#[ORM\Table(name: 'onboarding_reminder_sent')]
#[ORM\UniqueConstraint(name: 'uniq_onboarding_reminder_order_stage', columns: ['order_id', 'stage'])]
class OnboardingReminderSent
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: Order::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) Order $order,
        #[ORM\Column(length: 20)]
        private(set) string $stage,
        #[ORM\Column]
        private(set) \DateTimeImmutable $sentAt,
    ) {}
}
```

Migration: `docker compose exec web bin/console make:migration`. **No handwritten SQL.**

### 14. New repository: `OnboardingReminderSentRepository`

`src/Repository/OnboardingReminderSentRepository.php` — composition over `EntityManagerInterface`. Includes a pessimistic-lock lookup for the dispatch handler.

### 15. Daily cron + command + event + e-mail handler

`OrderRepository::findUnpaidSignedOnboarding(\DateTimeImmutable $now): Order[]` — admin-created + paymentMethod GOPAY + status RESERVED/AWAITING_PAYMENT + `signedAt IS NOT NULL` + `expiresAt > now`.

`App\Service\Onboarding\OnboardingReminderSchedule` — static `stageDueOn(now, signedAt): ?string` returning `'d_plus_2'` / `'d_plus_5'` / `null`. Calendar-day comparison (so cron time-of-day doesn't matter).

`App\Console\SendOnboardingPaymentRemindersCommand` (`#[AsCommand(name: 'app:send-onboarding-payment-reminders', …)]`) — mirrors `SendManualBillingPaymentRequestsCommand` 1:1.

`App\Command\DispatchOnboardingReminderCommand` + `DispatchOnboardingReminderHandler`:

```php
public function __invoke(DispatchOnboardingReminderCommand $command): void
{
    $now = $this->clock->now();
    $order = $this->orderRepository->get($command->orderId);

    // SELECT ... FOR UPDATE — prevents parallel cron / re-run races.
    $existing = $this->reminderRepository->findByOrderAndStageWithLock($order->id, $command->stage);
    if (null !== $existing) {
        return;
    }

    // Verify state hasn't drifted since the cron query (PAID/CANCELLED/EXPIRED).
    if (true !== $order->isAdminCreated
        || PaymentMethod::GOPAY !== $order->paymentMethod
        || !in_array($order->status, [OrderStatus::RESERVED, OrderStatus::AWAITING_PAYMENT], true)
        || null === $order->signedAt
    ) {
        return;
    }

    // Record FIRST so any later crash leaves the row in place — unique
    // constraint then blocks duplicates on the next cron run.
    $this->reminderRepository->save(new OnboardingReminderSent(
        id: $this->identityProvider->next(),
        order: $order,
        stage: $command->stage,
        sentAt: $now,
    ));

    $this->eventBus->dispatch(new OnboardingPaymentReminderRequested(
        orderId: $order->id,
        stage:   $command->stage,
        occurredOn: $now,
    ));
}
```

`App\Event\OnboardingPaymentReminderRequested` + `SendOnboardingPaymentReminderEmailHandler`. Subjects:

- D+2: "Připomínáme: dokončete platbu objednávky — Fajnesklady.cz"
- D+5: "Druhá připomínka: vaše objednávka stále čeká na platbu"

Body: warm but direct, place + storage + dates + amount + a single "Zaplatit nyní" CTA pointing at the status URL (which already exposes `payNowUrl`). Footer line: *"Pokud jste platbu již provedl/a, prosím ignorujte tuto zprávu. Pokud potřebujete pomoc, kontaktujte nás na simek@fajnesklady.cz."*

Templates: `templates/email/onboarding_payment_reminder.html.twig` + `.txt.twig`.

Wire the cron into the existing daily-cron deployment config alongside `app:send-external-prepayment-ending-soon`.

### 16. Form: EXTERNAL implies prepaid (or free)

`src/Form/AdminCreateOnboardingFormData.php` — add a callback:

```php
#[Assert\Callback]
public function validateExternalIsPrepaid(ExecutionContextInterface $context): void
{
    if (PaymentMethod::EXTERNAL !== $this->paymentMethod) {
        return;
    }
    if ('free' === $this->monthlyPriceMode) {
        return;   // Free contracts don't need a paid-through date.
    }
    if (!$this->isExternallyPrepaid || null === $this->paidThroughDate) {
        $context->buildViolation('Externí platba znamená, že zákazník již zaplatil — vyplňte datum, do kdy je předplaceno (zaškrtněte „Externí předplatné" a vyberte datum). Pro pronájem bez nutnosti platby zvolte „Zdarma".')
            ->atPath('paidThroughDate')
            ->addViolation();
    }
}
```

Update the form-template help text on `paymentMethod`: *"Externí platba znamená, že zákazník již zaplatil — bude potřeba doplnit datum předplatby."*

Migrate's `AdminMigrateCustomerFormData` already forces `isExternallyPrepaid = true` by default and requires `paidThroughDate` via `Assert\NotNull` — no change there.

### 17. `ContractService::calculateOutstandingDebt` — effective monthly

`src/Service/ContractService.php:106`:

```php
$monthlyRate = $contract->getEffectiveMonthlyAmount();
```

Integration test: a contract with `individualMonthlyAmount = 50_000` (500 Kč) and storage at `150_000` (1500 Kč) computes debt at the 500 Kč rate.

### 18. Tests

Unit:
- `tests/Unit/Service/Order/CustomerBillingSituationTest.php` — free wins over prepaid, prepaid wins over GoPay, both factory entry points pinned.
- `tests/Unit/Service/Order/SigningPriceViewModelTest.php` — three branches via `fromOrder`.
- `tests/Unit/Service/Order/SigningEmailContentTest.php` — subject + body strings pinned for each branch; `placeName` substitution.
- `tests/Unit/Service/Order/RentalActivatedEmailContentTest.php` — three branches.
- `tests/Unit/Service/Onboarding/OnboardingReminderScheduleTest.php` — boundary days; intra-day timing irrelevance.
- `tests/Unit/Form/AdminCreateOnboardingFormDataTest.php` (extend) — `validateExternalIsPrepaid` rejects EXTERNAL + standard + no `paidThroughDate`; accepts EXTERNAL + free; accepts EXTERNAL + prepaid + date; accepts GOPAY + anything.

Integration:
- `tests/Integration/Controller/CustomerSigningControllerTest.php` — verify the rendered page contains the green banner for prepaid/free; verify it shows `Order.firstPaymentPrice` not the storage rate for GoPay (set `individualMonthlyAmount = 80_000` + storage rate `150_000` → page shows 800 Kč, not 1 500 Kč).
- `tests/Integration/Controller/CustomerSigningCompleteControllerTest.php` (new) — three branches; CTA href points at the signed status URL not `/login`.
- `tests/Integration/Event/SendOrderPlacedEmailHandlerTest.php` (extend) — admin-created + COMPLETED → no `EmailLog` row for `order_placed`. Normal customer flow (RESERVED at handler-run) → row present.
- `tests/Integration/Event/SendRentalActivatedEmailHandlerTest.php` (extend) — body contains "předplaceno externě do 31.12.2026" for prepaid; "bezplatný pronájem" for free; "Vaše platba byla úspěšně zpracována" + "Děkujeme za Vaši platbu" only for GoPay-charged. Subject contains "Pronájem zahájen — předplaceno do …" for prepaid.
- `tests/Integration/Event/SendSigningLinkEmailHandlerTest.php` (new) — three subjects + body wording branches.
- `tests/Integration/Service/OrderEmailAttachmentsServiceTest.php` (extend) — migrate-style order (no order signature; contract with documentPath → real fixture PDF) → e-mail carries `smlouva_*.pdf` whose bytes equal the file.
- `tests/Integration/Repository/OrderRepositoryTest.php` (extend) — `findUnpaidSignedOnboarding` returns only the matching subset.
- `tests/Integration/Command/DispatchOnboardingReminderHandlerTest.php` (new) — first call dispatches event + writes row; second call same stage → no duplicate; state-flipped order → silent no-op.
- `tests/Integration/Console/SendOnboardingPaymentRemindersCommandTest.php` (new) — signedAt = 2 days ago → 1 event; same calendar day re-run → 0 events; 3 days ago → 0 events; 5 days ago → 1 event.
- `tests/Integration/Service/ContractServiceTest.php` (extend) — outstanding-debt uses individual monthly.

Fixtures:
- `fixtures/OnboardingFixtures.php` — add the three onboarding-shaped fixtures (digital prepaid signed-but-pre-payment GoPay; digital external prepaid auto-completed; migrate paper contract with PDF). Surfaces every branch in dev.

### 19. PROJECT_MAP.md update

Append:
- **Entities** — `OnboardingReminderSent` (idempotency for `app:send-onboarding-payment-reminders`; uniq `(order_id, stage)`).
- **Commands** — `DispatchOnboardingReminderCommand`.
- **Domain Events** — `OnboardingPaymentReminderRequested`; `AdminOnboardingInitiated` gains `orderId` field.
- **Services** — `Order\CustomerBillingSituation` (the central enum), `Order\SigningPriceViewModel`, `Order\SigningEmailContent`, `Order\CompletionPageViewModel`, `Order\RentalActivatedEmailContent`, `Onboarding\OnboardingReminderSchedule`.
- **Repositories** — `OnboardingReminderSentRepository`; new `OrderRepository::findUnpaidSignedOnboarding`.
- **Console commands** — `app:send-onboarding-payment-reminders`.

## Acceptance

- [ ] `docker compose exec web composer quality` is green (cs:fix, phpstan level 8, test:unit, test:integration).
- [ ] `docker compose exec web bin/console doctrine:schema:validate` reports no diff after the new migration.
- [ ] `make:migration` was used (no handwritten DDL).
- [ ] **Customer journey — externally prepaid (digital)**: admin onboards a customer with `paidThroughDate = 2026-12-31`. Customer's inbox receives **exactly one** signing-link email subjected "Podepište smlouvu — předplaceno do 31.12.2026"; signing page shows a green banner ("Pronájem je již předplacen externě do 31.12.2026") and **no** "Měsíční platba" line; after signing the completion page reads "Vše vyřízeno — pronájem je předplacen do 31.12.2026" with a "Zobrazit pronájem" CTA pointing at the signed status URL; customer receives **exactly one** post-sign email "Pronájem zahájen — předplaceno do 31.12.2026 — {placeName}" with the digitally-signed contract attached. The "Potvrzení objednávky" e-mail does **not** land. **Verified manually in browser + `/portal/admin/email-log`.**
- [ ] **Customer journey — free (digital)**: admin onboards with `monthlyPriceMode = free`. Signing-link subject "Podepište smlouvu — bezplatný pronájem"; signing page shows a green "Bezplatný pronájem" banner, no price; completion page "Vše vyřízeno — bezplatný pronájem aktivní"; post-sign email "Pronájem zahájen — bezplatný pronájem — {placeName}" with no "Děkujeme za Vaši platbu" anywhere. Order_placed suppressed.
- [ ] **Customer journey — GoPay (digital)**: admin onboards with `paymentMethod = GOPAY`. Signing-link subject "Podepište smlouvu a zaplaťte — pronájem skladu v {placeName}"; signing page shows the existing price block, reading `Order.firstPaymentPrice` (regression-test with `individualMonthlyAmount = 80_000` + storage `150_000` → page shows 800 Kč, not 1 500 Kč); after signing the customer is redirected to `/objednavka/{id}/platba` (the completion page is not visited); after payment the rental-activated email is unchanged from today's behaviour.
- [ ] **Customer journey — migrate**: admin migrates with paper contract. Customer receives **exactly one** email "Pronájem zahájen — předplaceno do {paidThroughDate} — {placeName}" with the uploaded paper PDF attached as `smlouva_*.pdf` (bytes equal to upload). No signing-link, no completion page (no signing happened), no order_placed.
- [ ] **GoPay signed-but-unpaid reminder**: a digital GoPay onboarding signed 2 days ago triggers `app:send-onboarding-payment-reminders` to dispatch one reminder email. Re-running the same calendar day dispatches zero (idempotency). At 5 days the second reminder fires. State-flipped-to-PAID between cron query and handler returns silently with no row written.
- [ ] **Admin form**: submitting `paymentMethod = EXTERNAL` + `monthlyPriceMode = standard` + `isExternallyPrepaid = false` (or `paidThroughDate = null`) is rejected with the validation message pointing at `paidThroughDate`. `paymentMethod = EXTERNAL` + `monthlyPriceMode = free` passes.
- [ ] **Outstanding-debt fix**: terminating an individual-priced contract mid-cycle records `outstandingDebtAmount` against the contract's `individualMonthlyAmount`, not the storage rate.
- [ ] `BACKLOG.md` + `PROJECT_MAP.md` updated.

## Out of scope

- **Customer-side self-service to convert an externally-prepaid contract to GoPay** before/after prepayment ends. Spec 025 already deferred this. The reminder cron from spec 025 (`app:send-external-prepayment-ending-soon`) fires 7 days before `paidThroughDate` and asks the customer to contact us; building `/portal/objednavky/{id}/nastavit-platbu` for token capture is its own spec.
- **Collapsing the form's `paymentMethod` + `billingMode` + `monthlyPriceMode` + `isExternallyPrepaid` into a single "Způsob platby" radio with 4 options.** Larger admin-UX refactor; req. 16 fixes the only customer-visible footgun (impossible limbo state) with a single Assert\Callback.
- **Rewording the `order_placed` email for legitimately-RESERVED GoPay onboardings.** The customer who genuinely needs to pay sees the existing "rezervace platná do" wording — that's correct for them. Add this only if the new reminder cron proves insufficient in practice.
- **Re-issuing already-sent wrong e-mails.** Forward-only fix. Customers who got "Děkujeme za Vaši platbu" before this spec lands keep their wrong email; we don't backfill.
- **A separate "po splatnosti" e-mail to the customer.** Today only admins see the po-splatnosti dashboard. Whether customers should also get a po-splatnosti notice is a collections-strategy decision belonging in its own spec.
- **Translating signing-page situational copy through `translation.yaml`.** Czech is the only supported language; inline strings are fine.
- **Live-component reactivity on the admin form.** Today the form posts and re-renders on validation error — that's enough. No client-side toggling between Indiv./Free/External/GoPay branches.
- **Dynamic post-prepayment rate display on signing page.** Customers signing a prepaid contract don't see "after prepayment ends you'll pay X Kč/month" — the contract document and VOP carry the full legal terms. The signing page stays simple.

## Open questions

None — proceed.
