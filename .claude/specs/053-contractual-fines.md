# 053 — Contractual fines (smluvní pokuty) — issue, track, and collect

**Status:** ready
**Type:** feature
**Scope:** large (~29 new files, ~12 modified)
**Depends on:** none

## Problem

The VOP (Section IV "Smluvní sankce") defines three contractual fines — dirty-storage return (6 000 Kč), non-return (2 000 Kč/day), and late payment (0.25 %/day, min 250 Kč/day). The system has zero infrastructure for issuing, tracking, or collecting these fines. Admins have no way to create a fine, customers have no way to pay one, and there is no audit trail. The only debt-like mechanism is `Contract.outstandingDebtAmount` (unpaid rental days at termination) and `Order.onboardingDebtInHaler` (spec 051), neither of which covers contractual penalties.

## Goal

Admin can issue a fine against any contract (active or terminated), choosing from predefined VOP types (with auto-calculated amounts) or a free-form custom type. The customer receives an email with a payment link and sees unpaid fines on `/stav` and the portal order detail. Payment works via GoPay (redirect to gateway) or bank transfer (QR code with unique variable symbol). The system auto-matches bank transfers via FIO polling. Everything is auditable: who issued it, when, why, when/how it was paid or cancelled.

## Context (current state)

### Existing debt infrastructure (reusable patterns)
- **Onboarding debt** (spec 051): `Order.onboardingDebtInHaler` + `debtPaidAt` + `debtGoPayPaymentId`. Dedicated payment page at `/objednavka/{id}/platba/dluh` (`OrderDebtPaymentController`). GoPay one-off via `GoPayClient::createOneTimeCharge()`. Bank transfer QR via `QrPaymentGenerator::generateDataUri()`. Webhook reconciliation in `ProcessPaymentNotificationHandler` (finds by `debtGoPayPaymentId`). FIO matching in `ProcessFioTransactionsCommand` (matches by `Order.variableSymbol`).
- **Manual billing** (spec 036): `ManualPaymentRequest` entity with `goPayPaymentId` + `goPayGatewayUrl` + per-stage sent timestamps. Per-cycle cron reminders. Webhook reconciliation branch.
- **Outstanding debt**: `Contract.outstandingDebtAmount` set at termination — display-only, no payment mechanism.

### Key files to reference
- `src/Service/GoPay/GoPayClient.php` — `createOneTimeCharge(int $amountInHaler, string $currency, string $orderNumber, string $description, string $email, string $notificationUrl, string $returnUrl): GoPayPayment`
- `src/Service/Payment/QrPaymentGenerator.php` — `generateDataUri(string $variableSymbol, int $amountInHaler, ?\DateTimeImmutable $dueDate = null): string`
- `src/Service/Payment/VariableSymbolGenerator.php` — `generate(Order $order): string` (CRC32 of UUID, checks `OrderRepository` for uniqueness)
- `src/Command/ProcessPaymentNotificationHandler.php` — webhook dispatcher, branches for order/debt/manual/recurring payments
- `src/Console/ProcessFioTransactionsCommand.php` — FIO bank polling + auto-matching by variable symbol
- `src/Service/Order/OrderStatusViewModelFactory.php` — builds the `/stav` page view model
- `src/Service/Operations/OperationsAlertsBuilder.php` — builds alert sections for admin operations hub
- `src/Controller/Public/OrderDebtPaymentController.php` — debt payment page (blueprint for fine payment page)
- `templates/public/order_debt_payment.html.twig` — debt payment template (blueprint)
- `templates/portal/admin/order/detail.html.twig` — admin order detail (add fines panel)
- `templates/public/order_status.html.twig` — public `/stav` page (add fines banner)
- `templates/portal/user/order/detail.html.twig` — customer portal order detail (add fines section)

### VOP fine definitions (source of truth for predefined types)
From contract template Section IV "Smluvní sankce":
1. **Znečištění** — 6 000 Kč fixed. "Pokud Nájemce Předmět nájmu vrátí znečištěný…"
2. **Nevrácení** — 2 000 Kč/day. "Pokud Nájemce Předmět nájmu nevrátí nejpozději ke dni skončení nájmu…"
3. **Prodlení s úhradou** — 0.25 %/day of overdue amount, minimum 250 Kč/day. "V případě prodlení Nájemce s úhradou jakéhokoliv peněžitého závazku…"

## Architecture

```
Admin issues fine ──→ IssueFineCommand ──→ IssueFineHandler
    │                                         │
    │                                         ├─ creates Fine entity (with VS)
    │                                         ├─ records FineIssued event
    │                                         └─ middleware flushes
    │
    │                                    FineIssued event
    │                                         │
    │                                    SendFineIssuedEmailHandler
    │                                         │
    │                                         └─ email to customer with signed payment URL
    │
    ▼
Customer clicks payment link ──→ FinePaymentController (UriSigner-protected)
    │
    ├─ "Zaplatit kartou" ──→ FinePaymentInitiateController
    │       │                    │
    │       │                    └─ GoPayClient::createOneTimeCharge()
    │       │                         │
    │       │                         └─ redirect to GoPay gateway
    │       │
    │       └─ GoPay webhook ──→ ProcessPaymentNotificationHandler (new branch)
    │              │
    │              └─ Fine::markPaid() → FinePaid event → confirmation email
    │
    └─ Bank transfer ──→ customer pays manually
            │
            └─ ProcessFioTransactionsCommand (new branch)
                   │
                   └─ matches Fine.variableSymbol → auto-confirms
```

## Requirements

### 1. `FineType` enum (`src/Enum/FineType.php`)

```php
enum FineType: string
{
    case DIRTY_STORAGE = 'dirty_storage';
    case NON_RETURN = 'non_return';
    case LATE_PAYMENT = 'late_payment';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DIRTY_STORAGE => 'Znečištění skladovací jednotky',
            self::NON_RETURN => 'Nevrácení skladovací jednotky',
            self::LATE_PAYMENT => 'Prodlení s úhradou',
            self::OTHER => 'Jiná pokuta',
        };
    }

    public function defaultAmountInHaler(): ?int
    {
        return match ($this) {
            self::DIRTY_STORAGE => 600_000,
            default => null, // calculated or manual
        };
    }
}
```

### 2. `Fine` entity (`src/Entity/Fine.php`)

Records domain events via `EntityWithEvents` + `HasEvents`.

```php
#[ORM\Entity]
class Fine implements EntityWithEvents
{
    use HasEvents;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private(set) Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private(set) Contract $contract;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private(set) User $user;                    // denormalized tenant

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private(set) User $issuedBy;                // admin

    #[ORM\Column(type: Types::STRING, enumType: FineType::class)]
    private(set) FineType $type;

    #[ORM\Column]
    private(set) int $amountInHaler;

    #[ORM\Column(type: Types::TEXT)]
    private(set) string $description;           // admin note visible to customer

    #[ORM\Column(length: 10, unique: true, nullable: true)]
    private(set) ?string $variableSymbol = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?string $goPayPaymentId = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?string $goPayGatewayUrl = null;

    #[ORM\Column]
    private(set) \DateTimeImmutable $issuedAt;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?User $cancelledBy = null; // ManyToOne

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $reminder1SentAt = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $reminder2SentAt = null;

    #[ORM\Column]
    private(set) \DateTimeImmutable $createdAt;
}
```

Key methods:
- `getAmountInCzk(): float` — `$this->amountInHaler / 100`
- `isPaid(): bool` — `$this->paidAt !== null`
- `isCancelled(): bool` — `$this->cancelledAt !== null`
- `isPayable(): bool` — `!$this->isPaid() && !$this->isCancelled()`
- `markPaid(\DateTimeImmutable $now): void` — sets `paidAt`, records `FinePaid` event
- `cancel(User $cancelledBy, \DateTimeImmutable $now): void` — sets `cancelledAt` + `cancelledBy`
- `assignVariableSymbol(string $vs): void`
- `setGoPayPayment(string $paymentId, string $gatewayUrl): void`
- `markReminder1Sent(\DateTimeImmutable $now): void`
- `markReminder2Sent(\DateTimeImmutable $now): void`

Constructor records `FineIssued` event.

### 3. `FineRepository` (`src/Repository/FineRepository.php`)

Standard composition pattern (EntityManager, no flush).

```php
save(Fine $fine): void
findById(Uuid $id): ?Fine
findByVariableSymbol(string $vs): ?Fine
findByGoPayPaymentId(string $paymentId): ?Fine
findByContract(Contract $contract): array           // all fines for a contract
findUnpaidByUser(User $user): array                 // all unpaid, non-cancelled fines
findUnpaidForReminder(int $daysSinceIssued, bool $isFirstReminder): array
countUnpaid(): int                                   // for admin badge
findAllFiltered(?string $status, ?string $search, int $page): Paginator
```

### 4. Domain events

**`FineIssued`** (`src/Event/FineIssued.php`):
```php
final readonly class FineIssued
{
    public function __construct(
        public Uuid $fineId,
        public Uuid $contractId,
        public Uuid $userId,
        public FineType $type,
        public int $amountInHaler,
        public \DateTimeImmutable $occurredOn,
    ) {}
}
```

**`FinePaid`** (`src/Event/FinePaid.php`):
```php
final readonly class FinePaid
{
    public function __construct(
        public Uuid $fineId,
        public Uuid $contractId,
        public Uuid $userId,
        public int $amountInHaler,
        public \DateTimeImmutable $occurredOn,
    ) {}
}
```

### 5. `IssueFineCommand` + handler

```php
final readonly class IssueFineCommand
{
    public function __construct(
        public Uuid $contractId,
        public FineType $type,
        public int $amountInHaler,
        public string $description,
        public Uuid $issuedById,    // admin user ID
    ) {}
}
```

Handler:
1. Load contract via `EntityManager::find()`
2. Load user from `contract.order.user`
3. Load admin from `issuedById`
4. Create `Fine` entity (ID via `ProvideIdentity`)
5. Generate variable symbol via `VariableSymbolGenerator::generateForFine(Uuid $fineId)` (see §10)
6. `FineRepository::save()`

### 6. `IssueFineCommand` validation + admin form

**`FineFormData`** (`src/Form/FineFormData.php`):
```php
final class FineFormData
{
    #[Assert\NotNull]
    public ?FineType $type = null;

    #[Assert\NotBlank]
    #[Assert\Positive]
    public ?int $amountInHaler = null;

    // Auxiliary fields for auto-calculation (not persisted)
    public ?int $nonReturnDays = null;          // for NON_RETURN: 2000 × days
    public ?int $latePaymentBaseInHaler = null;  // for LATE_PAYMENT: base amount
    public ?int $latePaymentDays = null;         // for LATE_PAYMENT: number of days

    #[Assert\NotBlank]
    #[Assert\Length(max: 2000)]
    public string $description = '';
}
```

**`FineFormType`** (`src/Form/FineFormType.php`):
- `type`: `EnumType` with `FineType` choices
- `amountInHaler`: `MoneyType` or `IntegerType` (display as CZK with /100 conversion in template)
- `nonReturnDays`: `IntegerType`, shown only when `type = NON_RETURN`
- `latePaymentBaseInHaler` + `latePaymentDays`: shown only when `type = LATE_PAYMENT`
- `description`: `TextareaType`

**Client-side JS** — new `assets/controllers/fine_form_controller.js` (Stimulus):
- On `type` change: show/hide auxiliary fields, auto-fill `amountInHaler`:
  - `DIRTY_STORAGE` → set to 600 000 (6 000 Kč), hide aux fields
  - `NON_RETURN` → show `nonReturnDays`, on days change: set amount to `200_000 × days`
  - `LATE_PAYMENT` → show `latePaymentBaseInHaler` + `latePaymentDays`, on change: set amount to `max(base × 0.0025 × days, 25_000 × days)`
  - `OTHER` → clear amount, hide aux fields
- Amount field always remains editable (admin can override)

### 7. Admin controllers + templates

**`AdminFineCreateController`** (`src/Controller/Portal/Admin/AdminFineCreateController.php`):
- Route: `GET|POST /portal/admin/pokuty/vytvorit/{contractId}`
- Security: `ROLE_ADMIN`
- Loads contract, validates it exists
- Renders form; on submit dispatches `IssueFineCommand`
- Redirects to admin order detail with flash "Pokuta vystavena"

**`AdminFineCancelController`** (`src/Controller/Portal/Admin/AdminFineCancelController.php`):
- Route: `POST /portal/admin/pokuty/{id}/zrusit`
- Security: `ROLE_ADMIN`
- Dispatches `CancelFineCommand` (marks fine as cancelled by admin)
- Redirects back to order detail with flash "Pokuta zrušena"

**`CancelFineCommand`** + handler:
```php
final readonly class CancelFineCommand
{
    public function __construct(
        public Uuid $fineId,
        public Uuid $cancelledById,
    ) {}
}
```

**`AdminFineListController`** (`src/Controller/Portal/Admin/AdminFineListController.php`):
- Route: `GET /portal/admin/pokuty`
- Security: `ROLE_ADMIN`
- Paginated list with filters: status (all / unpaid / paid / cancelled), text search (user name/email)
- Columns: Datum, Zákazník (linked), Smlouva, Typ, Částka, Stav (badge), Způsob platby (see below), Vystavil, Akce (detail link)
- **Způsob platby column** (only for paid fines): "GoPay ({goPayPaymentId})" or "Bankovní převod (VS {variableSymbol})" — lets admin trace exactly which payment settled the fine

**`AdminFineExportController`** (`src/Controller/Portal/Admin/AdminFineExportController.php`):
- Route: `GET /portal/admin/pokuty/export`
- Reuses `ExcelExporter` pattern from spec 028

**Admin order detail modification** (`templates/portal/admin/order/detail.html.twig`):
- New "Smluvní pokuty" panel after the existing "Dluh" panel (if contract exists)
- Shows table of fines: Datum | Typ | Částka | Stav (badge) | Způsob platby | Poznámka (truncated) | Akce (cancel button for unpaid)
- **Způsob platby** (paid fines only): "Kartou (GoPay)" when `goPayPaymentId` is set, "Převodem (VS {variableSymbol})" when paid via bank transfer (goPayPaymentId is null but paidAt is set). Unpaid/cancelled → empty.
- "Vystavit pokutu" button linking to `AdminFineCreateController`
- Panel hidden when no contract on the order

**`AdminOrderDetailController`** modification:
- Pass `fines: FineRepository::findByContract($contract)` to template (only when contract exists)

### 8. Customer-facing visibility

**Public `/stav` page** (`templates/public/order_status.html.twig`):
- New "Smluvní pokuty" section below the debt banner area (when unpaid fines exist)
- Red alert banner per unpaid fine: "Smluvní pokuta — {type label}: {amount} Kč" + description snippet + "Zaplatit" button (signed URL to fine payment page)
- Paid fines shown in a collapsed "Zaplacené pokuty" list (green badges)
- Cancelled fines not shown

**`OrderStatusViewModelFactory`** modification:
- Add `unpaidFines: array` and `paidFines: array` (from `FineRepository::findByContract()`)
- Each fine VO carries: `id`, `type`, `typeLabel`, `amountInCzk`, `description`, `issuedAt`, `paidAt`, `paymentUrl` (signed)
- New `FinePaymentUrlGenerator::generatePaymentUrl(Fine): string` (UriSigner pattern, mirrors `HandoverUrlGenerator`)

**Portal order detail** (`templates/portal/user/order/detail.html.twig`):
- New "Smluvní pokuty" section (similar layout to `/stav`)
- Unpaid fines link to the signed public payment URL (so it works even for passwordless customers)

**Timeline integration** on `/stav`:
- `FineIssued` → timeline entry: "Vystavena smluvní pokuta: {type label} ({amount} Kč)"
- `FinePaid` → timeline entry: "Smluvní pokuta zaplacena: {type label} ({amount} Kč)"

### 9. Fine payment page + controllers

**`FinePaymentController`** (`src/Controller/Public/FinePaymentController.php`):
- Route: `GET /pokuta/{id}/platba`
- UriSigner-protected (signed URL from email / `/stav`)
- Validates fine exists and `isPayable()`
- Renders: fine details (type, amount, description, issued date, contract info) + two payment options:
  1. **Karta**: "Zaplatit kartou ({amount} Kč)" button → POST to initiate controller
  2. **Bankovní převod**: account number (2603478520/2010), VS, amount, QR code via `QrPaymentGenerator`

Template: `templates/public/fine_payment.html.twig` — structured like `order_debt_payment.html.twig` but shows both payment methods.

**`FinePaymentInitiateController`** (`src/Controller/Public/FinePaymentInitiateController.php`):
- Route: `POST /pokuta/{id}/platba/iniciovat`
- UriSigner check on referrer or token
- Dispatches `InitiateFinePaymentCommand`:
  - Handler calls `GoPayClient::createOneTimeCharge()` with:
    - amount: `fine.amountInHaler`
    - orderNumber: `"FINE-{fine.id.toRfc4122()}"`
    - description: `"Smluvní pokuta - {fine.type.label()}"`
    - email: `fine.user.email`
    - notificationUrl: `/webhook/gopay`
    - returnUrl: `/pokuta/{id}/platba/navrat?_hash=...`
  - Stores `goPayPaymentId` + `goPayGatewayUrl` on Fine entity
  - Returns redirect to `gwUrl`

**`FinePaymentReturnController`** (`src/Controller/Public/FinePaymentReturnController.php`):
- Route: `GET /pokuta/{id}/platba/navrat`
- UriSigner-protected
- Checks fine payment status (if already paid → redirect to `/stav` with success flash)
- If pending → redirect to `/stav` with info flash ("Platba se zpracovává")

### 10. Variable symbol generation

**Extend `VariableSymbolGenerator`**:
- Add `FineRepository` as constructor dependency
- New method: `generateForFine(Uuid $fineId): string`
  - Same CRC32 algorithm as `generate(Order)`
  - Uniqueness check against BOTH `OrderRepository::findByVariableSymbol()` AND `FineRepository::findByVariableSymbol()`
- Modify existing `generate(Order)` to also check `FineRepository` (prevent cross-table collision)

### 11. Webhook integration (`ProcessPaymentNotificationHandler`)

Add a new branch after the existing debt-payment branch. The association chain is: GoPay sends webhook with `paymentId` → handler queries `FineRepository::findByGoPayPaymentId()` → finds the exact fine that initiated this charge → marks it paid. The `Fine.goPayPaymentId` was stored at initiation (§9), so the link is 1:1 and unambiguous.

```php
// Try fine payment
$fine = $this->fineRepository->findByGoPayPaymentId($goPayPaymentId);
if ($fine !== null) {
    if ($status->isPaid()) {
        $fine->markPaid($this->clock->now());
    }
    return;
}
```

### 12. FIO bank transfer matching (`ProcessFioTransactionsCommand`)

After attempting Order match by variable symbol, add a Fine match branch. The association chain is: each Fine gets a unique variable symbol at creation (§5+§10) → customer includes that VS in their bank transfer → FIO cron polls the bank account → matches incoming transaction's VS against `FineRepository::findByVariableSymbol()` → links `BankTransaction.pairedFine` to the fine → marks fine paid. The VS is unique across both Order and Fine tables (§10 enforces cross-table uniqueness), so there is no ambiguity.

```php
$fine = $this->fineRepository->findByVariableSymbol($transaction->variableSymbol);
if ($fine !== null && $fine->isPayable()) {
    if ($transaction->amount === $fine->amountInHaler) {
        $fine->markPaid($this->clock->now());
        $bankTransaction->matchToFine($fine, 'auto_via_variable_symbol');
    } else {
        $bankTransaction->markAmountMismatch();
    }
    // ... persist
}
```

`BankTransaction` entity gains a new nullable `pairedFine` ManyToOne relation (alongside existing `pairedOrder` / `pairedContract`). Admin "Bankovní platby" page already shows paired entity — extend to display "Pokuta: {type label} ({amount} Kč)" when `pairedFine` is set.

### 13. Email notifications

**`SendFineIssuedEmailHandler`** (`src/Event/SendFineIssuedEmailHandler.php`):
- Handles `FineIssued` event
- Email to `fine.user.email`
- Subject: `Smluvní pokuta — {type label} — Fajnesklady.cz`
- Body: fine type, amount, description (admin note), contract/storage reference, signed payment link, bank transfer details (account + VS)
- Template: `templates/email/fine_issued.html.twig`

**`SendFinePaidEmailHandler`** (`src/Event/SendFinePaidEmailHandler.php`):
- Handles `FinePaid` event
- Email to `fine.user.email`
- Subject: `Pokuta zaplacena — Fajnesklady.cz`
- Body: confirmation with amount, type, payment date
- Template: `templates/email/fine_paid.html.twig`

**`SendFineIssuedAdminNotificationHandler`** — NOT needed (admin issued it themselves).

### 14. Payment reminder cron

**`SendFinePaymentRemindersCommand`** (`src/Console/SendFinePaymentRemindersCommand.php`):
- Command: `app:send-fine-payment-reminders`
- Schedule: daily
- Logic:
  1. Find unpaid, non-cancelled fines where `issuedAt + 7 days ≤ now` AND `reminder1SentAt IS NULL` → dispatch `FinePaymentReminderRequested` event (stage 1)  → set `reminder1SentAt`
  2. Find unpaid fines where `issuedAt + 14 days ≤ now` AND `reminder2SentAt IS NULL` → dispatch same event (stage 2) → set `reminder2SentAt`
- Flush after each fine (console command — no middleware)

**`FinePaymentReminderRequested`** event + **`SendFinePaymentReminderEmailHandler`**:
- Email with same content as `fine_issued` but with urgency framing: "Připomínka nezaplacené pokuty"
- Template: `templates/email/fine_payment_reminder.html.twig`

### 15. Operations hub integration

**`OperationsAlertsBuilder`** modification:
- New section: "Nezaplacené pokuty" with count
- Shows unpaid fines older than 7 days (the concerning ones)
- Links to admin fine list page filtered by `status=unpaid`

**Admin sidebar** — add "Pokuty" nav entry with red count badge (unpaid count) via new `FineExtension` Twig extension (mirrors `OverdueExtension` / `OperationsExtension` pattern): `fines_unpaid_count()`.

### 16. Migration

Generate via `make:migration`. Creates:
- `fine` table with all columns, indexes on `contract_id`, `user_id`, unique on `variable_symbol`
- Adds `paired_fine_id` nullable FK on `bank_transaction`

## Acceptance

- [ ] Admin can create a fine on any order with a contract (active or terminated)
- [ ] Predefined types auto-fill amounts: DIRTY_STORAGE → 6 000 Kč; NON_RETURN → 2 000 Kč × entered days; LATE_PAYMENT → max(base × 0.0025 × days, 250 Kč × days)
- [ ] Amount is always editable by admin
- [ ] Admin-entered description/note is stored and visible to customer
- [ ] Customer receives email with fine details + signed payment link
- [ ] `/stav` page shows unpaid fines with payment CTA
- [ ] Portal order detail shows fines section
- [ ] Fine payment page offers both card (GoPay redirect) and bank transfer (QR code)
- [ ] GoPay payment completion (webhook) marks fine as paid
- [ ] FIO cron auto-matches bank transfer by variable symbol and marks fine as paid
- [ ] Customer receives payment confirmation email
- [ ] Admin can cancel an unpaid fine
- [ ] Admin fines list page (`/portal/admin/pokuty`) with status filter + Excel export
- [ ] Admin order detail shows fines panel with issue/cancel actions
- [ ] D+7 and D+14 reminder emails sent for unpaid fines
- [ ] Operations hub shows "Nezaplacené pokuty" section
- [ ] Admin sidebar shows "Pokuty" with unpaid count badge
- [ ] Fine issuance and payment appear in `/stav` timeline
- [ ] `composer quality` green
- [ ] Run `composer test` (full suite) — no regressions

## Out of scope

- **Fakturoid invoice generation for fines** — fines are contractual penalties, not service charges; accounting treatment is a separate concern. Admin exports the fine list for their accountant.
- **Automatic daily fine calculation** (e.g., auto-accruing late-payment penalty daily) — admin manually calculates and enters the total. Auto-accrual would need a spec of its own (complex edge cases: partial payments, dispute, caps).
- **Customer fine dispute mechanism** — disputes are handled offline between admin and customer. Admin cancels the fine if resolved.
- **Deposit (jistota) management** — VOP Section VII defines deposit rules; a deposit entity + flows would be a separate feature.
- **Blocking new orders for users with unpaid fines** — per user decision: visibility only, no blocking.
- **Landlord fine issuance** — admin-only for now. Landlords can request admin to issue fines.
- **Bulk fine operations** — issue fines one at a time.
- **Fine payment via existing recurring payment token** — fines are always one-off; no token reuse.

## Open questions

None — proceed.
