# 021 — Customer never sees a lifetime total; admin & landlord see "Zaplaceno" + "K zaplacení celkem"

**Status:** done
**Type:** UX / wording rule + small refactor + role-gated aggregate block
**Scope:** medium (~14 files: 1 entity helper, 1 service helper, 1 repository helper, 2 web partials, 6 templates, 3 controllers, 2 tests)
**Depends on:** none. **Supersedes spec 013** (the customer-facing label fix originally in 013 is folded in here so the whole rule ships in one piece — see "Relationship to 013" below).

## Problem

Today every order surface — public order flow, all three portal detail pages, all three list/dashboard cards — renders `Order.totalPrice` with the same caption ("Celková cena" or just "Cena") regardless of billing cadence:

- For a **fixed-end ≥ 28-day** rental at 5 000 Kč/měsíc, `Order.totalPrice` stores the **monthly** rate. The customer sees `Celková cena 5 000 Kč` and assumes the rental is settled with one charge — but the cron will keep billing 5 000 Kč every month.
- For an **unlimited** rental, same field, same monthly rate, same misleading "Celková cena" label.
- For a **< 28-day one-time** rental, the value is genuinely the total — but it's labelled identically to the recurring case, so the label has lost all signal.

Beyond the wording bug, there's an explicit product rule the user wants enforced: **a customer should never see a lifetime sum across recurring charges.** Even if it were technically correct (e.g. for a fixed 6-month contract = 6 × 5 000 Kč), surfacing "30 000 Kč" to a renter shifts the framing from "you pay 5 000 Kč/měsíc" to "you owe us 30 000 Kč" — bad UX, no business reason to show it.

Conversely, **admin and landlord** legitimately need lifetime numbers to operate the platform: how much has the tenant paid so far ("Zaplaceno") and, where the term is finite, how much will be paid by the end ("K zaplacení celkem"). For unlimited rentals the latter is undefined and must not appear.

## Goal

A single, codebase-wide rule for price display, applied at every surface that renders a price tied to an `Order` or `Contract`:

| Audience | Pricing mode | What is shown |
|---|---|---|
| **Customer** (`ROLE_USER` only, or anonymous public flow) | One-time (< 28 days) | `Celková cena: X Kč` |
| **Customer** | Fixed-term recurring (≥ 28 days, has endDate) | `Měsíční platba: X Kč / měsíc` — never a sum |
| **Customer** | Unlimited recurring | `Měsíční platba: X Kč / měsíc` — never a sum |
| **Admin / Landlord** | One-time | `Cena: X Kč` (one row) |
| **Admin / Landlord** | Fixed-term recurring | `Měsíční platba: X Kč / měsíc` **+ Zaplaceno: Y Kč + K zaplacení celkem: Z Kč** |
| **Admin / Landlord** | Unlimited recurring | `Měsíční platba: X Kč / měsíc` **+ Zaplaceno: Y Kč** (no total — undefined) |

Three pricing modes (canonical names used throughout the spec, mirroring `PriceCalculator`):

1. **`one-time`** — `endDate !== null` AND `days(start, end) < 28`. Single charge, full amount = `Order.totalPrice`.
2. **`fixed-term-recurring`** — `endDate !== null` AND `days(start, end) >= 28`. Monthly cadence, finite N charges, total knowable.
3. **`unlimited-recurring`** — `endDate === null`. Monthly cadence, open-ended.

## Context (current state)

### What is already in place

- **`PriceCalculator::buildPaymentSchedule(Storage, startDate, endDate): PaymentSchedule`** (`src/Service/PriceCalculator.php:183`) returns the canonical schedule. Already used by the public order flow (`OrderAcceptController`, `OrderPaymentController`) and the recurring-billing cron — single source of truth.
- **`PaymentSchedule`** value object (`src/Value/PaymentSchedule.php`) exposes `entries`, `isRecurring`, `isOpenEnded`, `monthlyAmount`, `totalKnownAmount()`, `getMonthlyAmountInCzk()`, `entryCount()`. Everything needed for both a customer-facing "monthly" line and an admin-facing "total" line is already on this value object.
- **`PriceCalculator::needsRecurringBilling(startDate, endDate): bool`** (`src/Service/PriceCalculator.php:164`) — the cadence predicate. Authoritative.
- **`Order.totalPrice`** stores the **first-payment amount** in halíře. For `fixed-term-recurring` and `unlimited-recurring` this equals the monthly rate; for `one-time` it equals the full one-shot total. Set at order creation in `OrderService.php:80`. Important: this number is **locked at order creation** — even if `Storage.pricePerMonth` changes later, the order's monthly rate stays.
- **Role check pattern:** templates use `is_granted('ROLE_ADMIN')` / `is_granted('ROLE_LANDLORD')` (see `templates/portal/layout.html.twig:56,93`).

### What is wrong today

#### Customer surfaces with misleading "Celková cena" / no per-month suffix

- `templates/email/order_confirmation.html.twig:121-122` — "Celková cena: {{ totalPrice }}".
- `templates/email/order_cancelled.html.twig:111-112` — same.
- `templates/public/order_accept.html.twig:87-93, 115` — "Cena" line; brittle `if not formData.endDate` branch only catches unlimited, misses fixed-term-recurring. Line 557 already gets it right (`{% if isRecurring %} / měsíc`) — leave that one alone.
- `templates/public/order_payment.html.twig:65-72, 146` — "Celková cena" + "Zaplatit X Kč" button.
- `templates/public/order_complete.html.twig:62` — "{{ order.totalPriceInCzk }}".
- `templates/public/customer_signing.html.twig:69-70` — already conditional on `isRecurring`, but missing "/ měsíc" on the value.
- `templates/portal/user/order/detail.html.twig:111-118` — uses `if order.endDate` to decide "Celková cena" vs "Cena za měsíc". Misses the 28-day threshold (a 14-day order with endDate would still say "Celková cena" — that one is actually correct, but a 90-day order would also say "Celková cena" — wrong). Also missing the "/ měsíc" suffix.
- `templates/portal/user/order/list.html.twig:22, 68` — "Cena" header; rows render `X Kč` with no cadence indicator.
- `templates/portal/dashboard_user.html.twig:193` — Recent Orders card; `X Kč` with no cadence indicator.

#### Admin/landlord surfaces today (what they currently DO and DON'T show)

- `templates/portal/landlord/order/detail.html.twig:145-150` — hardcoded "Celková cena". No "Zaplaceno", no "K zaplacení celkem". The landlord cannot see at a glance how much the tenant has paid against the order.
- `templates/admin/order/detail.html.twig:147-148` — same.
- `templates/portal/landlord/order/list.html.twig:25, 79` and `templates/admin/order/list.html.twig:20, 46` — "Cena" column; same value as customer view.
- `templates/portal/dashboard_landlord.html.twig:192` — same.

#### Already correct (do not touch)

- `templates/email/contract_ready.html.twig:139` — uses `{{ monthlyAmount }}` with label "Výše platby" inside an `{% if isRecurring %}` block. Correct semantics; out of scope.
- `templates/email/invoice.html.twig` — invoice line items with their own "Celková částka". Invoices itemise; that's allowed.
- `templates/email/payment_default_*.html.twig` — "Dlužná částka" is a real debt amount, not a rental price. Out of scope.
- `templates/portal/landlord/self_billing/list.html.twig` — invoice "Hrubá částka" / "K vyplacení". Self-billing aggregates per period, not per-order rental price. Out of scope.

### Relationship to 013

Spec 013 is currently `ready` but unimplemented (no `Order::isRecurring()`, no `_price_label.html.twig` in the tree). It targeted the customer-facing label fix only. This spec covers the same surfaces plus list/dashboard rows plus the new admin/landlord aggregate block — implementing them all in one pass is cheaper than shipping 013 and then re-touching the same templates a week later. **Mark 013 as `superseded` in `BACKLOG.md` (status column, with link to 021). The implementer reads only this spec.**

## Architecture

```
        ┌──────────────────────────────────────────────────────────┐
        │  src/Entity/Order.php                                    │
        │  ── new: isRecurring(): bool       (mirrors needsRecurring) │
        │  ── new: isFixedTermRecurring(): bool                    │
        │  ── new: isOneTime(): bool                               │
        └──────────────────────────────────────────────────────────┘
                                 ▲
                                 │ used by every Twig template & controller
                                 │
        ┌──────────────────────────────────────────────────────────┐
        │  src/Service/PriceCalculator.php                         │
        │  ── new: buildScheduleFromOrder(Order $o): PaymentSchedule │
        │     uses Order.totalPrice as locked-in monthly anchor      │
        │     (NOT current Storage price — important after price     │
        │     changes post-order-creation)                           │
        └──────────────────────────────────────────────────────────┘
                                 ▲
                                 │ called by detail controllers
                                 │
        ┌──────────────────────────────────────────────────────────┐
        │  src/Repository/PaymentRepository.php                    │
        │  ── new: sumPaidByContract(Contract): int                │
        │     SUM(p.amount) WHERE p.contract = :c  (only contract  │
        │     because recurring charges link via contract, the     │
        │     one-time first charge links via both order+contract) │
        │  ── new: sumPaidByOrder(Order): int     (used pre-contract │
        │     phase, when contract is still null)                  │
        └──────────────────────────────────────────────────────────┘

  Customer-facing partial         Admin/landlord aggregate partial
  templates/_price_label.html.twig    templates/_price_aggregate.html.twig
  ── label + value, inline spans       ── extra dt/dd rows (paid + remaining)
  ── always shown                       ── only inserted when ROLE_LANDLORD
                                          or ROLE_ADMIN

  Email partial
  templates/email/_price_label.html.twig
  ── inline-styled <td>/<td> table row, Outlook-safe
```

## Requirements

### 1. `Order::isRecurring()` / `isFixedTermRecurring()` / `isOneTime()`

Add to `src/Entity/Order.php`, next to `isUnlimited()` (line 234):

```php
/**
 * Whether this order is billed on a monthly recurring cadence.
 *
 * Mirrors {@see \App\Service\PriceCalculator::needsRecurringBilling()}.
 * Three pricing modes total: isOneTime() | isFixedTermRecurring() | isUnlimited().
 */
public function isRecurring(): bool
{
    if (null === $this->endDate) {
        return true;
    }

    return (int) $this->startDate->diff($this->endDate)->days >= 28;
}

/**
 * Recurring AND with a known end date — the only mode for which a
 * lifetime "K zaplacení celkem" total can be computed.
 */
public function isFixedTermRecurring(): bool
{
    return $this->isRecurring() && null !== $this->endDate;
}

public function isOneTime(): bool
{
    return !$this->isRecurring();
}
```

The 28-day constant is duplicated rather than imported from `PriceCalculator::WEEKLY_THRESHOLD_DAYS`. Rationale: keep `Order` free of service deps; this entity needs to answer the question from a Twig template without DI. The constants must stay in sync — PHPStan will not catch drift, so the unit test (req. 9) covers exactly this.

`isUnlimited()` already exists; do not re-implement it. It returns `RentalType::UNLIMITED === $this->rentalType`. Keep using it where the question is "is the rentalType enum unlimited", but for **billing-cadence questions use `isRecurring()`** so the 28-day rule is honored consistently.

### 2. `PriceCalculator::buildScheduleFromOrder(Order $order): PaymentSchedule`

Add to `src/Service/PriceCalculator.php` after the existing `buildPaymentSchedule` (`PriceCalculator.php:183`):

```php
/**
 * Build the locked-in payment schedule for an *existing* order, using
 * Order.totalPrice as the monthly anchor (NOT the current Storage price).
 *
 * Why a separate method: buildPaymentSchedule(Storage, ...) reads
 * Storage.effectivePricePerMonth, which is the *current* price. After an
 * order is placed, the storage price may change; the order's monthly
 * stays. For displaying a schedule on portal/admin/landlord detail pages
 * we must respect that lock.
 */
public function buildScheduleFromOrder(Order $order): PaymentSchedule
{
    if ($order->isUnlimited()) {
        return new PaymentSchedule(
            entries: [new PaymentScheduleEntry($order->startDate, $order->totalPrice)],
            isRecurring: true,
            isOpenEnded: true,
            monthlyAmount: $order->totalPrice,
        );
    }

    $endDate = $order->endDate;
    \assert(null !== $endDate); // !isUnlimited() ⇒ endDate is set

    $days = $this->calculateDays($order->startDate, $endDate);

    if ($days < self::WEEKLY_THRESHOLD_DAYS) {
        return new PaymentSchedule(
            entries: [new PaymentScheduleEntry($order->startDate, $order->totalPrice)],
            isRecurring: false,
            isOpenEnded: false,
            monthlyAmount: null,
        );
    }

    // fixed-term recurring: walk calendar months at the locked monthly rate
    $monthlyRate = $order->totalPrice;
    $entries = [];
    $billingDate = $order->startDate;
    while ($billingDate < $endDate) {
        $nextBillingDate = $billingDate->modify('+1 month');
        if ($nextBillingDate <= $endDate) {
            $entries[] = new PaymentScheduleEntry($billingDate, $monthlyRate);
            $billingDate = $nextBillingDate;
            continue;
        }
        $remainingDays = max(1, $this->calculateDays($billingDate, $endDate));
        $dailyRate = $monthlyRate / self::DAYS_PER_MONTH;
        $proratedAmount = max(1, (int) round($remainingDays * $dailyRate));
        $entries[] = new PaymentScheduleEntry($billingDate, $proratedAmount);
        break;
    }

    return new PaymentSchedule(
        entries: $entries,
        isRecurring: true,
        isOpenEnded: false,
        monthlyAmount: $monthlyRate,
    );
}
```

The fixed-term loop duplicates the loop in `buildPaymentSchedule` (lines 229-243). Acceptable: both call sites need different inputs (Storage vs Order), and extracting a private `walkMonthsFromAnchor(int $monthly, $start, $end)` helper would be a cosmetic cleanup that adds indirection without reducing risk. **Out of scope for this spec to extract.** A future refactor can do it; the duplication is bounded to one method body.

`Order` is already imported via `App\Entity\Order` (already used by other methods in the calculator? if not, add the use statement).

### 3. `PaymentRepository::sumPaidByContract` and `sumPaidByOrder`

Add to `src/Repository/PaymentRepository.php`, after `findByContractAndPaidAt` (line 65):

```php
/**
 * Total amount (in halíře) the tenant has actually paid against this
 * contract — sum of every Payment row, including the initial charge
 * and every subsequent recurring charge. Refunds, if any, are stored
 * as separate Payment rows with negative amount and are netted in.
 *
 * Returns 0 when no payments exist (e.g. order paid via offline
 * channel and Payment row not yet created).
 */
public function sumPaidByContract(Contract $contract): int
{
    $result = $this->entityManager->createQueryBuilder()
        ->select('SUM(p.amount)')
        ->from(Payment::class, 'p')
        ->where('p.contract = :contract')
        ->setParameter('contract', $contract)
        ->getQuery()
        ->getSingleScalarResult();

    return (int) ($result ?? 0);
}

/**
 * Total paid against a specific order — used during the brief window
 * before the contract row exists (between markPaid and complete).
 */
public function sumPaidByOrder(Order $order): int
{
    $result = $this->entityManager->createQueryBuilder()
        ->select('SUM(p.amount)')
        ->from(Payment::class, 'p')
        ->where('p.order = :order')
        ->setParameter('order', $order)
        ->getQuery()
        ->getSingleScalarResult();

    return (int) ($result ?? 0);
}
```

(Imports `Contract`, `Order` already exist in this repository — see the file header.)

**Why two methods, not just one.** Once the order is `COMPLETED` and the contract exists, recurring charges link via `Payment.contract`, not `Payment.order` — so `sumPaidByOrder` would miss every recurring charge. But a `RESERVED`/`AWAITING_PAYMENT` order has no contract yet, and an offline-marked-paid order may sit briefly with a Payment but no Contract. Detail controllers pick the right one (see req. 4).

### 4. Wire admin/landlord aggregates through detail controllers

#### `src/Controller/Portal/Landlord/LandlordOrderDetailController.php` and `src/Controller/Portal/Admin/AdminOrderDetailController.php`

Read both controllers — their constructors currently inject the order/contract repos. Add `PriceCalculator` and `PaymentRepository` as readonly constructor params. In the action, after loading `$order` and `$contract`:

```php
$paymentSchedule = $this->priceCalculator->buildScheduleFromOrder($order);
$totalPaid = null !== $contract
    ? $this->paymentRepository->sumPaidByContract($contract)
    : $this->paymentRepository->sumPaidByOrder($order);
```

Pass both to the template:

```php
return $this->render('admin/order/detail.html.twig', [
    // …existing keys
    'paymentSchedule' => $paymentSchedule,
    'totalPaid'       => $totalPaid,
]);
```

Same edit in the landlord controller.

#### `src/Controller/Portal/User/OrderDetailController.php`

Inject **only** `PriceCalculator` (no `PaymentRepository` — customer never sees totals). Pass `paymentSchedule` to the template; the customer template uses only `paymentSchedule.monthlyAmount` / first-entry amount, never `.totalKnownAmount()`.

```php
$paymentSchedule = $this->priceCalculator->buildScheduleFromOrder($order);
// pass into render context
```

### 5. Two new partials + reuse the email partial

#### `templates/_price_label.html.twig` (web — Tailwind)

```twig
{# Customer-facing label + value, inline spans. Caller wraps in flex / dl / etc.
   Inputs:
     isRecurring   bool
     priceCzk      float    (the monthly rate for recurring, or the one-time total)
     labelClass    string  optional (default 'text-sm font-medium text-gray-500')
     valueClass    string  optional (default 'text-sm text-gray-900 font-semibold')
   This partial NEVER renders a lifetime total. For recurring it always
   appends "/ měsíc". For one-time it shows the value plain.
#}
{% set formattedPrice = priceCzk|number_format(2, ',', ' ') ~ ' Kč' %}
<span class="{{ labelClass|default('text-sm font-medium text-gray-500') }}">
    {{ isRecurring ? 'Měsíční platba' : 'Celková cena' }}
</span>
<span class="{{ valueClass|default('text-sm text-gray-900 font-semibold') }}">
    {{ formattedPrice }}{% if isRecurring %} / měsíc{% endif %}
</span>
```

#### `templates/_price_aggregate.html.twig` (web — Tailwind, admin/landlord-only)

```twig
{# Extra rows for staff: "Zaplaceno" + (when applicable) "K zaplacení celkem".
   Caller is responsible for gating: only include this partial inside a
   {% if is_granted('ROLE_LANDLORD') or is_granted('ROLE_ADMIN') %} block.
   Inputs:
     order            Order
     paymentSchedule  PaymentSchedule
     totalPaid        int   halíře, sum of Payment.amount
   For unlimited-recurring orders the "K zaplacení celkem" row is omitted —
   the total is undefined. (paymentSchedule.isOpenEnded is the gate.)
#}
<div>
    <dt class="text-sm font-medium text-gray-500">Zaplaceno</dt>
    <dd class="mt-1 text-sm text-gray-900 font-semibold">
        {{ (totalPaid / 100)|number_format(2, ',', ' ') }} Kč
    </dd>
</div>
{% if not paymentSchedule.isOpenEnded and paymentSchedule.isRecurring %}
    <div>
        <dt class="text-sm font-medium text-gray-500">K zaplacení celkem</dt>
        <dd class="mt-1 text-sm text-gray-900 font-semibold">
            {{ paymentSchedule.totalKnownAmountInCzk|number_format(2, ',', ' ') }} Kč
        </dd>
    </div>
{% endif %}
```

For one-time orders this partial still renders just "Zaplaceno" — useful for staff to confirm whether the single charge has cleared.

#### `templates/email/_price_label.html.twig` (email — inline-styled, Outlook-safe)

```twig
{# Inputs: isRecurring (bool), priceCzk (float|int)
   Used by order_confirmation, order_cancelled. #}
{% set formattedPrice = priceCzk|number_format(2, ',', ' ') ~ ' Kč' %}
{% if isRecurring %}
    <td>Měsíční platba:</td>
    <td><strong>{{ formattedPrice }} / měsíc</strong></td>
{% else %}
    <td>Celková cena:</td>
    <td><strong>{{ formattedPrice }}</strong></td>
{% endif %}
```

### 6. Update customer-facing templates (apply the rule)

| File | Change |
|---|---|
| `templates/email/order_confirmation.html.twig:120-123` | Replace the "Celková cena" `<tr>` body with `{% include 'email/_price_label.html.twig' with { isRecurring: isRecurring, priceCzk: priceCzk } only %}`. |
| `templates/email/order_cancelled.html.twig:110-113` | Same. |
| `templates/public/order_accept.html.twig:87-93,115` | Replace the two "Cena" blocks with `{% include '_price_label.html.twig' with { isRecurring: paymentSchedule.isRecurring, priceCzk: paymentSchedule.firstPayment.amountInCzk } only %}` (paymentSchedule is already in scope — see `OrderAcceptController.php:96-97`). |
| `templates/public/order_payment.html.twig:65-72` | Replace the `<dt>/<dd>` Celková cena pair with the partial. |
| `templates/public/order_payment.html.twig:146` | Change the button label to `Zaplatit {{ priceFormatted }}{% if paymentSchedule.isRecurring %} (první platba){% endif %}`. The "(první platba)" suffix is a compliance + UX gain — customer must know the button charges only the first month for recurring. |
| `templates/public/order_complete.html.twig:62` | Replace with the partial; keep the surrounding `flex justify-between` wrapper. |
| `templates/public/customer_signing.html.twig:69-70` | Append `{% if isRecurring %} / měsíc{% endif %}` to the value. The local conditional is already correct; just add the suffix. No partial. |
| `templates/portal/user/order/detail.html.twig:111-118` | Replace the `if order.endDate` block with: `<dt>{{ order.isRecurring ? 'Měsíční platba' : 'Celková cena' }}</dt><dd>{{ (order.totalPrice / 100)|number_format(2, ',', ' ') }} Kč{% if order.isRecurring %} / měsíc{% endif %}</dd>`. (Inline rather than the partial so the existing `<dl>` grid stays intact.) |
| `templates/portal/user/order/list.html.twig:68` | Change `{{ (order.totalPrice / 100)|number_format(2, ',', ' ') }} Kč` to `{{ (order.totalPrice / 100)|number_format(2, ',', ' ') }} Kč{% if order.isRecurring %} / měsíc{% endif %}`. The header at line 22 stays "Cena" — concise, accurate, mixed list is fine. |
| `templates/portal/dashboard_user.html.twig:193` | Same `{% if order.isRecurring %} / měsíc{% endif %}` suffix. |

### 7. Update admin & landlord templates (label + aggregate block)

| File | Change |
|---|---|
| `templates/portal/landlord/order/detail.html.twig:145-150` | Replace the `<dt>Celková cena</dt><dd>…</dd>` pair with the same inline cadence-aware block as the user-detail page (req. 6 row 8). Then immediately after, **inside the same `<dl>`**, render `{% include '_price_aggregate.html.twig' with { order: order, paymentSchedule: paymentSchedule, totalPaid: totalPaid } only %}`. |
| `templates/admin/order/detail.html.twig:147-148` | Identical edit. |
| `templates/portal/landlord/order/list.html.twig:79` | Append `{% if order.isRecurring %} / měsíc{% endif %}` (same as user list). Do NOT add a "Zaplaceno" column — list views stay compact; aggregates live on the detail page. |
| `templates/admin/order/list.html.twig:46` | Same suffix. |
| `templates/portal/dashboard_landlord.html.twig:192` | Same suffix. |

The detail-template change for landlord/admin is two parts:
1. Same wording fix as the customer view (no separate copy of the rule for staff — they read the same number with the same caption, then get extra rows below).
2. The aggregate partial below it. The two parts together let the staff member see "this customer pays 5 000 Kč/měsíc, has paid 25 000 Kč so far, will pay 60 000 Kč by contract end" at a glance. For unlimited rentals, only the first two rows render.

Important: do NOT wrap the inline cadence block in `{% if is_granted('ROLE_LANDLORD') %}` — staff see the same monthly-only label as customers. Only the **extra rows** (Zaplaceno / K zaplacení celkem) are role-gated, and the gate is implicit because the partial is only included from staff templates.

### 8. Update email handlers to pass the new keys

`src/Event/SendOrderConfirmationEmailHandler.php` and `src/Event/SendOrderCancelledEmailHandler.php` currently set the context key `totalPrice` (pre-formatted string). Replace with:

```php
->context([
    // …existing keys, drop totalPrice
    'priceCzk'    => $order->getTotalPriceInCzk(),
    'isRecurring' => $order->isRecurring(),
])
```

The two email templates (req. 6 rows 1-2) are the only consumers of those keys.

### 9. Tests

#### Unit — `tests/Unit/Entity/OrderTest.php`

Cover `isRecurring()` / `isFixedTermRecurring()` / `isOneTime()` against four scenarios:

| Scenario | startDate / endDate | isRecurring | isFixedTermRecurring | isOneTime | isUnlimited |
|---|---|---|---|---|---|
| One-time short | 2025-06-15 / 2025-06-22 (7 days) | false | false | true | false |
| One-time at threshold | 2025-06-15 / 2025-07-12 (27 days) | false | false | true | false |
| Fixed-term recurring | 2025-06-15 / 2025-12-15 (~183 days) | true | true | false | false |
| Unlimited | 2025-06-15 / null | true | false | false | true |

Use `MockClock` and `OrderFixtures::makeBare(...)` if it exists; otherwise raw entity construction with hardcoded UUIDs is fine.

#### Unit — `tests/Unit/Service/PriceCalculatorTest.php`

Add cases for `buildScheduleFromOrder`:

- One-time order: 1 entry, amount = `Order.totalPrice`, `isRecurring=false`, `isOpenEnded=false`.
- Fixed-term recurring 90 days at 5 000 Kč/měsíc: 3 entries (2 full months + prorated tail), `totalKnownAmount` matches expected.
- Unlimited at 5 000 Kč/měsíc: 1 entry, `isRecurring=true`, `isOpenEnded=true`, `monthlyAmount=500_000`.

Crucially: assert that **`buildScheduleFromOrder` ignores Storage.pricePerMonth changes**. Set up an order at 5 000 Kč, mutate the storage's `pricePerMonth` to 7 000 Kč, call `buildScheduleFromOrder($order)`, assert monthly is still 5 000 — the locked-in test.

#### Integration — `tests/Integration/Repository/PaymentRepositoryTest.php`

Cover `sumPaidByContract` and `sumPaidByOrder`:
- A contract with three payments (initial 5 000 + two recurring 5 000) → 15 000 Kč.
- An order with no payments → 0.
- An order with a single payment but no contract yet → `sumPaidByOrder` returns the single amount, `sumPaidByContract` is 0 (or N/A — there's no contract to query).

#### Integration — handler email assertions

Extend (or create) `tests/Integration/Event/SendOrderConfirmationEmailHandlerTest.php`:
- For `unlimited` order: `getHtmlBody()` contains `Měsíční platba` AND `/ měsíc`, does NOT contain `Celková cena`.
- For `fixed-term-recurring` 90-day order: same assertions.
- For `one-time` 14-day order: contains `Celková cena`, does NOT contain `Měsíční platba`.

Mirror in `SendOrderCancelledEmailHandlerTest`.

The portal/admin/landlord detail pages are covered indirectly by their controller tests (no new fixtures needed). A manual walk-through (req. 10) is the visual sanity check.

### 10. Manual acceptance walk-through (Czech, with diacritics — per memory rule)

Run `docker compose exec web composer db:reset` then test on the dev box (Chrome, mailpit):

**Customer (login as `user@example.com`):**
- `/portal/objednavky` — list shows `5 000,00 Kč / měsíc` for the unlimited fixture order, `1 800,00 Kč` for the 14-day fixture, `5 000,00 Kč / měsíc` for the 90-day fixture. Header is "Cena".
- `/portal/objednavky/{unlimitedOrderId}` — "Měsíční platba: 5 000,00 Kč / měsíc". No "Celková cena". No "Zaplaceno" / "K zaplacení celkem" — customer never sees those.
- `/portal/objednavky/{14dayOrderId}` — "Celková cena: 1 800,00 Kč". No "Měsíční platba".
- `/portal/objednavky/{90dayOrderId}` — "Měsíční platba: 5 000,00 Kč / měsíc". No "Celková cena". No staff aggregates.
- Place a fresh order (90-day fixed) → confirmation email at mailpit shows "Měsíční platba: 5 000,00 Kč / měsíc".
- `/objednavka/{order}/platba` — button reads "Zaplatit 5 000,00 Kč (první platba)" for recurring; "Zaplatit 1 800,00 Kč" for one-time.

**Landlord (login as `landlord@example.com`):**
- `/portal/landlord/orders/{90dayOrderId}` — sees "Měsíční platba: 5 000,00 Kč / měsíc", then "Zaplaceno: 5 000,00 Kč", then "K zaplacení celkem: 30 000,00 Kč" (or whatever the 6-month sum is). Staff aggregates visible.
- `/portal/landlord/orders/{unlimitedOrderId}` — sees "Měsíční platba", "Zaplaceno: X". The "K zaplacení celkem" row is **absent** — `paymentSchedule.isOpenEnded` is true.

**Admin (login as `admin@example.com`):**
- `/portal/admin/orders/{anyOrderId}` — same behavior as landlord view.

## Acceptance

- [ ] `docker compose exec web composer quality` is green.
- [ ] Manual walk-through (req. 10) passes for all three roles × all three pricing modes.
- [ ] No occurrence of `Celková cena` in any rendered output for an `unlimited-recurring` or `fixed-term-recurring` order — grep server logs of the manual run, or eyeball the relevant pages.
- [ ] For an `unlimited-recurring` order, no surface — customer or staff — renders a sum across multiple months.
- [ ] `Order::isRecurring()` agrees with `PriceCalculator::needsRecurringBilling()` on every test case (covered by req. 9 unit test).
- [ ] `BACKLOG.md` updated: add row `021` (status `ready`), and update row `013` to `superseded by 021`.

## Out of scope

- **Renaming `Order.totalPrice` → `Order.firstPaymentPrice`.** The field name is misleading but renaming touches a migration, every command/query/repository, every test fixture, and the `OrderCreated` event payload. Documented as a known wart; follow-up refactor.
- **Showing a 12-month "indicative total" for unlimited rentals to staff.** The user's rule explicitly says "not possible for unlimited because we do not know how many months." Don't synthesise one.
- **Customer-facing breakdown of past payments.** A customer can already see invoices on the order detail page (`templates/portal/user/order/detail.html.twig:264-299`). That itemisation is correct (per-invoice line items, mandated by accounting). Showing a SUM across them on this page is exactly what the user wants to avoid ("wow i have paid this much already?"). Leave the invoice list as-is, do not add a "Zaplaceno celkem" customer row.
- **Refactoring the duplicated calendar-walk loop in `PriceCalculator::buildScheduleFromOrder`.** A future cleanup can extract a private `walkMonthsFromAnchor()`. Bounded duplication is cheaper than premature abstraction.
- **Self-billing / invoice / dashboard revenue widgets** — those operate on aggregated periods (per-month landlord revenue), not per-order rental price. Their wording is not bugged.
- **Touching `templates/email/contract_ready.html.twig`** — already correct (`{% if isRecurring %}` block around `monthlyAmount`).
- **Translating to other languages.** Czech-only stack today.

## Open questions

None — proceed. (One sharp confirmation request below for the user, post-spec; if the answer changes the spec, mark `Status: draft` until resolved.)
