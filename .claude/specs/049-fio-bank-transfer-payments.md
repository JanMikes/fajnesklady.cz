# 049 — Add FIO bank transfer as a payment method

**Status:** done
**Type:** feature
**Scope:** large (~45 files: 8 entities, 5 services, 4 controllers, 3 console commands, 6 templates, 5 email templates, 8 form/handler changes, tests)
**Depends on:** none

## Problem

The platform only supports GoPay (card) payments. Customers who prefer bank transfers have no self-service option — admin must manually mark orders as externally paid. There's no automated reconciliation, no QR codes for mobile banking convenience, and no visibility into which payments arrived vs. which are outstanding. The operator wants to encourage GoPay (lower operational overhead) by adding a monthly surcharge to bank transfers, while still offering the option for customers who won't use cards.

## Goal

Customers can choose "Bankovní převod" during the order flow. They see bank account details, a unique variable symbol, and a QR code (SPD standard). A cron job polls FIO banka API every 5 minutes, auto-matches incoming payments by variable symbol, and triggers the same "order paid" / "recurring charge received" events as GoPay. Admin gets a dedicated "Bankovní platby" page showing all transactions (matched + unmatched). *(Correction: manual pairing was deferred by spec 078 and never shipped with this spec — it was delivered by spec 091.)* A configurable monthly surcharge (default 100 CZK) is added to bank transfer orders to incentivize GoPay usage.

## Context (current state)

- `src/Enum/PaymentMethod.php` — two cases: `GOPAY`, `EXTERNAL`
- `src/Entity/Order.php:88` — `paymentMethod` column, nullable `PaymentMethod` enum
- `src/Entity/Order.php:98` — `billingMode` column (`BillingMode` enum: ONE_TIME / AUTO_RECURRING / MANUAL_RECURRING)
- `src/Command/InitiatePaymentHandler.php` — branches on billingMode, always calls GoPayClient
- `src/Command/DispatchManualBillingNotificationHandler.php` — sends GoPay one-time links for MANUAL_RECURRING cycles
- `src/Service/GoPay/GoPayClient.php` — interface for GoPay operations
- `src/Command/ProcessPaymentNotificationHandler.php` — reconciles GoPay webhooks, handles order/recurring/manual-billing paths
- `src/Service/OrderService.php:150` — `confirmPayment()` transitions order to PAID
- `src/Service/OrderService.php:163` — `completeOrder()` creates Contract
- `templates/public/order_payment.html.twig` — payment page, currently GoPay-only inline gateway
- `templates/components/OrderForm.html.twig:296-302` — billingMode radio rendered for recurring-eligible orders
- `src/Form/AdminCreateOnboardingFormType.php:136` — payment method choices (EXTERNAL/GOPAY)
- `src/Form/AdminCreateOnboardingFormData.php:79` — `$paymentMethod` field
- `src/Entity/Payment.php` — records completed payments; `goPayPaymentId` nullable unique column
- `src/Entity/ManualPaymentRequest.php` — per-cycle tracking for MANUAL_RECURRING; has `goPayPaymentId` + `goPayGatewayUrl`
- `src/Service/PriceCalculator.php` — calculates pricing; no surcharge concept exists
- `templates/portal/layout.html.twig:93-155` — admin sidebar nav (between "Onboarding" and "Historie změn" is natural spot)
- Bank account: `2603478520/2010` (FIO banka)

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│ Customer Order Flow                                              │
│                                                                  │
│  OrderForm ──► choose BANK_TRANSFER ──► surcharge added         │
│       │                                                          │
│       ▼                                                          │
│  /platba page ──► shows bank details + QR + VS                  │
│       │              (no GoPay gateway)                          │
│       ▼                                                          │
│  Confirmation email with same info                               │
└──────────────────────────────────┬──────────────────────────────┘
                                   │
┌──────────────────────────────────▼──────────────────────────────┐
│ FIO Reconciliation (cron every 5 min)                            │
│                                                                  │
│  FioClient::downloadSince(lastCheckpoint)                        │
│       │                                                          │
│       ▼                                                          │
│  For each credit transaction:                                    │
│    1. Store as BankTransaction entity                            │
│    2. Match by variableSymbol → Order/Contract                   │
│    3. If no VS match → try BankAccountMapping (sender acct)      │
│    4. If matched: dispatch same events as GoPay webhook          │
│       (OrderPaid / RecurringPaymentCharged / amount mismatch)    │
│    5. If unmatched: flag for admin                               │
└──────────────────────────────────┬──────────────────────────────┘
                                   │
┌──────────────────────────────────▼──────────────────────────────┐
│ Admin "Bankovní platby" page                                     │
│                                                                  │
│  • All transactions list (filter: matched / unmatched / mismatch)│
│  • "Párovat k objednávce" action on unmatched rows               │
│  • Creates BankAccountMapping for future auto-match              │
│  • Order detail: "Bankovní platby" tab shows received payments   │
└─────────────────────────────────────────────────────────────────┘
```

## Requirements

### 1. New packages

```bash
composer require mhujer/fio-api-php rikudou/czqrpayment endroid/qr-code
```

- `mhujer/fio-api-php` ^5.0 — FIO API client (30s rate limit between calls per token)
- `rikudou/czqrpayment` ^5.3 — Czech QR payment (SPD) string generation
- `endroid/qr-code` — QR code image rendering (PNG/data-uri)

### 2. Environment variable

```dotenv
FIO_API_TOKEN=  # 64-char token from FIO ebanking settings
```

Add to `.env` (empty default), `.env.test` (empty/mock).

### 3. PaymentMethod enum extension

```php
// src/Enum/PaymentMethod.php
enum PaymentMethod: string
{
    case GOPAY = 'gopay';
    case EXTERNAL = 'external';
    case BANK_TRANSFER = 'bank_transfer';
}
```

### 4. Variable symbol on Order

New column `Order.variableSymbol` — `string(10)`, nullable, unique index.

```php
// src/Entity/Order.php — new column
#[ORM\Column(length: 10, nullable: true, unique: true)]
public private(set) ?string $variableSymbol = null;
```

**Generation rules (Czech bank transfer VS constraints):**
- Exactly 10 numeric digits (zero-padded)
- Max value: 9999999999
- Generated deterministically from order UUID via CRC32 + collision retry
- Assigned only when `paymentMethod === BANK_TRANSFER`

New method:
```php
public function assignVariableSymbol(string $variableSymbol): void
{
    $this->variableSymbol = $variableSymbol;
}
```

### 5. VariableSymbolGenerator service

New `src/Service/Payment/VariableSymbolGenerator.php`:

```php
final readonly class VariableSymbolGenerator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function generate(Uuid $orderId): string
    {
        // CRC32 of UUID string → 10-digit zero-padded
        // On collision (unique constraint), append attempt counter to input and rehash
        // Max 10 attempts before throwing
        $base = $orderId->toRfc4122();
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $input = $attempt === 0 ? $base : $base . '-' . $attempt;
            $hash = crc32($input);
            $vs = str_pad((string) abs($hash % 10_000_000_000), 10, '0', STR_PAD_LEFT);

            $exists = $this->entityManager->createQueryBuilder()
                ->select('1')
                ->from(Order::class, 'o')
                ->where('o.variableSymbol = :vs')
                ->setParameter('vs', $vs)
                ->getQuery()
                ->getOneOrNullResult();

            if (null === $exists) {
                return $vs;
            }
        }

        throw new \RuntimeException('Cannot generate unique variable symbol after 10 attempts');
    }
}
```

### 6. PlatformSettings entity (singleton)

New `src/Entity/PlatformSettings.php`:

```php
#[ORM\Entity]
class PlatformSettings
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private(set) Uuid $id;

    /** Bank transfer monthly surcharge in haléře (CZK × 100). Default 10 000 = 100 CZK. */
    #[ORM\Column(options: ['default' => 10_000])]
    public private(set) int $bankTransferSurchargeInHaler = 10_000;

    #[ORM\Column]
    public private(set) \DateTimeImmutable $updatedAt;
}
```

New `src/Repository/PlatformSettingsRepository.php` with `getSettings(): PlatformSettings` (fetches the singleton row; creates it with defaults if missing via an internal flush — documented exception like `EmailLogRepository`).

Admin page: `/portal/admin/nastaveni` → `AdminSettingsController` + `PlatformSettingsFormType` + `PlatformSettingsFormData`. Single field: "Příplatek za bankovní převod (Kč/měsíc)" with NumberType, scale 0.

### 7. BankTransaction entity

New `src/Entity/BankTransaction.php` — stores every incoming credit transaction from FIO:

```php
#[ORM\Entity]
#[ORM\UniqueConstraint(fields: ['fioTransactionId'])]
class BankTransaction
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private(set) Uuid $id;

    /** FIO's internal transaction ID (idempotency key) */
    #[ORM\Column(length: 50, unique: true)]
    private(set) string $fioTransactionId;

    #[ORM\Column]
    private(set) int $amount; // haléře

    #[ORM\Column(length: 3)]
    private(set) string $currency;

    #[ORM\Column(length: 10, nullable: true)]
    private(set) ?string $variableSymbol;

    #[ORM\Column(length: 50, nullable: true)]
    private(set) ?string $senderAccountNumber; // "123456/0800"

    #[ORM\Column(length: 255, nullable: true)]
    private(set) ?string $senderName;

    #[ORM\Column]
    private(set) \DateTimeImmutable $transactionDate;

    #[ORM\Column(length: 500, nullable: true)]
    private(set) ?string $comment;

    /** NULL = unmatched, set when paired to an order */
    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(nullable: true)]
    public private(set) ?Order $pairedOrder = null;

    /** NULL = unmatched, set when paired to a contract (recurring) */
    #[ORM\ManyToOne(targetEntity: Contract::class)]
    #[ORM\JoinColumn(nullable: true)]
    public private(set) ?Contract $pairedContract = null;

    #[ORM\Column(length: 20, options: ['default' => 'unmatched'])]
    public private(set) string $status = 'unmatched'; // unmatched | matched | amount_mismatch | ignored

    /** Admin who manually paired this transaction */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    public private(set) ?User $pairedBy = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $pairedAt = null;

    #[ORM\Column]
    private(set) \DateTimeImmutable $createdAt;
}
```

Methods: `pairToOrder(Order, ?User, DateTimeImmutable)`, `pairToContract(Contract, ?User, DateTimeImmutable)`, `markIgnored(User, DateTimeImmutable)`.

### 8. BankAccountMapping entity

New `src/Entity/BankAccountMapping.php` — maps a sender bank account number to a specific order/contract. Purpose: when a customer pays without a variable symbol (or forgets it), but we know their bank account from a previous manual pairing, the FIO cron auto-matches future payments from that account to the correct order. This eliminates repeated manual pairing for the same customer.

```php
#[ORM\Entity]
#[ORM\UniqueConstraint(fields: ['accountNumber', 'order'])]
class BankAccountMapping
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private(set) Uuid $id;

    /** Normalized sender account "123456/0800" */
    #[ORM\Column(length: 50)]
    private(set) string $accountNumber;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private(set) User $user;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(nullable: false)]
    private(set) Order $order;

    /** Admin who created this mapping */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private(set) User $createdBy;

    #[ORM\Column]
    private(set) \DateTimeImmutable $createdAt;
}
```

**Lifecycle:**
1. Admin pairs an unmatched transaction to an order
2. Admin is prompted: "Přiřadit účet odesílatele k objednávce pro budoucí platby?" (checkbox, default checked)
3. If confirmed: `BankAccountMapping` is created linking the sender account → order
4. Next time the FIO cron sees a credit from the same account (with or without VS), it auto-matches to this order's active contract
5. If the order/contract is terminated/cancelled, the mapping is still valid for the last-known context (admin can delete it from the mapping management list)

**Account-to-order matching precedence in FIO cron (§14):**
1. Variable symbol match (strongest — deterministic)
2. BankAccountMapping match (fallback — admin-confirmed association)
3. Unmatched (needs manual intervention)

If a transaction has BOTH a valid VS AND matches a BankAccountMapping for a DIFFERENT order, the VS match wins (the customer may have corrected their payment). This is logged as an audit event.

**Admin can manage mappings** from the admin order detail panel ("Přiřazené účty" sub-section) — view existing mappings, delete obsolete ones. Deletion is audited.

### 9. FioClient service

New `src/Service/Payment/FioClient.php`:

```php
final readonly class FioClient
{
    public function __construct(
        private string $fioApiToken, // injected from %env(FIO_API_TOKEN)%
    ) {}

    public function downloadTransactions(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ('' === $this->fioApiToken) {
            return []; // FIO not configured — skip gracefully
        }

        $downloader = new \FioApi\Downloader($this->fioApiToken);
        $transactionList = $downloader->downloadFromTo($from, $to);

        return array_map(
            fn (\FioApi\Transaction $t) => new FioBankTransaction(
                id: (string) $t->getId(),
                amount: (int) round($t->getAmount() * 100), // float → haléře
                currency: $t->getCurrency(),
                variableSymbol: $t->getVariableSymbol(),
                senderAccountNumber: $this->formatAccount($t->getSenderAccountNumber(), $t->getSenderBankCode()),
                senderName: $t->getSenderName(),
                date: $t->getDate(),
                comment: $t->getComment(),
            ),
            $transactionList->getTransactions(),
        );
    }
}
```

New `src/Value/FioBankTransaction.php` (readonly DTO).

Register in `config/services.php`:
```php
FioClient::class => service()
    ->arg('$fioApiToken', env('FIO_API_TOKEN')),
```

### 10. QrPaymentGenerator service

New `src/Service/Payment/QrPaymentGenerator.php`:

```php
final readonly class QrPaymentGenerator
{
    private const ACCOUNT_NUMBER = '2603478520';
    private const BANK_CODE = '2010';

    public function generateDataUri(string $variableSymbol, int $amountInHaler, ?\DateTimeImmutable $dueDate = null): string
    {
        $payment = QrPayment::fromAccountAndBankCode(self::ACCOUNT_NUMBER, self::BANK_CODE);
        $payment
            ->setVariableSymbol((int) $variableSymbol)
            ->setAmount($amountInHaler / 100)
            ->setCurrency('CZK');

        if (null !== $dueDate) {
            $payment->setDueDate($dueDate);
        }

        return $payment->getQrCode()->getDataUri();
    }

    public function getBankAccountFormatted(): string
    {
        return self::ACCOUNT_NUMBER . '/' . self::BANK_CODE;
    }
}
```

### 11. Order flow changes — surcharge + payment method choice

**OrderFormType / OrderFormData:**
- New `paymentMethod` field (ChoiceType, expanded radios):
  - `GOPAY`: "Platba kartou (GoPay)" — default
  - `BANK_TRANSFER`: "Bankovní převod (+X Kč / měsíc)"
- Only shown when order is recurring-eligible (>= 28 days or UNLIMITED). One-time short rentals (< 28 days) also allow bank transfer but surcharge is one-time.
- `OrderFormData` gains `public ?PaymentMethod $paymentMethod = null;`

**Price calculation with surcharge:**
- `PriceCalculator` gains `calculateWithSurcharge(int $basePrice, PaymentMethod $method, PlatformSettings $settings): int`
- For BANK_TRANSFER: adds `$settings->bankTransferSurchargeInHaler` per billing cycle
- `Order.firstPaymentPrice` is set INCLUDING the surcharge (locked at creation)
- Customer sees the surcharge inline: "500 Kč + 100 Kč příplatek za převod = 600 Kč / měsíc"

**OrderForm Live Component:**
- Reactively shows/hides bank transfer surcharge note based on payment method selection
- Updates price preview to include surcharge when BANK_TRANSFER selected

**billingMode derivation:**
- `BANK_TRANSFER` + one-time (< 28 days) → `BillingMode::ONE_TIME`
- `BANK_TRANSFER` + recurring (≥ 28 days or UNLIMITED) → `BillingMode::MANUAL_RECURRING`
- AUTO_RECURRING is NEVER available for bank transfer (no automated charge possible)

### 12. Payment page for bank transfer

When `order.paymentMethod === BANK_TRANSFER`, `OrderPaymentController` renders a different template block:

- No GoPay inline gateway
- Shows:
  - Číslo účtu: 2603478520/2010
  - Variabilní symbol: `{order.variableSymbol}`
  - Částka: `{order.firstPaymentPriceInCzk}` Kč
  - QR kód (SPD standard PNG via `QrPaymentGenerator::generateDataUri`)
  - "Po přijetí platby vás budeme informovat e-mailem."
  - Link to order status page (`/objednavka/{id}/stav`)
- Same identification block + compliance elements as GoPay page
- No "Zaplatit" button — customer pays from their banking app

Template: extend `templates/public/order_payment.html.twig` with `{% if order.paymentMethod.value == 'bank_transfer' %}` conditional branch.

### 13. Confirmation email for bank transfer

New template `templates/email/bank_transfer_payment_instructions.html.twig`:
- Sent immediately when order reaches payment page (after terms accepted)
- Contains: bank account, VS, amount, QR code (inline PNG as email attachment via CID), link to `/stav`
- Subject: "Platební údaje — objednávka {order.id|slice(0,8)|upper}"

New event: `BankTransferPaymentInstructionsSent` — or simpler: branch in existing `SendOrderPlacedEmailHandler` when paymentMethod is BANK_TRANSFER.

### 14. FIO reconciliation cron

New `src/Console/ProcessFioTransactionsCommand.php` — `app:process-fio-transactions`:

```
Schedule: every 5 minutes (crontab: */5 * * * *)
```

Logic:
1. Determine date range: last 3 days (overlap window for safety; idempotent via `fioTransactionId` unique constraint)
2. Call `FioClient::downloadTransactions($from, $to)`
3. Filter to credit-only (amount > 0) CZK transactions
4. For each transaction:
   a. Skip if `BankTransactionRepository::existsByFioTransactionId()` (already processed)
   b. Create `BankTransaction` entity
   c. Attempt auto-match:
      - If `variableSymbol` is set: find `Order` with matching `variableSymbol`
        - If found and order `canBePaid()`: match as first payment → dispatch `ConfirmOrderPaymentCommand`
        - If found and order has active contract with `billingMode=MANUAL_RECURRING`: match as recurring charge → call `contract->recordBillingCharge()` + dispatch `RecurringPaymentCharged`
        - If found but amount != expected: pair with `status = 'amount_mismatch'`, dispatch `PaymentAmountMismatch` event
      - If no VS: check `BankAccountMapping` for sender account → if found, pair to the mapped order's active contract
      - Otherwise: leave as `status = 'unmatched'`
   d. Persist (inside doctrine_transaction middleware via command bus dispatch)

**Rate limit handling:** FIO API allows one call per 30 seconds per token. The cron runs every 5 minutes, making exactly one API call per run. If `TooGreedyException` is thrown, log warning and exit gracefully (next run in 5 min).

**Command dispatches for matched transactions:**
- New `ProcessBankTransferPaymentCommand` (contains `BankTransaction $transaction, Order $order`)
- `ProcessBankTransferPaymentHandler`: mirrors `ProcessPaymentNotificationHandler`'s order/recurring paths but without GoPay status polling — the money is already in the account.

### 15. Bank transfer reminders (recurring)

For `MANUAL_RECURRING` + `BANK_TRANSFER` contracts, the existing `app:send-manual-billing-payment-requests` cron must branch:

In `DispatchManualBillingNotificationHandler`:
- If `contract.order.paymentMethod === BANK_TRANSFER`:
  - Do NOT create GoPay one-time charge
  - Instead: generate email with bank account + VS + QR code + amount for this cycle
  - The `ManualPaymentRequest` row is still created (same unique constraint, same `sentStages`)
  - `goPayPaymentId` and `goPayGatewayUrl` stay NULL (unused for bank transfer)
- Email templates: new variants `manual_billing_bank_transfer_*.html.twig` (initial, due_today, overdue_first, overdue_final)

### 16. Admin "Bankovní platby" page

New route: `/portal/admin/bankovni-platby` → `AdminBankPaymentsController`

**List view:**
- Table: Datum | Částka | VS | Odesílatel | Účet | Stav | Objednávka | Akce
- Filter chips: Vše | Nespárované ({N}) | Spárované | Nesouhlasí částka
- "Nespárované" count badge in sidebar nav (like overdue/operations)
- Sortable by date (desc default)
- Excel export (mirrors spec 028 pattern)

**Manual pairing action:**
- Row action "Párovat" on unmatched transactions → opens modal/page:
  - Order search (by ID prefix, user email, or storage number)
  - Shows order details + expected amount
  - **"Přiřadit účet odesílatele k objednávce" checkbox (default: checked)** — when checked, creates `BankAccountMapping` so future payments from this sender account are automatically paired to this order/contract WITHOUT needing a variable symbol. Admin sees explanatory text: "Příští platby z účtu {senderAccount} budou automaticky přiřazeny k této objednávce bez nutnosti ručního párování."
  - Confirm button → pairs transaction to order + optionally creates `BankAccountMapping`
  - "Ignorovat" button → marks as ignored (with confirmation + mandatory reason)

**Order detail integration:**
- New panel "Bankovní platby" on admin order detail (below existing payment info)
- Shows all `BankTransaction` rows paired to this order/contract
- "Párovat platbu" button → same pairing flow but pre-filtered to this order

### 17. Admin onboarding form extension

`AdminCreateOnboardingFormType` and `AdminMigrateCustomerFormType`:
- `paymentMethod` choices gain third option: `BANK_TRANSFER`: "Bankovní převod (zákazník platí převodem)"
- When `BANK_TRANSFER` selected, show optional `variableSymbol` override field (TextType, max 10 digits, numeric-only constraint)
- If override provided: use it (check uniqueness). If blank: auto-generate via `VariableSymbolGenerator`.
- `billingMode` is auto-set to `MANUAL_RECURRING` when `BANK_TRANSFER` and recurring-eligible; `ONE_TIME` for short-term.

### 18. Order accept page (`/prijmout`) changes

When `paymentMethod === BANK_TRANSFER`:
- Hide the recurring-payment consent checkbox (no card stored, no automated charges)
- Hide GoPay card/3DS logos
- Show instead: "Po potvrzení objednávky obdržíte platební údaje pro bankovní převod."
- The submit button stays `OBJEDNÁVÁM a zaplatím` per compliance (§ 1826a OZ)

### 19. Migrations

Generate via `make:migration` (never handwrite):
- Add `variable_symbol` column to `orders` (varchar 10, nullable, unique index)
- Create `platform_settings` table
- Create `bank_transactions` table
- Create `bank_account_mappings` table
- Seed one `PlatformSettings` row with default surcharge 10 000 haléřů

### 20. Events

New domain events:
- `BankTransferPaymentReceived` — when FIO cron matches a transaction to an order (first payment)
- `BankTransferRecurringPaymentReceived` — when matched to a contract cycle

These dispatch into the existing event bus, triggering the same downstream handlers as GoPay (invoice issuance, Payment entity creation, email notifications).

### 21. Admin settings page

New route: `/portal/admin/nastaveni` → `AdminSettingsController`
- Single form with "Příplatek za bankovní převod" field (NumberType, Kč, mapped from/to haléře)
- Future-proof: additional platform settings can be added to the entity + form
- Admin sidebar: new "Nastavení" entry below "E-maily" (gear icon)

### 22. Audit trail (CRITICAL — every payment action must be auditable)

Every payment-related action produces an `AuditLog` row via `AuditLogger`. This is non-negotiable — bank transfer payments have no external ledger (unlike GoPay which provides its own audit via their dashboard). Our `AuditLog` + `BankTransaction` entities together form the complete evidence chain.

**New AuditLogger methods** (all follow existing pattern: `entityType`, `entityId`, `eventType`, `payload` JSON):

| Action | entityType | eventType | payload fields |
|--------|-----------|-----------|----------------|
| FIO transaction received (stored) | `bank_transaction` | `received` | `fio_transaction_id`, `amount`, `currency`, `variable_symbol`, `sender_account`, `sender_name`, `transaction_date` |
| Auto-matched to order (by VS) | `bank_transaction` | `auto_matched_to_order` | `order_id`, `variable_symbol`, `expected_amount`, `received_amount`, `match_method: 'variable_symbol'` |
| Auto-matched to contract (recurring, by VS) | `bank_transaction` | `auto_matched_to_contract` | `contract_id`, `order_id`, `variable_symbol`, `expected_amount`, `received_amount`, `billing_period_start` |
| Auto-matched via BankAccountMapping | `bank_transaction` | `auto_matched_via_account` | `order_id`, `contract_id`, `sender_account`, `mapping_id`, `expected_amount`, `received_amount` |
| Amount mismatch detected | `bank_transaction` | `amount_mismatch` | `order_id` or `contract_id`, `expected_amount`, `received_amount`, `difference`, `variable_symbol` |
| Admin manual pair to order | `bank_transaction` | `manually_paired_to_order` | `order_id`, `admin_id`, `admin_email`, `amount`, `sender_account` |
| Admin manual pair to contract | `bank_transaction` | `manually_paired_to_contract` | `contract_id`, `order_id`, `admin_id`, `admin_email`, `amount`, `sender_account` |
| Admin ignored transaction | `bank_transaction` | `ignored` | `admin_id`, `admin_email`, `amount`, `sender_account`, `variable_symbol`, `reason` (free text, required on ignore action) |
| Bank account mapping created | `bank_account_mapping` | `created` | `account_number`, `user_id`, `order_id`, `created_by_admin_id`, `source_bank_transaction_id` |
| Bank account mapping deleted | `bank_account_mapping` | `deleted` | `account_number`, `user_id`, `order_id`, `deleted_by_admin_id` |
| VS match overrode account mapping (conflict) | `bank_transaction` | `vs_override_account_mapping` | `vs_matched_order_id`, `account_mapping_order_id`, `variable_symbol`, `sender_account` |
| Variable symbol generated | `order` | `variable_symbol_assigned` | `variable_symbol`, `generation_method: 'auto'` or `'admin_override'`, `admin_id` (if override) |
| Surcharge setting changed | `platform_settings` | `surcharge_changed` | `old_value_haler`, `new_value_haler`, `admin_id`, `admin_email` |
| Bank transfer payment confirmed (order paid) | `order` | `bank_transfer_payment_confirmed` | `bank_transaction_id`, `fio_transaction_id`, `amount`, `variable_symbol`, `sender_account` |
| Bank transfer recurring charge confirmed | `contract` | `bank_transfer_recurring_confirmed` | `bank_transaction_id`, `fio_transaction_id`, `amount`, `variable_symbol`, `billing_period_start`, `sender_account` |
| FIO cron run completed | `system` | `fio_cron_completed` | `transactions_fetched`, `transactions_matched`, `transactions_unmatched`, `transactions_skipped_duplicate`, `amount_mismatches`, `date_range_from`, `date_range_to`, `duration_ms` |
| FIO cron run failed | `system` | `fio_cron_failed` | `error_class`, `error_message`, `was_rate_limited: bool` |
| Bank transfer reminder sent (recurring) | `manual_payment_request` | `bank_transfer_reminder_sent` | `contract_id`, `period_start`, `stage`, `amount`, `variable_symbol` |

**BankTransaction entity additions for audit completeness:**

Add to `BankTransaction`:
```php
/** How this transaction was matched — evidence for disputes */
#[ORM\Column(length: 30, nullable: true)]
public private(set) ?string $matchMethod = null; // 'variable_symbol' | 'account_mapping' | 'manual'

/** Free-text reason when admin ignores (required field in UI) */
#[ORM\Column(length: 500, nullable: true)]
public private(set) ?string $ignoreReason = null;
```

**Admin "Bankovní platby" page must show full audit trail per transaction:**
- Expandable row detail shows: all AuditLog entries for this `bank_transaction` entityId, chronologically
- Admin can see exactly what happened: received → matched/ignored, by whom, when

**Admin order detail "Bankovní platby" panel shows:**
- Each received payment: date, amount, sender, VS, match method (auto/manual), matched by (system/admin name)
- Expandable audit trail per payment

**Ignore action requires reason:**
- Admin must type a reason (min 5 chars) when ignoring a transaction
- Reason is stored on `BankTransaction.ignoreReason` AND in the audit log payload
- This prevents casual dismissal of unmatched payments

**Immutability rules:**
- Once a `BankTransaction` is `matched`, it CANNOT be unpaired (requires new spec / admin override)
- Once `ignored`, can be "un-ignored" and re-paired (with its own audit entry: `eventType: 'unignored'`)
- `BankTransaction` raw data (`amount`, `fioTransactionId`, `variableSymbol`, `senderAccountNumber`) is NEVER editable — it's a mirror of the bank's ledger

## Acceptance

- [ ] `composer require mhujer/fio-api-php rikudou/czqrpayment endroid/qr-code` succeeds
- [ ] Customer can select "Bankovní převod" on order form; price updates to show surcharge
- [ ] After accepting terms, payment page shows bank account + VS + QR code (no GoPay gateway)
- [ ] Variable symbol is exactly 10 numeric digits, unique across all orders
- [ ] Email with payment instructions (bank details + QR) is sent after order placed
- [ ] `app:process-fio-transactions` cron correctly downloads and stores FIO transactions
- [ ] Auto-matching by VS triggers order payment (same events as GoPay: OrderPaid → Contract creation → Invoice)
- [ ] Amount mismatch between expected and received is flagged (admin alert email + status on transaction)
- [ ] Recurring MANUAL_RECURRING + BANK_TRANSFER contracts receive reminder emails with bank details + QR (not GoPay links)
- [ ] FIO cron matches recurring payments to contracts and triggers RecurringPaymentCharged event
- [ ] Admin sees `/portal/admin/bankovni-platby` with all transactions, filter chips, unmatched badge
- [ ] Admin can manually pair unmatched transaction to an order; BankAccountMapping is created
- [ ] Future payments from same account (without VS) auto-match via BankAccountMapping
- [ ] Manual pairing prompts admin with "Přiřadit účet odesílatele" checkbox (default checked); creates mapping when confirmed
- [ ] BankAccountMapping auto-match works: second payment from same account (no VS) → automatically paired
- [ ] VS match takes precedence over account mapping when both point to different orders (audit log records the conflict)
- [ ] Admin can view and delete BankAccountMappings from order detail panel ("Přiřazené účty")
- [ ] Admin can ignore irrelevant transactions
- [ ] Admin order detail shows "Bankovní platby" panel with received transactions
- [ ] Admin onboarding forms have BANK_TRANSFER option with optional VS override
- [ ] `/portal/admin/nastaveni` allows changing surcharge (reflected in new orders immediately)
- [ ] FIO rate limit (30s) is respected; `TooGreedyException` is caught and logged gracefully
- [ ] QR code renders valid SPD (testable: decode QR → verify SPD string contains correct IBAN + VS + amount)
- [ ] `composer quality` is green
- [ ] **AUDIT (critical):** Every action in the §22 table produces an `AuditLog` row with correct `entityType`, `eventType`, and complete `payload`
- [ ] **AUDIT:** FIO cron writes `fio_cron_completed` audit entry on every run (including zero-transaction runs)
- [ ] **AUDIT:** FIO cron writes `fio_cron_failed` on API error / rate-limit / exception
- [ ] **AUDIT:** Auto-match records `auto_matched_to_order` or `auto_matched_to_contract` with `expected_amount` + `received_amount` + `match_method`
- [ ] **AUDIT:** Manual pair by admin records `manually_paired_to_order`/`manually_paired_to_contract` with admin identity
- [ ] **AUDIT:** Ignore requires non-empty reason (min 5 chars); reason stored on entity + audit log
- [ ] **AUDIT:** Surcharge change records old → new value + admin identity
- [ ] **AUDIT:** Variable symbol assignment records `generation_method` (`auto` / `admin_override`)
- [ ] **AUDIT:** Admin "Bankovní platby" transaction detail shows chronological audit trail
- [ ] **AUDIT:** `BankTransaction` raw fields (`amount`, `fioTransactionId`, `variableSymbol`, `senderAccountNumber`) are immutable after creation
- [ ] **AUDIT:** Matched transactions cannot be unpaired; ignored can be un-ignored (with audit entry)
- [ ] Unit tests cover: VariableSymbolGenerator (uniqueness, collision retry), QrPaymentGenerator (SPD string), surcharge calculation, FIO transaction matching logic, BankTransaction entity state transitions, audit log creation for every §22 action
- [ ] Integration tests cover: order flow with BANK_TRANSFER end-to-end, reconciliation cron with fixture transactions, manual pairing admin flow, audit trail completeness for matched + manual-paired + ignored scenarios

## Out of scope

- **Outgoing bank transfers** (paying landlords via FIO) — only incoming payment reading is needed.
- **Multi-currency** — FIO transactions in non-CZK are ignored by the cron.
- **FIO webhook/push notifications** — FIO doesn't offer webhooks; polling is the only option.
- **Auto-recurring bank transfers (SIPO/inkaso)** — not supported by FIO API; customer always pays manually.
- **GoPay removal or deprecation** — GoPay remains the primary/default payment method.
- **Per-place surcharge** — surcharge is global (single PlatformSettings value).
- **Yearly payment frequency for bank transfer** — follow same rules as existing yearly (uses MANUAL_RECURRING, compatible out of the box).
- **Customer self-service cancel of bank transfer recurring** — they simply stop paying; overdue detection (spec 023) handles it.
- **Refunds via FIO** — out of scope; handled manually by the operator.
- **Admin "Nastavení" page for any settings beyond surcharge** — future specs can extend the entity.

## Open questions

None — proceed.
