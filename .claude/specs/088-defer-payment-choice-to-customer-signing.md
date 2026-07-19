# 088 — Onboarding: let the customer choose payment method + frequency at signing (admin opt-in)

**Status:** done
**Type:** feature (order-lifecycle change — new deferred-choice state)
**Scope:** large (~1 migration + Order entity + admin form/component/template + command/handler + new Live Component choice step + new controller + signing controller guard + signing e-mail + admin display + tests)
**Depends on:** spec 050 (unified admin onboarding), spec 072 (signing-page parity), spec 076 (`BillingMode::derive`, payment matrix), spec 078-upfront (`PaymentFrequency::ONE_TIME`), spec 085 (billing-mode drift discipline)

## Problem

Today the admin **fully decides the payment shape** at onboarding creation: the "Platební metoda" (GoPay card / bank transfer / external) and "Frekvence plateb" (monthly / yearly / one-time upfront) radios lock `Order.paymentMethod`, `Order.paymentFrequency`, `Order.billingMode`, `Order.firstPaymentPrice` and (for bank) the variable symbol at `new Order(...)` time (`AdminOnboardingHandler.php:54-88`). The customer then only signs. For a large class of onboardings the admin doesn't actually know (or care) how the customer wants to pay — they just want to hand the customer a signing link and let the customer pick "kartou / převodem" and "měsíčně / ročně / jednorázově" themselves, exactly like a public web order does on `/objednavka`.

There is no way to defer that decision. The admin is forced to guess on the customer's behalf.

## Goal

The admin onboarding form gains a single checkbox **"Nechat vybrat zákazníka"** (default **unchecked**). When the admin checks it:

- The "Platební metoda", "Frekvence plateb", "Cenový model" (individuální/zdarma) and "Externí předplatné" sections disappear from the admin form. Pricing is forced to the **standard ceník**; the rental is a normal paying rental (never free/prepaid/custom).
- The order is created in a **well-defined "čeká na volbu platby" state** — not merely "hidden in the UI". No payment method / billing mode / VS is locked, and the price is a provisional monthly ceník figure.
- When the customer opens the signing link they first land on a **dedicated payment-choice step** (`/podpis/{token}/zpusob-platby`) — a small Live Component mirroring the public order form's method + frequency selector, with a live price preview and the card availability-guarantee note. On submit the choice is applied to the order (method, frequency, billing mode, recomputed price, VS for bank) and the customer proceeds to the **unchanged** signing page → payment → completion.

When the admin leaves the checkbox unchecked, **nothing changes** — the flow is byte-for-byte as today.

## Context (current state)

Read `.claude/specs/PROJECT_MAP.md`, then specs 076 / 072 / 085. Everything below verified 2026-07-19.

### The locking today (what "defer" has to unbind)

- **`Order` entity** `src/Entity/Order.php`:
  - `paymentFrequency` `:177-178` and `firstPaymentPrice` (column `total_price`) `:183-184` are **constructor-only `private(set)` with NO mutator** — decided at `new Order(...)`.
  - `paymentMethod` `:88-89` (`?PaymentMethod`, nullable) — setter `setPaymentMethod()` `:512-515`.
  - `billingMode` `:94-102` (default `AUTO_RECURRING`) — setter `setBillingMode()` `:522-525`.
  - `variableSymbol` `:91-92` (nullable, unique) — setter `assignVariableSymbol()` `:517-520`.
  - `status` starts `CREATED` `:190`; predicates `canBePaid()` `:298-301`, `isRecurring()` `:352-363`, `hasSignature()` `:465-468`, `hasUploadedContract()` `:555-558`, `hasUnpaidDebt()` `:582-587`.
- **`OrderService::createOrder()`** `src/Service/OrderService.php:46-152`: takes `PaymentFrequency $paymentFrequency = MONTHLY` + `?int $monthlyPriceOverride`, computes `firstPaymentPrice = $monthlyPriceOverride ?? calculateFirstPaymentPrice(storage, start, end, frequency)` `:120-121`, constructs the Order `:124-134`. **Does not touch method / billingMode / VS** — those are all set afterward by the handler.
- **`AdminOnboardingHandler::__invoke()`** `src/Command/AdminOnboardingHandler.php:34-118`:
  - `:54-64` `createOrder(..., paymentFrequency: $command->paymentFrequency, monthlyPriceOverride: $command->individualMonthlyAmount)`.
  - `:66` `markAsAdminCreated()`.
  - `:68-72` free/prepaid (no debt) → force `EXTERNAL`; else `command->paymentMethod` → `setPaymentMethod()`.
  - `:77-81` `billingMode` derived (prepaid ⇒ derive from EXTERNAL) → `setBillingMode()`.
  - `:83-88` **VS only for `BANK_TRANSFER`** (override or `VariableSymbolGenerator::generate(order->id)`).
  - `:91-95` `setOnboardingBillingTerms(individualMonthlyAmount, paidThroughDate, createdByAdmin)`.
  - `:97-99` `setOnboardingDebt`; `:101-104` uploaded contract; `:106` signing token; `:107` `extendExpiration(+30d)`; `:109-115` `AdminOnboardingInitiated`.
- **`AdminOnboardingCommand`** `src/Command/AdminOnboardingCommand.php:23-49`: non-null `PaymentMethod $paymentMethod` `:40`, `PaymentFrequency $paymentFrequency` `:44`, `?int $individualMonthlyAmount` `:41`, `?\DateTimeImmutable $paidThroughDate` `:42`, `?string $variableSymbolOverride` `:45`, `?int $debtInHaler` `:47`.

### Admin form (Live Component)

- **`AdminOnboardingFormData`** `src/Form/AdminOnboardingFormData.php`: `#[Assert\NotNull] ?PaymentMethod $paymentMethod` `:70-71`; derived `?BillingMode $billingMode` `:73-78`; `#[Assert\NotNull] ?PaymentFrequency $paymentFrequency` `:80-81`; `#[Assert\NotBlank] ?string $monthlyPriceMode` `:83-84`; `?float $customMonthlyPriceInCzk` `:92-93`; `bool $isExternallyPrepaid` `:95`; `?\DateTimeImmutable $paidThroughDate` `:97`; `?string $variableSymbol` `:106-108`; `?float $debtAmountInCzk` `:110-111`; `startsInPast()` `:120-124`. Payment callbacks: `validateMonthlyPriceMode` `:186-198`, `validateCustomPriceCap` `:200-217`, `validateUpfrontCustomPriceIsSinglePayment` `:219-241`, `validatePaymentMethod` `:243-278`, `validatePaymentFrequency` `:280-297`, `deriveBillingMode` `:304-323`, `validateExternalIsPrepaid` `:336-360`, `validatePaidThroughDate` `:362-414`, `validateDebtPaymentMethod` `:416-428`.
- **`AdminOnboardingFormType`** `src/Form/AdminOnboardingFormType.php`: `paymentMethod` EnumType `:113-123`; `paymentFrequency` `:127-129` (+ date-driven reconfigure); `monthlyPriceMode` `:130-139`; `customMonthlyPriceInCzk` `:142`; `variableSymbol` `:161-166`; `debtAmountInCzk` `:167-173`; PRE_SUBMIT normalises GoPay→MONTHLY `:205-209`.
- **`AdminOnboardingForm` component** `src/Twig/Components/AdminOnboardingForm.php`: `submit()` `:459-577` (asserts non-null method/frequency `:474-475`; builds `individualMonthlyAmount` `:492-496`, `paidThroughDate` `:497-500`, `debtInHaler` `:513-515`; dispatches `AdminOnboardingCommand` `:518-543`); `getSchedulePreview()` `:256-316` (live "Kalkulace plateb" card).
- **Template** `templates/components/AdminOnboardingForm.html.twig`: §9 "Platební metoda" `:245-274`, §10 "Frekvence plateb" `:276-362`, "Kalkulace plateb" `:380-…`, §11 "Cenový model" `:364-378`, "Externí předplatné" `:453-…`, "Existující smlouva" `:482-…`, "Dluh z předchozí smlouvy" `:494-502`, Submit `:506-…`.

### Signing flow (all reads `paymentMethod` / `billingMode` — must be locked before this runs)

- **`CustomerSigningController`** `src/Controller/Public/CustomerSigningController.php`: `__invoke()` `:36-59`; `computeContext()` `:206-227` (gates read `paymentMethod` `:221`, `billingMode` `:216-217`, `buildScheduleFromOrder` `:225`); `redirectAfterSigning()` `:241-252` (branches on `paymentMethod`).
- **`CustomerSignOnboardingHandler`** `src/Command/CustomerSignOnboardingHandler.php:28-94`: guards `isAdminCreated` + `canBePaid()` `:34-44`; signs + `reserve()` `:47-63`; EXTERNAL auto-completes `:85-92`.
- **`SigningEmailContent::fromOrder()`** `src/Service/Order/SigningEmailContent.php:26-55`: a deferred order (null `individualMonthlyAmount`, null `paidThroughDate`) resolves to `GOPAY_FIRST_CHARGE` → subject "Podepište smlouvu a zaplaťte", shows `firstPaymentPrice`. Template `templates/email/signing_link.html.twig` renders the price row gated on `content.situation.value == 'gopay_first_charge'` `:145-148`.

### Payment matrix helpers (reuse verbatim)

- **`BillingMode::derive(PaymentMethod, PaymentFrequency, int $rentalDays)`** `src/Enum/BillingMode.php:22-39`: `ONE_TIME` freq → `ONE_TIME`; `YEARLY` freq → `MANUAL_RECURRING`; else GOPAY→`AUTO_RECURRING`, BANK→(`<31d ? ONE_TIME : MANUAL_RECURRING`), EXTERNAL→`MANUAL_RECURRING`.
- **`PriceCalculator`** `src/Service/PriceCalculator.php`: `WEEKLY_THRESHOLD_DAYS=31` `:16`, `YEARLY_THRESHOLD_DAYS=360` `:18`; `calculateFirstPaymentPrice(storage, start, end, frequency)` `:169-178`; `buildPaymentSchedule` `:241-323`; `isUpfrontSplitIntoTranches(start, end)` `:585-588`.
- **`OrderForm` public order flow** — the proven method+frequency selector markup lives at `templates/components/OrderForm.html.twig:248-362` (payment method radios + green guarantee note `:262-270` + amber no-guarantee/bank note `:291-305` + frequency radio loop `:331-355` + `−10 %` badge `:321-323`). The choice-step template mirrors this section.
- **`VariableSymbolGenerator::generate(Uuid)`** `src/Service/Payment/VariableSymbolGenerator.php:19-33`.

### Order status / availability during the pending window (accepted behaviour — document, don't fight)

A deferred order sits in `CREATED` between creation and the customer's choice. `CREATED` is a **blocking** status in `OrderRepository::findOverlappingByStorage`, so the unit stays reserved for the customer (no double-book). The provisional `billingMode = AUTO_RECURRING` means the pending order **over-blocks open-endedly** (spec 076 availability rule) until the choice is applied or the order expires (+30 days). This is conservative and correct — the admin intends to rent this unit — so it is **left as-is**.

## Architecture

```
Admin onboarding form
  └─ [ ] Nechat vybrat zákazníka
        unchecked → identical to today (admin picks method+frequency, order fully locked)
        checked   → force STANDARD ceník; hide method/frequency/cenový-model/externí sections
                    (keeps: dates, storage, customer, contract upload, debt)
                       │
                       ▼
          AdminOnboardingHandler (deferred branch)
            createOrder(freq: MONTHLY provisional, override: null)   ← provisional monthly ceník price
            markCustomerChoosesPayment()                             ← customerChoosesPayment = true
            (NO setPaymentMethod / setBillingMode / assignVariableSymbol / external-force)
            setOnboardingBillingTerms(null, null, admin) + optional debt + optional contract + token
                       │
   signing e-mail (neutral: "Vyberte způsob platby a podepište smlouvu", no locked price)
                       │
                       ▼
   /podpis/{token}   (CustomerSigningController)
     order.isAwaitingPaymentChoice() ?  yes → 302 → /podpis/{token}/zpusob-platby
                                                        (CustomerPaymentChoiceController
                                                         → OnboardingPaymentChoiceForm Live Component)
                                                        method (karta/převod) + frequency (matrix)
                                                        live price preview + guarantee note
                                                          │ submit
                                                          ▼
                                            ChooseOnboardingPaymentCommand
                                              firstPaymentPrice = calculateFirstPaymentPrice(freq)
                                              billingMode = BillingMode::derive(method, freq, days)
                                              vs = BANK ? generate : null
                                              order.applyCustomerPaymentChoice(method, freq, price, mode, vs)
                                                          │
                                                          ▼ 302 → /podpis/{token}
     order.isAwaitingPaymentChoice() ?  no  → normal signing page (UNCHANGED downstream)
                                              → payment → completion
```

`customerChoosesPayment` is **write-once provenance** (set at creation, never cleared). "Choice made" is derived from `paymentMethod !== null`. This keeps the signing redirect and the re-editability logic clean:
- `isAwaitingPaymentChoice()` = `customerChoosesPayment && paymentMethod === null`.
- `canEditPaymentChoice()` = `customerChoosesPayment && !hasSignature()` (customer may revisit the choice step and change it right up until they sign).

## Requirements

### 1. `Order` entity — deferred-choice state + single controlled write path

`src/Entity/Order.php`:

```php
#[ORM\Column(options: ['default' => false])]
public private(set) bool $customerChoosesPayment = false;

/** Set once at admin onboarding when the admin defers the payment shape to the customer. */
public function markCustomerChoosesPayment(): void
{
    $this->customerChoosesPayment = true;
}

/** The customer has not yet chosen — the signing flow must route to the choice step first. */
public function isAwaitingPaymentChoice(): bool
{
    return $this->customerChoosesPayment && null === $this->paymentMethod;
}

/** The choice may still be (re)made: deferred order, not yet signed. */
public function canEditPaymentChoice(): bool
{
    return $this->customerChoosesPayment && !$this->hasSignature();
}

/**
 * The customer's payment choice — the ONLY path that writes the otherwise
 * constructor-locked frequency + firstPaymentPrice after creation. Guarded to
 * deferred, unsigned orders so it can never rewrite a normal order's price.
 */
public function applyCustomerPaymentChoice(
    PaymentMethod $paymentMethod,
    PaymentFrequency $paymentFrequency,
    int $firstPaymentPrice,
    BillingMode $billingMode,
    ?string $variableSymbol,
): void {
    if (!$this->canEditPaymentChoice()) {
        throw new \DomainException('Order is not awaiting an editable customer payment choice.');
    }
    $this->paymentMethod = $paymentMethod;
    $this->paymentFrequency = $paymentFrequency;
    $this->firstPaymentPrice = $firstPaymentPrice;
    $this->billingMode = $billingMode;
    $this->variableSymbol = $variableSymbol; // null clears any stale VS (method flipped bank→card)
}
```

Add a **schema migration** for `customer_chooses_payment BOOLEAN NOT NULL DEFAULT false` — generate via `bin/console make:migration` after the entity edit (never handwrite). No data backfill (existing rows default `false`).

### 2. `AdminOnboardingCommand` — carry the flag; method/frequency become optional

`src/Command/AdminOnboardingCommand.php`: change `PaymentMethod $paymentMethod` → `?PaymentMethod $paymentMethod`, `PaymentFrequency $paymentFrequency` → `?PaymentFrequency $paymentFrequency`, and add `public bool $letCustomerChoosePayment = false`. Update the class docblock: when `letCustomerChoosePayment` is true, `paymentMethod` / `paymentFrequency` / `individualMonthlyAmount` / `paidThroughDate` / `variableSymbolOverride` are all null (standard ceník, customer decides).

### 3. `AdminOnboardingHandler` — deferred branch

`src/Command/AdminOnboardingHandler.php`. Wrap the payment-locking block:

```php
if ($command->letCustomerChoosePayment) {
    // Deferred: provisional MONTHLY standard-ceník order; the customer will
    // choose method + frequency at signing (ChooseOnboardingPaymentHandler),
    // which recomputes firstPaymentPrice + billingMode + VS.
    $order = $this->orderService->createOrder(
        user: $user,
        storageType: $command->storageType,
        place: $command->place,
        startDate: $command->startDate,
        endDate: $command->endDate,
        now: $now,
        paymentFrequency: PaymentFrequency::MONTHLY, // provisional
        preSelectedStorage: $command->storage,
        monthlyPriceOverride: null,                  // standard ceník only
    );
    $order->markAsAdminCreated();
    $order->markCustomerChoosesPayment();
    // NO setPaymentMethod / setBillingMode / assignVariableSymbol / external-force.
    $order->setOnboardingBillingTerms(
        individualMonthlyAmount: null,
        paidThroughDate: null,
        createdByAdmin: $createdByAdmin,
    );
} else {
    // ... existing block (createOrder + method/billingMode/VS/terms) unchanged ...
}
```

Debt (`setOnboardingDebt`), uploaded contract (`moveContractDocument`/`setUploadedContractDocumentPath`), signing token, `extendExpiration(+30d)` and `AdminOnboardingInitiated` stay **common to both branches** (move them below the `if/else`). `$createdByAdmin` is resolved once for both. The debt is allowed with deferral (user decision): the method the customer picks applies to both the debt payment and the rental — `EXTERNAL` is never offered to the customer, so the "no EXTERNAL with debt" rule holds automatically.

### 4. `AdminOnboardingFormData` — new flag, conditional requirements, standard-only guard

`src/Form/AdminOnboardingFormData.php`:

- Add `public bool $letCustomerChoosePayment = false;`.
- **Remove the property-level `#[Assert\NotNull]` from `paymentMethod` `:70` and `paymentFrequency` `:80`** and require them via a new callback only when NOT deferring:

```php
#[Assert\Callback]
public function validatePaymentSelectionRequired(ExecutionContextInterface $context): void
{
    if ($this->letCustomerChoosePayment) {
        return;
    }
    if (null === $this->paymentMethod) {
        $context->buildViolation('Vyberte způsob platby.')->atPath('paymentMethod')->addViolation();
    }
    if (null === $this->paymentFrequency) {
        $context->buildViolation('Vyberte frekvenci platby.')->atPath('paymentFrequency')->addViolation();
    }
}
```

- **Early-return when deferring** in `validatePaymentMethod`, `validatePaymentFrequency`, `deriveBillingMode`, `validateExternalIsPrepaid`, `validatePaidThroughDate`, `validateDebtPaymentMethod`, `validateMonthlyPriceMode`, `validateCustomPriceCap`, `validateUpfrontCustomPriceIsSinglePayment` — add `if ($this->letCustomerChoosePayment) { return; }` at the top of each (they all reason about method/frequency/custom-price that no longer exist in this mode).
- **New guard callback** enforcing the "standard ceník only" decision (defence-in-depth — the UI already hides these, this stops a crafted POST):

```php
#[Assert\Callback]
public function validateLetCustomerChoose(ExecutionContextInterface $context): void
{
    if (!$this->letCustomerChoosePayment) {
        return;
    }
    if (null !== $this->monthlyPriceMode && 'standard' !== $this->monthlyPriceMode) {
        $context->buildViolation('Při volbě „Nechat vybrat zákazníka" nelze nastavit individuální cenu ani pronájem zdarma.')
            ->atPath('monthlyPriceMode')->addViolation();
    }
    if ($this->isExternallyPrepaid || null !== $this->paidThroughDate) {
        $context->buildViolation('Při volbě „Nechat vybrat zákazníka" nelze zadat externí předplatné.')
            ->atPath('isExternallyPrepaid')->addViolation();
    }
    if ($this->startsInPast()) {
        $context->buildViolation('Při volbě „Nechat vybrat zákazníka" musí být datum začátku dnes nebo v budoucnosti (zpětné předplatné volí administrátor).')
            ->atPath('startDate')->addViolation();
    }
}
```

`monthlyPriceMode` keeps its `#[Assert\NotBlank]`; the component forces it to `'standard'` in this mode (req 6), so it is always present.

### 5. `AdminOnboardingFormType` — the checkbox

`src/Form/AdminOnboardingFormType.php`: add a `letCustomerChoosePayment` `CheckboxType` (`required: false`, `label: 'Nechat vybrat zákazníka'`, `help: 'Zákazník si při podpisu sám zvolí způsob a frekvenci platby. Pronájem bude za standardní ceník.'`). Keep `paymentMethod` / `paymentFrequency` as fields — they are conditionally rendered by the template. **Do not** make them `required` at the type level (validation is now callback-driven).

### 6. Admin form template + component `submit()`

`templates/components/AdminOnboardingForm.html.twig`:
- Render the `letCustomerChoosePayment` checkbox as a prominent card **above §9 "Platební metoda"** (it governs the sections below it). Wire it to re-render live: `data-model="on(change)|letCustomerChoosePayment"` (or the form-wide `data-model` already in place — mirror how `monthlyPriceMode`/`paymentMethod` trigger re-render).
- `{% set deferPayment = formData.letCustomerChoosePayment ?? false %}` at the top.
- Wrap §9 (Platební metoda), §10 (Frekvence plateb), the "Kalkulace plateb" card, §11 (Cenový model) and "Externí předplatné" in `{% if not deferPayment %} … {% endif %}`. Call `{% do form.paymentMethod.setRendered %}`, `{% do form.paymentFrequency.setRendered %}`, `{% do form.monthlyPriceMode.setRendered %}`, `{% do form.customMonthlyPriceInCzk.setRendered %}`, `{% do form.variableSymbol.setRendered %}`, `{% do form.isExternallyPrepaid.setRendered %}`, `{% do form.paidThroughDate.setRendered %}` inside the `else` so Symfony doesn't complain about unrendered fields.
- In the `deferPayment` branch, render a short green info card: **"Zákazník si při podpisu smlouvy sám zvolí způsob platby (kartou / převodem) a frekvenci. Pronájem bude za standardní ceník pobočky."**
- Keep "Existující smlouva" and "Dluh z předchozí smlouvy" visible in **both** branches.

`src/Twig/Components/AdminOnboardingForm.php` `submit()`:
- Guard the `\assert(null !== $formData->paymentMethod)` / `\assert(null !== $formData->paymentFrequency)` (`:474-475`) behind `if (!$formData->letCustomerChoosePayment)`.
- When deferring, force `individualMonthlyAmount = null`, `paidThroughDate = null`, `variableSymbol = null` and pass `letCustomerChoosePayment: true`, `paymentMethod: null`, `paymentFrequency: null` into `AdminOnboardingCommand`. (Debt + uploaded contract pass through as today.)
- `getSchedulePreview()` `:256-316`: `if ($data->letCustomerChoosePayment) { return null; }` at the top (the "Kalkulace" card is hidden anyway; this keeps the getter from touching the now-absent frequency).

### 7. New `ChooseOnboardingPaymentCommand` + handler

`src/Command/ChooseOnboardingPaymentCommand.php` (`final readonly`): `public Order $order`, `public PaymentMethod $paymentMethod`, `public PaymentFrequency $paymentFrequency`.

`src/Command/ChooseOnboardingPaymentHandler.php` (`#[AsMessageHandler] final readonly`, inject `PriceCalculator`, `VariableSymbolGenerator`, `AuditLogger`, `ClockInterface`):

```php
public function __invoke(ChooseOnboardingPaymentCommand $command): void
{
    $order = $command->order;
    if (!$order->canEditPaymentChoice()) {
        throw new \DomainException('Order is not awaiting an editable customer payment choice.');
    }
    \assert(null !== $order->endDate);

    $rentalDays = (int) $order->startDate->diff($order->endDate)->days;

    // Enforce the same matrix the public order form enforces (belt to the
    // form-level braces): card ≥ 31 days, yearly/one-time bank-only.
    $method = $command->paymentMethod;
    $frequency = $command->paymentFrequency;
    if (PaymentMethod::GOPAY === $method && (
        $rentalDays < PriceCalculator::WEEKLY_THRESHOLD_DAYS
        || PaymentFrequency::YEARLY === $frequency
        || PaymentFrequency::ONE_TIME === $frequency
    )) {
        throw new \DomainException('Card payments are monthly recurring only (≥ 31 days).');
    }
    if (PaymentFrequency::YEARLY === $frequency && $rentalDays < PriceCalculator::YEARLY_THRESHOLD_DAYS) {
        throw new \DomainException('Yearly payment requires a rental of at least 12 months.');
    }

    $firstPaymentPrice = $this->priceCalculator->calculateFirstPaymentPrice(
        $order->storage, $order->startDate, $order->endDate, $frequency,
    );
    $billingMode = BillingMode::derive($method, $frequency, $rentalDays);
    $variableSymbol = PaymentMethod::BANK_TRANSFER === $method
        ? ($order->variableSymbol ?? $this->variableSymbolGenerator->generate($order->id))
        : null;

    $order->applyCustomerPaymentChoice($method, $frequency, $firstPaymentPrice, $billingMode, $variableSymbol);
    $this->auditLogger->logOnboardingPaymentChosen($order); // new action 'onboarding/payment_chosen'
}
```

Add `AuditLogger::logOnboardingPaymentChosen(Order): void` mirroring existing single-order audit methods (payload: `payment_method`, `payment_frequency`, `billing_mode`, `first_payment_price`). **Audit inside the handler** (never after a dispatch — CLAUDE.md §Manual flush).

The write goes through the command bus (`doctrine_transaction` flushes). EXTERNAL is never a valid customer choice — the `paymentMethod` choices offered are card + bank only (req 8), and the matrix guard above rejects anything else.

### 8. Choice step — Live Component + FormData + FormType

**`src/Form/OnboardingPaymentChoiceFormData.php`** (`final`): `public ?PaymentMethod $paymentMethod = null;` `public ?PaymentFrequency $paymentFrequency = null;`. Two callbacks mirroring `OrderFormData` — but reading the fixed rental window injected from the component via a plain setter (POPO can't DI): store `?int $rentalDays` set by the component before `isValid()`. Rules: `paymentMethod` required; `paymentFrequency` required; GOPAY + `rentalDays < 31` → violation on `paymentMethod` ('Platba kartou je dostupná pro pronájmy od 31 dnů. Kratší pronájem zaplatíte bankovním převodem.'); GOPAY + (YEARLY|ONE_TIME) → violation on `paymentFrequency` ('Roční ani jednorázovou platbu nelze platit kartou — zvolte bankovní převod.'); YEARLY + `rentalDays < 360` → violation ('Roční platba je dostupná pouze pro pronájem na 12 měsíců a déle.').

**`src/Form/OnboardingPaymentChoiceFormType.php`**: `paymentMethod` EnumType restricted to `[GOPAY, BANK_TRANSFER]` (no EXTERNAL); `paymentFrequency` — build the choices from a required `rental_days` form option: MONTHLY always; ONE_TIME iff `>= 31`; YEARLY iff `>= 360`. (Static — the window is fixed on the order, so no PRE_SET_DATA reconfiguration like `OrderFormType` needs.)

**`src/Twig/Components/OnboardingPaymentChoiceForm.php`** (`#[AsLiveComponent] final class … extends AbstractController`, `ComponentWithFormTrait`, `DefaultActionTrait`):
- `#[LiveProp] public string $token;` — resolve the order via `OrderRepository::findBySigningToken($token)` in a memoised getter; throw `NotFoundHttpException` if null / expired / `!canEditPaymentChoice()`.
- Build the form with `rental_days` option from `order.startDate.diff(order.endDate).days`.
- Expose helpers for the template: `rentalDays()`, `isCardEligible()` (`>= 31`), `isEligibleForFrequencyChoice()` (`>= 360`), and a `getSchedulePreview(): ?PaymentSchedule` that, once a method+frequency is chosen in the live form data, returns `priceCalculator->buildPaymentSchedule(order.storage, start, end, chosenFrequency)` for the live "Kalkulace" preview (mirror the OrderForm preview). Inject `PriceCalculator`, `OrderRepository`, `PlatformSettingsRepository` (bank surcharge display, reuse `getBankTransferSurchargeInCzk()` pattern).
- `#[LiveAction] public function submit(): ?RedirectResponse`: `submitForm()`; set `$formData->rentalDays` before validity check; if invalid return null; on valid, dispatch `ChooseOnboardingPaymentCommand(order, method, frequency)`; on success `return $this->redirectToRoute('public_customer_signing', ['token' => $this->token])`. Unwrap handler exceptions via `HandlerFailureUnwrap::unwrap()` and surface as a `submitError` LiveProp (mirror `AdminOnboardingForm`).

**`templates/components/OnboardingPaymentChoiceForm.html.twig`**: mirror `OrderForm.html.twig:248-362` — "Způsob platby" heading + green availability-guarantee note (always) + `form_widget(form.paymentMethod)` + card-ineligible amber hint (`< 31d`) + bank no-guarantee/surcharge amber note; then "Frekvence platby" (hidden for card = auto monthly, `{% do form.paymentFrequency.setRendered %}`) with the `−10 %` badge when `isEligibleForFrequencyChoice()`; then the live "Kalkulace plateb" preview (reuse the OrderForm schedule markup / `_price_*` partials). All prices **`vč. DPH`**. A single primary submit button "Pokračovat k podpisu" wired to `submit`. Use `data-model="on(change)|*"` so the card/frequency visibility + preview re-render on every field change (same as `OrderForm`).

### 9. Choice-step page controller

`src/Controller/Public/CustomerPaymentChoiceController.php` (single-action):

```php
#[Route('/podpis/{token}/zpusob-platby', name: 'public_customer_payment_choice', requirements: ['token' => '[a-f0-9]{64}'])]
```

- Resolve `order = orderRepository->findBySigningToken($token)`; null / expired → render `public/customer_signing_error.html.twig` (reuse the signing error page copy).
- If `!order->customerChoosesPayment` **or** `order->hasSignature()` → `redirectToRoute('public_customer_signing', ['token' => $token])` (not a deferred order, or already signed — nothing to choose).
- Otherwise render `templates/public/customer_payment_choice.html.twig` (page shell extending `user/layout.html.twig`, identification block + intro + `component('OnboardingPaymentChoiceForm', { token: token })`). The signing token IS the authorization (no login), mirroring `CustomerSigningController`.

The page allows re-entry after a first choice (until signed) so the customer can change their mind — `canEditPaymentChoice()` stays true while unsigned.

### 10. Signing controller + handler — route deferred orders to the choice step

`src/Controller/Public/CustomerSigningController.php` `__invoke()`: after the expired check `:52` and **before** `handlePost`/`renderForm`, add:

```php
if ($order->isAwaitingPaymentChoice()) {
    return $this->redirectToRoute('public_customer_payment_choice', ['token' => $token]);
}
```

(Applies to both GET and POST — a deferred order can neither be shown the signing form nor signed until the method is chosen.)

`src/Command/CustomerSignOnboardingHandler.php`: add a guard after the existing `canBePaid()` check `:42-44`:

```php
if ($order->isAwaitingPaymentChoice()) {
    throw new \DomainException('Order is awaiting a customer payment choice and cannot be signed yet.');
}
```

### 11. Signing e-mail — neutral copy while awaiting choice

`src/Service/Order/SigningEmailContent.php`: add `public bool $awaitingChoice = false` to the constructor (default false). In `fromOrder()` add an **early branch at the very top**:

```php
if ($order->isAwaitingPaymentChoice()) {
    return new self(
        situation: CustomerBillingSituation::GOPAY_FIRST_CHARGE, // pay-flow shell; price row suppressed below
        subject: 'Vyberte způsob platby a podepište smlouvu — pronájem skladu v '.$placeName,
        headline: 'Výběr platby a podpis smlouvy',
        nextStepLine: 'Nejprve si zvolíte způsob platby (kartou nebo bankovním převodem) a frekvenci, poté podepíšete smlouvu.',
        buttonLabel: 'Vybrat platbu a podepsat',
        paidThroughDate: null,
        monthlyPriceInHaler: 0,
        awaitingChoice: true,
    );
}
```

`templates/email/signing_link.html.twig`: gate the `gopay_first_charge` price row (`:145-148`) with `and not content.awaitingChoice`. The debt line (`:111`) stays (debt is known even when the rental method isn't). Everything else already reads `content.*`.

### 12. Admin-facing "čeká na volbu" indicator

While `order.isAwaitingPaymentChoice()` the concrete method/mode is null and the price is provisional — admin surfaces must say so rather than show a misleading "Automatická platba / X Kč".

- **`templates/admin/order/detail.html.twig`**: the "Způsob platby" `dd` (`:79`, `:322-323`) and the price `dd` (`:69`, `:345`) — when `order.isAwaitingPaymentChoice()` render a neutral badge/text **"Zákazník zvolí způsob a frekvenci platby"** instead of `order.billingMode.label()` / the price. Keep the existing rendering otherwise.
- **Admin orders list** (`templates/admin/order/...` list template referenced by `AdminOrderListController`): where the payment-method / price column renders, show a small amber chip **"Čeká na volbu platby"** when `order.isAwaitingPaymentChoice()`. (Find the list template + column via the list controller; do not invent a new column.)

No change needed to the operations hub or e-mail history — they key on order status, which is a normal `CREATED`/`RESERVED`. `paymentMethod` is already nullable across the codebase, so nothing else crashes; `firstPaymentPrice` stays non-null (provisional monthly). Verify the admin order **list** and **detail** render without error for a deferred order in an integration test (req 14).

### 13. COMPLIANCE.md

Add `templates/public/customer_payment_choice.html.twig` (+ its component) to the surfaces that must carry the **identification block** (Mekmann s.r.o., IČO, sídlo) and display **all prices `vč. DPH`**. Note explicitly: the choice page is a **selection** surface, not a charging surface — the card/3DS/GoPay **logos** are **not** required here (they appear later on the signing page + payment page, already gated to card). The dedicated recurring-payment consent still lives on the signing page (spec 072), unchanged — the choice step only picks method+frequency; consent is collected after.

### 14. Tests

- **Unit `tests/Unit/Entity/OrderTest.php`**: `markCustomerChoosesPayment()` → `isAwaitingPaymentChoice()` true while `paymentMethod` null, false once set; `canEditPaymentChoice()` true unsigned / false after `attachSignature`; `applyCustomerPaymentChoice()` sets all five fields + flips `isAwaitingPaymentChoice()` to false; throws when not deferred or already signed; passing a null VS clears a stale one.
- **Unit `tests/Unit/Form/OnboardingPaymentChoiceFormDataTest.php`** (new): the matrix — card+<31d, card+yearly, card+one_time, yearly+<360d all violate; card+6-month, bank+monthly, bank+yearly(≥360d), bank+one_time(≥31d) pass.
- **Unit `tests/Unit/Command/…`** or integration for `ChooseOnboardingPaymentHandler`: card + 6-month deferred order → `paymentMethod=GOPAY`, `billingMode=AUTO_RECURRING`, VS null, `firstPaymentPrice` = monthly ceník; bank + 12-month + yearly → `MANUAL_RECURRING`, VS assigned, `firstPaymentPrice` = yearly; guard throws on already-signed / non-deferred.
- **Integration `tests/Integration/Controller/CustomerPaymentChoiceControllerTest.php`** (new): deferred order → GET 200 renders method+frequency radios; non-deferred order token → 302 to signing; signed deferred order → 302 to signing; unknown/expired token → error page; POST valid choice via the Live Component locks the order and redirects to `/podpis/{token}`.
- **Integration `CustomerSigningControllerTest`** (extend): a deferred (awaiting-choice) order hitting `/podpis/{token}` (GET and POST) → 302 to `/podpis/{token}/zpusob-platby`; a non-deferred order is unaffected.
- **Integration `AdminOnboardingFormTest` / handler test**: submitting with `letCustomerChoosePayment` checked creates an order with `customerChoosesPayment=true`, null `paymentMethod`, `individualMonthlyAmount=null`, provisional MONTHLY frequency, non-null `firstPaymentPrice`, no VS; `letCustomerChoosePayment` + custom price / external prepaid / backdated start → validation errors; unchecked path unchanged (regression).
- **Integration**: admin order **detail** + **list** render for a deferred order and show the "Zákazník zvolí / Čeká na volbu platby" indicator (no crash).
- **Integration `SigningEmailContent` / e-mail**: a deferred order's signing e-mail has the neutral subject/button and **no** price row.
- `docker compose exec web composer quality` (phpstan L8 + cs + unit) **and** full `composer test` (controller/template/form changes) green.

## Acceptance

- [ ] Admin onboarding form shows an unchecked "Nechat vybrat zákazníka" checkbox. Unchecked → the form and created order are identical to today (regression test green).
- [ ] Checking it hides Platební metoda / Frekvence plateb / Cenový model / Externí předplatné (live), keeps Existující smlouva + Dluh, and shows the green "zákazník si zvolí…" note. A crafted POST with custom price / external prepaid / backdated start + the flag is rejected with the req-4 messages.
- [ ] A deferred onboarding creates an order with `customer_chooses_payment = true`, `payment_method = NULL`, `individual_monthly_amount = NULL`, provisional MONTHLY frequency and a non-null provisional `total_price`; no VS; still `CREATED`, still blocks the unit.
- [ ] The signing e-mail for a deferred order reads "Vyberte způsob platby a podepište smlouvu", has no locked price row, and the CTA opens `/podpis/{token}`.
- [ ] Opening `/podpis/{token}` for a deferred, unsigned order 302-redirects to `/podpis/{token}/zpusob-platby`; the page renders method (karta/převod) + frequency radios constrained by the rental length, a live price preview (`vč. DPH`), the green card guarantee note and the amber bank no-guarantee note, plus the Mekmann identification block. No card/3DS logos on this page.
- [ ] Submitting a valid choice locks `payment_method` / `payment_frequency` / `billing_mode` / recomputed `total_price` / VS (bank only) and redirects to `/podpis/{token}`, which now renders the **unchanged** signing page; signing → payment → completion all behave exactly as a normal admin onboarding of that method.
- [ ] A card choice on a 20-day rental, a card+yearly, a card+one-time, and a yearly on a <12-month rental are each rejected (inline on the component and by the handler guard). Bank + one-time (≥31d) and bank + yearly (≥360d) succeed.
- [ ] `/podpis/{token}/zpusob-platby` redirects to signing for a non-deferred order and for a signed deferred order; `CustomerSignOnboardingHandler` refuses to sign an awaiting-choice order.
- [ ] Admin order detail + list render a deferred order without error, showing "Zákazník zvolí… / Čeká na volbu platby" in place of a concrete method/price.
- [ ] Debt + "let customer choose" works: the chosen method drives both the debt page and the rental payment; EXTERNAL is never offered.
- [ ] `doctrine:schema:validate` clean; migrations-up-to-date CI green; `.claude/COMPLIANCE.md`, `PROJECT_MAP.md`, `BACKLOG.md` updated. `composer quality` + full `composer test` green.

## Out of scope

- **Making the signing page itself reactive.** The choice is a dedicated step *before* signing (user decision), so `customer_signing.html.twig` and its `computeContext`/`redirectAfterSigning` are untouched. Not converting the signing page to a Live Component.
- **Deferring the *price* (individuální/zdarma) or external prepayment to the customer.** "Let customer choose" is standard-ceník only, by decision — those stay admin-only knobs and are hidden when the box is checked.
- **Offering EXTERNAL to the customer.** External settlement is an admin-only, off-system concept (spec 086); the customer only ever picks card or bank.
- **Public order flow (`/objednavka`).** Already lets the customer choose; unchanged.
- **A per-place / global default for the checkbox.** It defaults unchecked every time; no persisted admin preference.
- **Reworking availability during the pending window.** The provisional `AUTO_RECURRING` open-ended block is accepted as conservative; not special-cased.
- **Editing the choice after signing**, or an admin override of a customer's choice — not built (admin can still cancel + re-onboard).
- **Storage.status / dashboard read-site churn** — provisional values are valid; no display beyond admin detail/list is adjusted.

## Open questions

None — proceed.
