# 051 — Add pre-existing debt to admin onboarding

**Status:** done
**Type:** feature
**Scope:** large (~25 files changed/created)
**Depends on:** 050 (unified admin onboarding)

## Problem

Admin-onboarded customers may carry debt from a prior arrangement (e.g. unpaid rent at a previous facility, damage deposit owed). Today there is no way to attach this obligation to the onboarding — the admin must track it manually and hope the customer pays before the rental activates. The system has no enforcement point between VOP signing and the first rental charge.

## Goal

Admin enters an optional "Dluh" (CZK) during onboarding. When > 0, the debt becomes a **separate, sequential payment step** that the customer must complete after signing the VOP / contract, **before** the first rental payment. The customer receives an immediate debt-payment-request e-mail after signing plus the standard D+2 / D+5 reminders. The order status page and all relevant e-mails show the debt amount and a payment CTA (GoPay card or bank transfer, matching the order's payment method). For free / externally-prepaid customers with debt, the admin must choose a real payment method (EXTERNAL is disallowed when debt > 0); after the debt is paid the contract activates with zero ongoing cost.

## Context (current state)

### Onboarding form & handler
- **Live Component**: `src/Twig/Components/AdminOnboardingForm.php` — cascading place → type → dates → storage → billing; `submit()` dispatches `AdminOnboardingCommand` (line 303).
- **FormData**: `src/Form/AdminOnboardingFormData.php` — 9 `#[Assert\Callback]` validators; `variableSymbol` field at line 102 is the last billing-adjacent property.
- **FormType**: `src/Form/AdminOnboardingFormType.php`.
- **Command**: `src/Command/AdminOnboardingCommand.php` — 26-param readonly DTO.
- **Handler**: `src/Command/AdminOnboardingHandler.php` — `forceExternal` logic at line 70 (`$forceExternal = 0 === $command->individualMonthlyAmount || null !== $command->paidThroughDate`); VS assignment at line 74; billing terms write-once at line 82.

### Signing & post-signing redirect
- **Controller**: `src/Controller/Public/CustomerSigningController.php` — `handlePost()` dispatches `CustomerSignOnboardingCommand`; redirect at line 128: EXTERNAL → signing-complete, else → `public_order_payment` (line 133).
- **Handler**: `src/Command/CustomerSignOnboardingHandler.php` — for EXTERNAL: `confirmPayment()` + `CompleteOrderCommand` (auto-completes immediately). For GoPay/BANK_TRANSFER: order stays in RESERVED.

### Payment pages
- **Payment page**: `src/Controller/Public/OrderPaymentController.php` — shows GoPay embed or bank transfer details for `firstPaymentPrice`.
- **GoPay initiation**: `src/Controller/Public/PaymentInitiateController.php` → `GoPayApiClient::createPayment()` → stores `Order.goPayPaymentId`.
- **GoPay webhook**: `src/Command/ProcessPaymentNotificationHandler.php` — finds order via `OrderRepository::findByGoPayPaymentIdForUpdate()` (line 76); on success: `confirmPayment()` + `CompleteOrderCommand`.
- **Bank transfer matching**: `src/Console/ProcessFioTransactionsCommand.php` — `matchToOrder()` (line 305): checks contract recurring first, then falls back to first-payment match at line 368 (`order.canBePaid()` + amount = `firstPaymentPrice`).
- **Bank transfer handler**: `src/Command/ProcessBankTransferPaymentHandler.php` — `confirmPayment()` + `CompleteOrderCommand`.

### Order entity
- `src/Entity/Order.php` — no debt fields today. Key methods: `canBePaid()` (line 291), `markPaid()` (line 235), `setGoPayPaymentId()` (line 385). `setOnboardingBillingTerms()` (line 472) is the write-once method for billing overrides.

### Reminders
- `src/Service/Onboarding/OnboardingReminderSchedule.php` — two stages: `d_plus_2` (2 days) and `d_plus_5` (5 days) after signing.
- `src/Console/SendOnboardingPaymentRemindersCommand.php` — daily cron, dispatches `DispatchOnboardingReminderCommand`.

### Order status page
- `templates/public/order_status.html.twig` line 54: payment CTA block for signed-but-unpaid orders (non-EXTERNAL, `canBePaid()`).

## Architecture

```
Admin onboarding form
  │
  ▼
AdminOnboardingHandler
  │  sets Order.onboardingDebtInHaler
  │  adjusts forceExternal (debt > 0 ⟹ never EXTERNAL)
  │  assigns VS for BANK_TRANSFER
  ▼
Customer signs VOP  ──────►  CustomerSigningController
  │                            │
  │  EXTERNAL (impossible      │  debt > 0?
  │   when debt > 0)           │    YES → redirect /objednavka/{id}/platba/dluh
  │                            │    NO  → redirect /objednavka/{id}/platba (existing)
  ▼                            ▼
Order records OnboardingDebtPaymentRequested event
  │
  ▼  immediate e-mail + D+2/D+5 cron reminders
Customer pays debt
  │
  ├─ GoPay: webhook → ProcessPaymentNotificationHandler (new debtGoPayPaymentId branch)
  └─ Bank:  FIO cron → matchToOrder() new debt branch → ProcessBankTransferPaymentHandler
  │
  ▼  Order.debtPaidAt set
  │
  ├─ free/external billing? → auto-complete (confirmPayment(0) + CompleteOrderCommand)
  └─ standard billing?      → customer proceeds to /objednavka/{id}/platba (first rental)
                               → existing flow: GoPay/bank → PAID → COMPLETED
```

## Requirements

### 1. Order entity — new fields and methods

**File:** `src/Entity/Order.php`

Add three nullable columns after `uploadedContractDocumentPath` (line 161):

```php
#[ORM\Column(nullable: true)]
public private(set) ?int $onboardingDebtInHaler = null;

#[ORM\Column(nullable: true)]
public private(set) ?\DateTimeImmutable $debtPaidAt = null;

#[ORM\Column(nullable: true)]
public private(set) ?string $debtGoPayPaymentId = null;
```

Add methods:

```php
public function setOnboardingDebt(int $amountInHaler): void
{
    $this->onboardingDebtInHaler = $amountInHaler;
}

public function hasUnpaidDebt(): bool
{
    return null !== $this->onboardingDebtInHaler
        && $this->onboardingDebtInHaler > 0
        && null === $this->debtPaidAt;
}

public function hasDebt(): bool
{
    return null !== $this->onboardingDebtInHaler && $this->onboardingDebtInHaler > 0;
}

public function markDebtPaid(\DateTimeImmutable $now): void
{
    $this->debtPaidAt = $now;
}

public function setDebtGoPayPaymentId(string $paymentId): void
{
    $this->debtGoPayPaymentId = $paymentId;
}

public function getDebtAmountInCzk(): ?float
{
    return null !== $this->onboardingDebtInHaler ? $this->onboardingDebtInHaler / 100 : null;
}
```

### 2. Admin onboarding form

#### 2a. FormData

**File:** `src/Form/AdminOnboardingFormData.php`

Add after `variableSymbol` (line 102):

```php
#[Assert\PositiveOrZero(message: 'Dluh nemůže být záporný.')]
public ?float $debtAmountInCzk = null;
```

Add validation callback:

```php
#[Assert\Callback]
public function validateDebtPaymentMethod(ExecutionContextInterface $context): void
{
    if (null === $this->debtAmountInCzk || $this->debtAmountInCzk <= 0) {
        return;
    }

    if (PaymentMethod::EXTERNAL === $this->paymentMethod) {
        $context->buildViolation('Při dluhu nelze použít externě — zákazník musí mít možnost zaplatit. Zvolte GoPay nebo bankovní převod.')
            ->atPath('paymentMethod')
            ->addViolation();
    }
}
```

#### 2b. FormType

**File:** `src/Form/AdminOnboardingFormType.php`

Add a field near the billing section (after `variableSymbol`):

```php
->add('debtAmountInCzk', NumberType::class, [
    'label' => 'Dluh z předchozí smlouvy (Kč)',
    'required' => false,
    'html5' => true,
    'attr' => ['placeholder' => '0', 'min' => 0, 'step' => 1],
    'help' => 'Pokud má zákazník nevyplacený dluh, zadejte částku v Kč. Zákazník musí dluh uhradit před zahájením nového pronájmu.',
])
```

#### 2c. Command

**File:** `src/Command/AdminOnboardingCommand.php`

Add parameter (alongside `variableSymbolOverride`):

```php
public ?int $debtInHaler = null,
```

#### 2d. Live Component submit

**File:** `src/Twig/Components/AdminOnboardingForm.php`

In `submit()` method, compute `$debtInHaler` from form data and pass to `AdminOnboardingCommand`:

```php
$debtInHaler = null !== $formData->debtAmountInCzk && $formData->debtAmountInCzk > 0
    ? (int) round($formData->debtAmountInCzk * 100)
    : null;
```

Pass `debtInHaler: $debtInHaler` in the constructor call.

### 3. AdminOnboardingHandler — debt + forceExternal adjustment

**File:** `src/Command/AdminOnboardingHandler.php`

**Line 70 — adjust `forceExternal` logic**: debt > 0 overrides the free/prepaid auto-EXTERNAL:

```php
$isFreeOrPrepaid = 0 === $command->individualMonthlyAmount || null !== $command->paidThroughDate;
$hasDebt = null !== $command->debtInHaler && $command->debtInHaler > 0;
$forceExternal = $isFreeOrPrepaid && !$hasDebt;
$effectivePaymentMethod = $forceExternal ? PaymentMethod::EXTERNAL : $command->paymentMethod;
```

**After line 86** (after `setOnboardingBillingTerms`) — set debt and ensure VS for bank transfer:

```php
if (null !== $command->debtInHaler && $command->debtInHaler > 0) {
    $order->setOnboardingDebt($command->debtInHaler);
}
```

**Line 74 VS assignment**: ensure VS is assigned when `BANK_TRANSFER` even when billing is free/prepaid (now possible because `forceExternal` is false when debt > 0). The existing conditional `if (PaymentMethod::BANK_TRANSFER === $effectivePaymentMethod)` already handles this correctly after the `forceExternal` adjustment.

### 4. Signing controller — redirect to debt payment

**File:** `src/Controller/Public/CustomerSigningController.php`

Change redirect logic in `handlePost()` after dispatching `CustomerSignOnboardingCommand` (lines 128–133):

```php
if (PaymentMethod::EXTERNAL === $order->paymentMethod) {
    return $this->redirectToRoute('public_customer_signing_complete', ['id' => $order->id]);
}

if ($order->hasUnpaidDebt()) {
    return $this->redirectToRoute('public_order_debt_payment', ['id' => $order->id]);
}

return $this->redirectToRoute('public_order_payment', ['id' => $order->id]);
```

### 5. Immediate debt payment email — new event + handler

#### 5a. Event

**File:** `src/Event/OnboardingDebtPaymentRequested.php`

```php
final readonly class OnboardingDebtPaymentRequested
{
    public function __construct(
        public Uuid $orderId,
        public \DateTimeImmutable $occurredOn,
    ) {}
}
```

#### 5b. Record event in signing handler

**File:** `src/Command/CustomerSignOnboardingHandler.php`

After clearing the signing token and before the EXTERNAL branch, if the order has debt, record the event:

```php
if ($order->hasUnpaidDebt()) {
    $order->recordThat(new OnboardingDebtPaymentRequested(
        orderId: $order->id,
        occurredOn: $now,
    ));
}
```

This fires when the doctrine_transaction middleware commits, triggering the email handler below.

#### 5c. Email handler

**File:** `src/Event/SendDebtPaymentRequestEmailHandler.php`

Handles `OnboardingDebtPaymentRequested`. Loads order, generates the debt payment URL (signed with UriSigner, similar to `OrderStatusUrlGenerator`), sends e-mail with:
- Subject: `Dluh z předchozí smlouvy — {debtAmountCzk} Kč`
- Body: explains the debt, shows amount, and provides a payment CTA
- GoPay: "Zaplatit kartou" button linked to the debt payment page
- Bank transfer: shows bank account, VS, and QR code for the debt amount

Template: `templates/email/debt_payment_request.html.twig`

### 6. Debt payment page

#### 6a. Controller

**File:** `src/Controller/Public/OrderDebtPaymentController.php`

Route: `#[Route('/objednavka/{id}/platba/dluh', name: 'public_order_debt_payment')]`

UriSigner-protected (same pattern as `OrderStatusController`). Validates:
- Order exists
- Order has signature + accepted terms
- Order has unpaid debt (`$order->hasUnpaidDebt()`)
- If debt already paid → redirect to normal payment page (or order status if completed)

Renders template with: order, debt amount, payment method (GoPay vs bank transfer), QR code (for bank transfer via `QrPaymentGenerator`), bank account details, variable symbol.

#### 6b. Template

**File:** `templates/public/order_debt_payment.html.twig`

Shows:
- Header: "Úhrada dluhu z předchozí smlouvy"
- Amount: "Dluh: XX Kč"
- Explanation: "Před zahájením nového pronájmu je třeba uhradit dluh z předchozí smlouvy."
- **GoPay**: "Zaplatit XX Kč kartou" button → initiates GoPay (same embed pattern as existing payment page)
- **Bank transfer**: bank account number, variable symbol, QR code for the debt amount, "Čekáme na přijetí platby" notice

### 7. GoPay debt payment initiation

#### 7a. Initiate controller

**File:** `src/Controller/Public/DebtPaymentInitiateController.php`

Route: `#[Route('/objednavka/{id}/platba/dluh/iniciovat', name: 'public_debt_payment_initiate', methods: ['POST'])]`

Same pattern as `PaymentInitiateController` but:
- Amount: `$order->onboardingDebtInHaler`
- Stores payment ID in `$order->setDebtGoPayPaymentId($payment->id)` (NOT `setGoPayPaymentId`)
- `notification_url` = existing `/webhook/gopay` route (the handler will look up by `debtGoPayPaymentId`)
- `return_url` = `/objednavka/{id}/platba/dluh/navrat`

Creates a **one-shot** GoPay payment (no recurrence), reusing `GoPayApiClient::createOneTimeCharge()`.

#### 7b. Return controller

**File:** `src/Controller/Public/DebtPaymentReturnController.php`

Route: `#[Route('/objednavka/{id}/platba/dluh/navrat', name: 'public_debt_payment_return')]`

Checks order state:
- If debt paid + order completed (free/external) → redirect to order status page
- If debt paid + order still RESERVED → redirect to normal payment page (`public_order_payment`)
- If debt not yet paid (webhook pending) → redirect back to debt payment page with flash "Platba se zpracovává"

### 8. GoPay webhook — debt branch

**File:** `src/Command/ProcessPaymentNotificationHandler.php`

After the existing `findByGoPayPaymentIdForUpdate()` check at line 76 (which looks for `Order.goPayPaymentId`), add a second lookup for `Order.debtGoPayPaymentId`:

```php
// After existing order-payment lookup returns null (line 108):
$debtOrder = $this->orderRepository->findByDebtGoPayPaymentIdForUpdate($command->goPayPaymentId);
if (null !== $debtOrder) {
    if ($status->isPaid() && $debtOrder->hasUnpaidDebt()) {
        $debtOrder->markDebtPaid($now);
        $this->auditLogger->log('order', $debtOrder->id->toRfc4122(), 'debt_payment_confirmed', [
            'gopay_payment_id' => $command->goPayPaymentId,
            'debt_amount' => $debtOrder->onboardingDebtInHaler,
        ]);
        $this->autoCompleteIfApplicable($debtOrder, $now);
    }
    return;
}
```

**New repository method:** `OrderRepository::findByDebtGoPayPaymentIdForUpdate(string $paymentId): ?Order` — mirrors `findByGoPayPaymentIdForUpdate()` but queries `WHERE o.debtGoPayPaymentId = :id`.

**New private helper in handler:**

```php
private function autoCompleteIfApplicable(Order $order, \DateTimeImmutable $now): void
{
    $isFreeOrPrepaid = 0 === $order->individualMonthlyAmount
        || null !== $order->paidThroughDate;

    if ($isFreeOrPrepaid && $order->canBePaid()) {
        $this->orderService->confirmPayment($order, $now, 0);
        if ($order->hasAcceptedTerms()) {
            $this->commandBus->dispatch(new CompleteOrderCommand($order));
        }
    }
}
```

### 9. Bank transfer debt matching

#### 9a. FIO cron — matchToOrder()

**File:** `src/Console/ProcessFioTransactionsCommand.php`

In `matchToOrder()` (line 305), add a **new branch before the existing "First payment match" block** (line 368):

```php
// Debt payment match — must come before first-payment match
if ($order->hasUnpaidDebt()) {
    if ($bankTx->amount !== $order->onboardingDebtInHaler) {
        $bankTx->markAmountMismatch($order, $matchMethod, $now);
        ++$stats['amount_mismatches'];
        // ... audit log ...
        return true;
    }

    $bankTx->pairToOrder($order, $matchMethod, null, $now);
    // ... audit log 'auto_matched_to_order_debt' ...

    try {
        $this->commandBus->dispatch(new ProcessBankTransferDebtPaymentCommand($bankTx, $order));
    } catch (\Throwable $e) {
        // ... log ...
    }

    return true;
}
```

#### 9b. New command + handler for bank transfer debt

**File:** `src/Command/ProcessBankTransferDebtPaymentCommand.php`

```php
final readonly class ProcessBankTransferDebtPaymentCommand
{
    public function __construct(
        public \App\Entity\BankTransaction $transaction,
        public \App\Entity\Order $order,
    ) {}
}
```

**File:** `src/Command/ProcessBankTransferDebtPaymentHandler.php`

Handler: marks `$order->markDebtPaid($now)`, audit log, then calls the same `autoCompleteIfApplicable()` logic. Extract the auto-complete logic into a shared service (see §10).

### 10. Shared auto-complete service

**File:** `src/Service/Onboarding/DebtPaymentService.php`

Extracts the "mark debt paid + check auto-complete" logic used by both the GoPay webhook handler (§8) and the bank transfer debt handler (§9):

```php
final readonly class DebtPaymentService
{
    public function __construct(
        private OrderService $orderService,
        private AuditLogger $auditLogger,
        private MessageBusInterface $commandBus,
    ) {}

    public function confirmDebtPaid(Order $order, \DateTimeImmutable $now, ?string $goPayPaymentId = null): void
    {
        $order->markDebtPaid($now);

        $this->auditLogger->log('order', $order->id->toRfc4122(), 'debt_payment_confirmed', [
            'debt_amount' => $order->onboardingDebtInHaler,
            'gopay_payment_id' => $goPayPaymentId,
        ]);

        // Free or externally-prepaid billing → no first rental payment needed
        $isFreeOrPrepaid = 0 === $order->individualMonthlyAmount
            || null !== $order->paidThroughDate;

        if ($isFreeOrPrepaid && $order->canBePaid()) {
            $this->orderService->confirmPayment($order, $now, 0);
            if ($order->hasAcceptedTerms()) {
                $this->commandBus->dispatch(new CompleteOrderCommand($order));
            }
        }
    }
}
```

### 11. Order status page — debt CTA

**File:** `templates/public/order_status.html.twig`

Insert a new block **before** the existing payment CTA block (line 53). When the order has unpaid debt, show a prominent debt payment CTA instead of the normal payment CTA:

```twig
{# Unpaid debt from onboarding: prominent debt CTA #}
{% if order.hasUnpaidDebt() and order.hasSignature() and order.hasAcceptedTerms() %}
    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
        <h3 class="font-semibold text-red-800 mb-2">Úhrada dluhu</h3>
        <p class="text-red-700 text-sm mb-1">
            Před zahájením pronájmu je třeba uhradit dluh z předchozí smlouvy.
        </p>
        <p class="text-red-800 font-bold text-lg mb-3">
            Dluh: {{ (order.onboardingDebtInHaler / 100)|number_format(0, ',', ' ') }} Kč
        </p>
        <a href="{{ vm.debtPaymentUrl }}"
           class="btn btn-primary bg-red-600 hover:bg-red-700">
            {% if order.paymentMethod.value == 'bank_transfer' %}
                Zobrazit platební údaje pro úhradu dluhu
            {% else %}
                Zaplatit dluh kartou
            {% endif %}
        </a>
    </div>
{% elseif order.hasDebt() and order.debtPaidAt %}
    <div class="bg-green-50 border border-green-200 rounded-lg p-3 mb-6">
        <p class="text-green-800 text-sm">
            ✓ Dluh {{ (order.onboardingDebtInHaler / 100)|number_format(0, ',', ' ') }} Kč uhrazen {{ order.debtPaidAt|date('d.m.Y') }}
        </p>
    </div>
{% endif %}
```

**View model**: `OrderStatusViewModelFactory` needs to generate a signed `debtPaymentUrl` (UriSigner) for the debt payment page when the order has unpaid debt.

The existing payment CTA block (line 54) should add a guard: **suppress when debt is unpaid** (`and not order.hasUnpaidDebt()`). The customer must pay debt first.

### 12. Signing link e-mail — mention debt

**File:** `src/Event/SendSigningLinkEmailHandler.php`

Add `order.onboardingDebtInHaler` (or computed CZK value) to the e-mail context.

**File:** `templates/email/signing_link.html.twig`

When debt > 0, show an amber notice block:

```
Upozornění: K této objednávce evidujeme dluh z předchozí smlouvy ve výši XX Kč.
Po podpisu smlouvy budete nejprve vyzváni k úhradě tohoto dluhu.
```

### 13. Onboarding payment reminders — debt-aware

**File:** `src/Console/SendOnboardingPaymentRemindersCommand.php`

The existing cron finds signed-but-unpaid orders. Orders with unpaid debt are a subset of this — `findUnpaidSignedOnboarding()` already returns them (since they're in RESERVED and `canBePaid()`).

Change the reminder e-mail template (dispatched via `DispatchOnboardingReminderCommand` → handler) to **check `order.hasUnpaidDebt()`** and adapt the content:
- If debt unpaid: "Zaplaťte dluh XX Kč" with debt payment page CTA
- If debt paid but first rental unpaid: "Zaplaťte nájemné XX Kč" with normal payment page CTA (existing behavior)

### 14. Admin order detail — debt display

**File:** `templates/admin/order_detail.html.twig` (or equivalent admin order detail template)

Add a panel/badge showing:
- "Dluh z předchozí smlouvy: XX Kč" with status badge (Neuhrazen / Uhrazen DD.MM.YYYY)
- Show only when `order.onboardingDebtInHaler` is not null

### 15. AdminOnboardingForm template

**File:** `templates/components/AdminOnboardingForm.html.twig`

Add the debt input field in the billing section (near the variable symbol / external prepayment fields). The field is always visible (not conditionally shown) — it's a simple optional numeric input. Label: "Dluh z předchozí smlouvy (Kč)".

### 16. Migration

Generate via `docker compose exec web bin/console make:migration`.

Three new nullable columns on `orders`:
- `onboarding_debt_in_haler INT DEFAULT NULL`
- `debt_paid_at TIMESTAMP DEFAULT NULL`
- `debt_go_pay_payment_id VARCHAR(255) DEFAULT NULL`

## Acceptance

- [ ] Admin onboarding form shows "Dluh z předchozí smlouvy (Kč)" field; leaving it empty (or 0) means no debt
- [ ] Setting debt > 0 with `paymentMethod = EXTERNAL` shows a validation error
- [ ] Free/prepaid customer with debt > 0: admin must choose GoPay or BANK_TRANSFER; EXTERNAL is blocked
- [ ] After signing, customer with debt is redirected to `/objednavka/{id}/platba/dluh`
- [ ] Debt payment page shows correct amount and payment CTA (GoPay embed or bank transfer details + QR)
- [ ] GoPay debt payment webhook correctly marks `debtPaidAt` on the order
- [ ] Bank transfer debt payment matched by FIO cron correctly marks `debtPaidAt`
- [ ] Free/prepaid customer: after debt paid → order auto-completes (contract created)
- [ ] Standard-billing customer: after debt paid → redirected to normal first-rental payment page
- [ ] Customer without debt: flow unchanged (signing → payment page as before)
- [ ] Order status page shows debt CTA when unpaid; suppresses first-rental CTA until debt is cleared
- [ ] Order status page shows green confirmation when debt is paid
- [ ] Signing link e-mail mentions the debt amount when present
- [ ] Immediate debt payment request e-mail sent after signing (via `OnboardingDebtPaymentRequested` event)
- [ ] D+2 and D+5 reminder e-mails include debt info when debt is unpaid
- [ ] Admin order detail shows debt amount and status
- [ ] `composer quality` is green
- [ ] Focused unit tests for `Order.hasUnpaidDebt()`, `DebtPaymentService.confirmDebtPaid()`, and the `validateDebtPaymentMethod` form callback

## Out of scope

- **Automated Fakturoid invoice for debt payment.** The debt is a pre-contract one-off; the invoice infrastructure is wired to orders and contracts. Admin issues a manual invoice in Fakturoid for now. Can add automated debt invoicing in a follow-up if needed.
- **Debt payment on non-onboarding orders.** Only admin-created onboarding orders can carry debt. Customer self-service orders are unaffected.
- **Extending reminder schedule beyond D+5.** The order's place-level expiration window (default 30 days for onboarding) handles the hard deadline. If needed, additional reminder stages (D+10, D+14) can be added later.
- **Operations hub "Onboarding s dluhem" section.** The existing "Onboarding podepsaný bez platby" section in `/portal/admin/operace` already surfaces signed-but-unpaid orders, which includes debt-bearing ones.
- **Landlord / customer portal order detail debt display.** Debt is admin-only metadata for now; the public `/stav` page covers the customer-facing display.
- **Contract.outstandingDebtAmount inheritance.** The onboarding debt is paid before the contract exists. Once paid, the contract starts clean. If the contract later develops its own debt (termination due to payment failure), the existing overdue system handles that independently.

## Open questions

None — proceed.
