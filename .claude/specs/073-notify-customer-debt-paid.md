# 073 — Notify customer (and admin) when an onboarding debt is paid + issue a debt invoice

**Status:** done
**Type:** feature (notification + invoicing)
**Scope:** medium (~12 files: 1 new event, record it on `Order`, 2 new email handlers + 2 templates, `FakturoidClient` method (interface + impl + mock), `InvoicingService` method, tests, docs)
**Depends on:** spec 051 (onboarding debt: `Order.onboardingDebtInHaler` / `debtPaidAt`, `DebtPaymentService::confirmDebtPaid`, GoPay + FIO debt branches). Supersedes spec 051's "Automated Fakturoid invoice for debt payment" out-of-scope note.

## Problem

An admin-onboarded customer who carries a pre-existing debt (spec 051) must pay it before the rental can start. The customer gets an immediate debt-payment **request** e-mail plus D+2 / D+5 reminders — but when they actually **pay** (GoPay card or bank transfer), they receive **nothing**. `DebtPaymentService::confirmDebtPaid()` (`src/Service/Onboarding/DebtPaymentService.php`) marks `debtPaidAt`, writes an audit row, and (for free/prepaid) auto-completes the order — but never tells the customer the money landed. This is acute for **bank-transfer** debts, which clear asynchronously via the FIO cron: the customer transfers the money and then has zero feedback that it arrived or what happens next. The operator confirmed: "I paid debt and had no feedback as a customer."

Separately, no invoice is issued for the debt. Spec 051 deferred this ("admin issues a manual invoice in Fakturoid for now"). The operator now wants the debt invoiced automatically (flagged as possibly-temporary — keep it cleanly removable).

## Goal

When an onboarding debt is confirmed paid (either payment path), the system:

1. **E-mails the customer a "Dluh uhrazen" receipt** — which storage (number + type) and place, the amount, the date paid, and a clear next-step pointer: for standard-billing orders "now pay the first rent" (CTA to `/stav`); for free/externally-prepaid orders "your rental is now active". Debt confirmation is a **separate** message from the rental/first-payment e-mail — paying the debt is the gating step, not the rent.
2. **Issues a Fakturoid invoice for the debt** (gross amount the admin entered, 21 % DPH back-calculated, marked paid immediately) and bundles its PDF into the customer receipt, suppressing the standalone invoice e-mail (exactly as the rental-activated flow does).
3. **Sends every admin a short heads-up** that the debt was paid (recipient, amount, order).

## Context (current state)

### Where debt gets marked paid (the single choke point)

- `src/Service/Onboarding/DebtPaymentService.php` → `confirmDebtPaid(Order $order, \DateTimeImmutable $now, ?string $goPayPaymentId = null)`: calls `$order->markDebtPaid($now)`, audit-logs `debt_payment_confirmed`, and for free/prepaid (`individualMonthlyAmount === 0 || paidThroughDate !== null`) does `confirmPayment(0)` + dispatch `CompleteOrderCommand`.
- Called from exactly two guarded sites, so the "paid" transition happens **once** per debt:
  - GoPay webhook — `src/Command/ProcessPaymentNotificationHandler.php:119`, guarded by `$status->isPaid() && $debtOrder->hasUnpaidDebt()`.
  - FIO bank-transfer cron — `src/Command/ProcessBankTransferDebtPaymentHandler.php:29`, guarded by `if (!$order->hasUnpaidDebt()) return;`.
- `src/Entity/Order.php:512` → `markDebtPaid(\DateTimeImmutable $now)` currently just sets `$this->debtPaidAt = $now;`. `Order implements EntityWithEvents` + `use HasEvents` (`Order.php:28-30`), so it can `recordThat(...)`.
- Helpers already present: `Order::hasUnpaidDebt()`, `hasDebt()`, `getDebtAmountInCzk(): ?float` (haléře/100), `canBePaid()` (`Order.php:301` — true for CREATED/RESERVED/AWAITING_PAYMENT), `Order::$onboardingDebtInHaler` (`?int`), `Order::$debtPaidAt`, `Order::$user`, `Order::$storage` → `$storage->storageType` / `$storage->number` / `$storage->getPlace()`.

### The mirror pattern — fine "paid" (copy this shape)

- `src/Entity/Fine.php:99-110` → `markPaid()` records a `FinePaid` event **inside the entity method** (`Fine.php:103`). This is the convention to copy for `markDebtPaid()`.
- `src/Event/FinePaid.php` — readonly event DTO (`fineId`, `contractId`, `userId`, `amountInHaler`, `occurredOn`).
- `src/Event/SendFinePaidEmailHandler.php` — `#[AsMessageHandler]`, loads the entity, builds a `TemplatedEmail` (green "zaplaceno" template), adds `X-Order-Id`, sends with try/catch.
- `templates/email/fine_paid.html.twig` — green header, white summary table (type / amount / date), footer. **Reuse this exact CSS skeleton** for `debt_paid.html.twig`.

### Domain-event dispatch timing (why the handler can read final order state)

- `config/packages/messenger.php:27-49`: **both** `command.bus` and `event.bus` carry `DispatchDomainEventsMiddleware` + `doctrine_transaction`. Events recorded via `recordThat` are collected by `src/Event/DomainEventsSubscriber.php` on flush and dispatched **after the command transaction commits**.
- Consequence: by the time `OnboardingDebtPaid` is handled, the order's final state is committed — for free/prepaid the nested `CompleteOrderCommand` has already run (order `COMPLETED`, `canBePaid()` false); for standard billing the order is still `RESERVED` (`canBePaid()` true). So the email handler can branch the next-step copy on `$order->canBePaid()`.
- Because `event.bus` also has `doctrine_transaction`, an event handler that persists a new `Invoice` gets flushed by the middleware (no manual `flush()`), and `InvoiceCreated` is dispatched after that commit — identical to how `SendRentalActivatedEmailHandler` issues invoices.

### Invoicing infrastructure (for the debt invoice)

- `src/Entity/Invoice.php` — FK to **`Order`** (not Contract) and `User`, both `nullable: false`. So an invoice can be issued for an order **before any contract exists** (the debt is paid pre-contract). Multiple invoices per order already happen (every recurring charge issues one against `contract.order`). Methods: `attachPdf()`, `hasPdf()`, `markEmailed()`, `isEmailed()`, `getAmountInCzk()`.
- `src/Service/InvoicingService.php:30` → `issueInvoiceForOrder(Order, now): Invoice` is the template: `ensureFakturoidSubject` (with stale-subject recovery) → `fakturoidClient->createInvoice(...)` → `markInvoiceAsPaid` when `order.paidAt` set → `new Invoice(...)` → `invoiceRepository->save()` → best-effort PDF download/attach. **Add a `issueInvoiceForDebt` sibling.**
- `src/Service/Fakturoid/FakturoidClient.php` (interface, `createInvoice` at :20) + `FakturoidApiClient.php:112` (impl). The impl's line-item shape (copy it):
  ```php
  'vat_price_mode' => 'from_total_with_vat', // prices are gross (vč. DPH); Fakturoid back-calculates VAT — spec 034
  'lines' => [[ 'name' => '…', 'quantity' => 1, 'unit_price' => <gross CZK float>, 'vat_rate' => $this->vatRate ]],
  ```
  `$this->vatRate` is the injected int (21). Returns `FakturoidInvoice { id, number, total (haléře) }`.
- Test double: `tests/Mock/MockFakturoidClient.php` (implements the interface — **must** gain `createDebtInvoice`). `willReturnPdf()` / `getCreatedInvoices()` available for assertions.

### Double-send suppression (invoice e-mail)

- Saving an `Invoice` records `InvoiceCreated` → `src/Event/SendInvoiceEmailHandler.php` sends the standalone "Faktura X" e-mail, but **skips when `$invoice->isEmailed()`** (`SendInvoiceEmailHandler.php:41`).
- `src/Event/SendRentalActivatedEmailHandler.php:117` issues the invoice, attaches the PDF, then calls `$invoice->markEmailed($now)` to suppress the standalone send. **Do the same** in the debt receipt handler.

### Existing debt e-mail to mirror for context

- `src/Event/SendDebtPaymentRequestEmailHandler.php` (the **request** e-mail) shows the context shape: loads `order` via `orderRepository->get($event->orderId)`, `place = $order->storage->getPlace()`, uses `PlaceAddressFormatter::format($place)`, `number_format($debtAmountCzk, 0, ',', ' ')`, `X-Order-Id` header. `templates/email/debt_payment_request.html.twig` — red header + summary table; the paid receipt is its green sibling.

### Admin-notification pattern (copy this)

- `src/Event/SendBankTransferMismatchAdminEmailHandler.php` — `$admins = $this->userRepository->findByRole(UserRole::ADMIN)` (`UserRepository.php:255`), early-return if none, loop `foreach ($admins as $admin)` sending one `TemplatedEmail` each with `email/*_admin.html.twig`, `X-Order-Id`, per-send try/catch. Admin templates live flat in `templates/email/` with the `_admin.html.twig` suffix.

### "What's next" surface

- `src/Service/OrderStatusUrlGenerator.php:30` → `generate(Order): string` returns the signed public `/stav` URL. Spec 051 §11 already made `/stav` show a green "dluh uhrazen" banner and un-suppress the first-rent CTA once the debt clears. So the receipt's CTA is just a link to `generate($order)` — no new UI.

## Architecture

```
confirmDebtPaid(order)                         [command.bus: GoPay webhook OR FIO cron]
  └─ order.markDebtPaid(now)
        └─ recordThat(OnboardingDebtPaid)       ← NEW (mirrors Fine::markPaid → FinePaid)
  └─ (free/prepaid only) confirmPayment(0) + CompleteOrderCommand  → order COMPLETED
  ── commit ──► DispatchDomainEventsMiddleware dispatches OnboardingDebtPaid (+ any OrderCompleted)
       │
       ├─► SendOnboardingDebtPaidEmailHandler            [event.bus]   (customer)
       │      ├─ issueInvoiceForDebt(order, now)  → Fakturoid invoice (gross, 21% from_total_with_vat,
       │      │      marked paid) → Invoice row (records InvoiceCreated) → best-effort PDF
       │      ├─ invoice.markEmailed(now)   → suppresses standalone SendInvoiceEmailHandler
       │      ├─ attach invoice PDF (if downloaded)
       │      └─ send "Dluh uhrazen" receipt: storage+place+amount+date,
       │             next step = order.canBePaid() ? "pay first rent" : "rental active", CTA → /stav
       │
       └─► SendOnboardingDebtPaidAdminEmailHandler       [event.bus]   (admins)
              └─ findByRole(ADMIN) → per-admin short heads-up
```

## Requirements

### 1. New domain event

**File:** `src/Event/OnboardingDebtPaid.php` — mirror `FinePaid`:

```php
final readonly class OnboardingDebtPaid
{
    public function __construct(
        public Uuid $orderId,
        public Uuid $userId,
        public int $amountInHaler,
        public \DateTimeImmutable $occurredOn,
    ) {}
}
```

### 2. Record the event on `Order::markDebtPaid()`

**File:** `src/Entity/Order.php:512` — mirror `Fine::markPaid()` (`Fine.php:103`):

```php
public function markDebtPaid(\DateTimeImmutable $now): void
{
    $this->debtPaidAt = $now;

    $this->recordThat(new OnboardingDebtPaid(
        orderId: $this->id,
        userId: $this->user->id,
        amountInHaler: $this->onboardingDebtInHaler ?? 0, // non-null > 0 whenever a debt exists; ?? 0 satisfies PHPStan
        occurredOn: $now,
    ));
}
```

Both call sites already guard on `hasUnpaidDebt()`, so the event fires exactly once. No change needed in `DebtPaymentService`, the webhook handler, or the FIO handler — they call `markDebtPaid` unchanged.

### 3. Fakturoid client — `createDebtInvoice`

**File:** `src/Service/Fakturoid/FakturoidClient.php` (interface) — add:

```php
public function createDebtInvoice(int $subjectId, Order $order): FakturoidInvoice;
```

**File:** `src/Service/Fakturoid/FakturoidApiClient.php` — implement, copying `createInvoice` (`:112`) verbatim except the line item:

```php
'lines' => [
    [
        'name' => sprintf('Úhrada dluhu z předchozí smlouvy (%s)', $place->name),
        'quantity' => 1,
        'unit_price' => $order->getDebtAmountInCzk(), // gross — admin's amount includes VAT
        'vat_rate' => $this->vatRate,
    ],
],
```

Keep `'vat_price_mode' => 'from_total_with_vat'` (the debt amount is gross / vč. DPH — operator confirmed — so Fakturoid back-calculates the 21 % VAT exactly like rent; do **not** add VAT on top → guards against the spec 034 double-count). Keep the same try/catch, `StaleFakturoidSubjectException` rethrow, and `FakturoidInvoice` return mapping. The line name references the place (for the operator's books) without implying it's for the current unit (the debt is from a *previous* arrangement).

**File:** `tests/Mock/MockFakturoidClient.php` — add `createDebtInvoice` mirroring its `createInvoice` (record into `createdInvoices`, return a stub `FakturoidInvoice`).

### 4. `InvoicingService::issueInvoiceForDebt`

**File:** `src/Service/InvoicingService.php` — add after `issueInvoiceForOrder`, copying it but calling `createDebtInvoice` and marking paid at the debt-payment time:

```php
public function issueInvoiceForDebt(Order $order, \DateTimeImmutable $now): Invoice
{
    $user = $order->user;
    $subjectId = $this->ensureFakturoidSubject($user, $now);

    try {
        $fakturoidInvoice = $this->fakturoidClient->createDebtInvoice($subjectId, $order);
    } catch (StaleFakturoidSubjectException $e) {
        $subjectId = $this->recreateFakturoidSubject($user, $now, $e->subjectId);
        $fakturoidInvoice = $this->fakturoidClient->createDebtInvoice($subjectId, $order);
    }

    // Debt is already paid when this runs — mark the invoice paid immediately.
    $this->fakturoidClient->markInvoiceAsPaid($fakturoidInvoice->id, $order->debtPaidAt ?? $now);

    $invoice = new Invoice(
        id: $this->identityProvider->next(),
        order: $order,
        user: $user,
        fakturoidInvoiceId: $fakturoidInvoice->id,
        invoiceNumber: $fakturoidInvoice->number,
        amount: $fakturoidInvoice->total,
        issuedAt: $now,
        createdAt: $now,
    );

    $this->invoiceRepository->save($invoice);

    try {
        $pdfContent = $this->fakturoidClient->downloadInvoicePdf($fakturoidInvoice->id);
        $invoice->attachPdf($this->storePdf($invoice, $pdfContent));
    } catch (\Throwable $e) {
        $this->logger->warning('Failed to download debt invoice PDF', [
            'invoice_id' => $fakturoidInvoice->id,
            'exception' => $e,
        ]);
    }

    return $invoice;
}
```

> **Removability note (operator flagged the invoice as possibly-temporary):** keep all debt-invoice code isolated to `createDebtInvoice` + `issueInvoiceForDebt` + the one call in §5. Removing the feature later = delete those three and the `try/catch` block in §5; the receipt e-mail still sends without an attachment.

### 5. Customer receipt handler

**File:** `src/Event/SendOnboardingDebtPaidEmailHandler.php` (new, `#[AsMessageHandler]` on `OnboardingDebtPaid`). Inject `OrderRepository`, `InvoicingService`, `OrderStatusUrlGenerator`, `PlaceAddressFormatter`, `MailerInterface`, `LoggerInterface`.

```php
public function __invoke(OnboardingDebtPaid $event): void
{
    $order = $this->orderRepository->get($event->orderId);
    $user = $order->user;
    $storage = $order->storage;
    $place = $storage->getPlace();

    // Issue + bundle the debt invoice (best-effort: Fakturoid failure must not block the receipt).
    $invoice = null;
    try {
        $invoice = $this->invoicingService->issueInvoiceForDebt($order, $event->occurredOn);
        $invoice->markEmailed($event->occurredOn); // suppress the standalone SendInvoiceEmailHandler
    } catch (\Throwable $e) {
        $this->logger->error('Failed to issue debt invoice', ['order_id' => $order->id->toRfc4122(), 'exception' => $e]);
    }

    $awaitingFirstPayment = $order->canBePaid(); // standard billing → still owes first rent; free/prepaid → COMPLETED

    $email = (new TemplatedEmail())
        ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
        ->to(new Address($user->email, $user->fullName))
        ->subject(sprintf('Dluh uhrazen — %s Kč', number_format($event->amountInHaler / 100, 0, ',', ' ')))
        ->htmlTemplate('email/debt_paid.html.twig')
        ->context([
            'name' => $user->fullName,
            'amountCzk' => number_format($event->amountInHaler / 100, 0, ',', ' '),
            'paidAt' => $order->debtPaidAt,
            'storageNumber' => $storage->number,
            'storageTypeName' => $storage->storageType->name,
            'placeName' => $place->name,
            'placeAddress' => $this->addressFormatter->format($place),
            'awaitingFirstPayment' => $awaitingFirstPayment,
            'statusUrl' => $this->statusUrlGenerator->generate($order),
            'invoiceNumber' => $invoice?->invoiceNumber,
        ]);

    if (null !== $invoice && $invoice->hasPdf() && null !== $invoice->pdfPath && file_exists($invoice->pdfPath)) {
        $email->attachFromPath($invoice->pdfPath, 'faktura-'.$invoice->invoiceNumber.'.pdf', 'application/pdf');
    }

    $email->getHeaders()->addTextHeader('X-Order-Id', $order->id->toRfc4122());

    try {
        $this->mailer->send($email);
    } catch (\Throwable $e) {
        $this->logger->error('Failed to send debt paid email', ['order_id' => $order->id->toRfc4122(), 'exception' => $e]);
    }
}
```

### 6. Customer receipt template

**File:** `templates/email/debt_paid.html.twig` — clone `templates/email/fine_paid.html.twig`'s structure (green `#16a34a` header, white `.summary` table, footer). Content:

- Header: `Dluh uhrazen`. Greeting `Dobrý den, {{ name }}!`. Intro: `Potvrzujeme přijetí platby dluhu z předchozí smlouvy.`
- Summary table rows: `Pobočka` (`{{ placeName }}`), `Adresa` (`{{ placeAddress }}`, `{% if %}`), `Skladovací jednotka` (`{{ storageTypeName }} č. {{ storageNumber }}`), `Uhrazená částka` (`{{ amountCzk }} Kč`, green bold), `Datum platby` (`{{ paidAt|date('d.m.Y') }}`, `{% if paidAt %}`), and `Faktura` (`{{ invoiceNumber }}`, `{% if invoiceNumber %}`).
- Next step block:
  ```twig
  {% if awaitingFirstPayment %}
      <p>Tímto je dluh vyrovnán. Nyní prosím dokončete úhradu prvního nájemného, aby mohl pronájem začít.</p>
      <div style="text-align:center;"><a href="{{ statusUrl }}" class="button">Zobrazit stav a zaplatit nájemné</a></div>
  {% else %}
      <p>Tímto je dluh vyrovnán a váš pronájem je aktivní. Podrobnosti najdete ve stavu objednávky.</p>
      <div style="text-align:center;"><a href="{{ statusUrl }}" class="button">Zobrazit stav objednávky</a></div>
  {% endif %}
  ```
  (`.button` green, matching the header — adapt the red `.button` from `debt_payment_request.html.twig`.)
- `{% if invoiceNumber %}` add a line: `Fakturu za úhradu dluhu najdete v příloze.`
- Footer + contact identical to `debt_payment_request.html.twig` (`simek@fajnesklady.cz`, auto-generated notice).

All Czech text with full diacritics.

### 7. Admin heads-up handler + template

**File:** `src/Event/SendOnboardingDebtPaidAdminEmailHandler.php` (new, `#[AsMessageHandler]` on the **same** `OnboardingDebtPaid` event) — mirror `SendBankTransferMismatchAdminEmailHandler`: `findByRole(UserRole::ADMIN)`, early-return if none, loop per admin, `email/debt_paid_admin.html.twig`, `X-Order-Id`, per-send try/catch. Inject `OrderRepository`, `UserRepository`, `MailerInterface`, `LoggerInterface`. Context per admin: `adminName`, `customerName`, `customerEmail`, `amountCzk`, `paidAt`, `placeName`, `storageLabel` (`{type} č. {number}`), `orderReference` (optional — `OrderReferenceFormatter::format($order)`; inject it if used). Subject: `sprintf('Dluh uhrazen — %s (%s Kč)', $order->user->fullName, …)`.

**File:** `templates/email/debt_paid_admin.html.twig` (new) — short neutral admin notice (clone an existing `_admin` template's shell): "Zákazník {{ customerName }} uhradil dluh {{ amountCzk }} Kč" + the summary rows. No CTA needed.

### 8. Tests

- **Unit — `tests/Unit/Entity/OrderTest.php`** (extend): after `setOnboardingDebt(50000)` then `markDebtPaid($now)`, `popEvents()` contains one `OnboardingDebtPaid` with `amountInHaler === 50000`, `orderId`, `userId`, `occurredOn === $now`. Calling `markDebtPaid` sets `debtPaidAt`.
- **Integration — `tests/Integration/.../DebtPaidNotificationTest.php`** (new; use `OnboardingFixtures`, add a debt-bearing golden order if absent). Dispatch `OnboardingDebtPaid` on the test event bus (or call `DebtPaymentService::confirmDebtPaid` and let the middleware dispatch) and assert via `MailerAssertionsTrait` + `MockFakturoidClient`:
  - **Standard-billing (GoPay) debt order:** customer e-mail sent (subject `Dluh uhrazen — …`), body contains the storage label + place + amount + the "zaplatit nájemné" next-step CTA to the `/stav` URL; `MockFakturoidClient::getCreatedInvoices()` has one debt invoice; the standalone `SendInvoiceEmailHandler` did **not** send a second "Faktura" e-mail (invoice was `markEmailed`).
  - **Free/prepaid debt order:** receipt says "pronájem je aktivní" (no first-rent CTA); a debt invoice is still issued.
  - **Admins:** with ≥1 `ROLE_ADMIN` fixture, one `*_admin` e-mail per admin.
  - **Fakturoid failure** (`MockFakturoidClient` configured to throw on `createDebtInvoice`): the customer receipt **still sends** (without attachment) — invoice failure is swallowed.
- **Integration — `tests/Integration/.../InvoicingServiceTest.php`** (extend or new): `issueInvoiceForDebt` persists an `Invoice` linked to the order with `amount === fakturoidInvoice.total`, and the Fakturoid payload carries `vat_price_mode = from_total_with_vat`, `vat_rate = 21`, `unit_price = getDebtAmountInCzk()` (assert on the `MockFakturoidClient` recorded call).

### 9. Docs

- **`.claude/specs/PROJECT_MAP.md`** — add `OnboardingDebtPaid` event, `SendOnboardingDebtPaidEmailHandler` + `SendOnboardingDebtPaidAdminEmailHandler`, `InvoicingService::issueInvoiceForDebt`, `FakturoidClient::createDebtInvoice`, templates `email/debt_paid.html.twig` + `email/debt_paid_admin.html.twig`.
- **`.claude/CUSTOMER_DOCUMENTS.md`** — add the **debt invoice** (Fakturoid PDF, issued on debt payment, attached to the "Dluh uhrazen" e-mail, stored under the invoices dir like other invoices). New customer-facing document → mandated by CLAUDE.md.
- **`BACKLOG.md`** — append the row for spec 073.

## Acceptance

- [ ] `docker compose exec web composer quality` is green (cs:fix, phpstan level 8, unit).
- [ ] `docker compose exec web composer test` is green (full suite — touches entity events, invoicing, mailer, mock client).
- [ ] Paying an onboarding debt by **GoPay** (webhook) sends the customer a "Dluh uhrazen" e-mail naming the storage (type + number), place, amount, and date, with a CTA to `/stav`. **Verified** (functional/integration).
- [ ] Paying an onboarding debt by **bank transfer** (FIO cron match) sends the same receipt — the previously-silent async path now notifies the customer.
- [ ] Standard-billing order → receipt's next step is "zaplatit nájemné" (first-rent CTA). Free/externally-prepaid order → receipt says "pronájem je aktivní" (no first-rent CTA), and the separate "Pronájem aktivován" e-mail still fires independently.
- [ ] A Fakturoid **debt invoice** is issued (gross amount, 21 % DPH via `from_total_with_vat`, marked paid), its PDF is attached to the receipt, and the standalone "Faktura" e-mail is suppressed (no duplicate invoice e-mail).
- [ ] If Fakturoid is unavailable, the receipt e-mail still sends (no attachment); the debt-paid state is unaffected.
- [ ] Every `ROLE_ADMIN` receives a short heads-up e-mail when a debt is paid.
- [ ] The debt-paid e-mail fires **exactly once** per debt (guarded call sites; idempotent `markDebtPaid`).
- [ ] `PROJECT_MAP.md`, `.claude/CUSTOMER_DOCUMENTS.md`, `BACKLOG.md` updated.

## Out of scope

- **On-screen / GoPay-return UX.** The card return (`DebtPaymentReturnController`) already redirects to `/stav` (which shows the green "dluh uhrazen" banner per spec 051 §11); this spec adds the missing **notification** channel (e-mail), which is what's absent for bank transfer. No new pages.
- **Re-issuing invoices for debts already paid before this ships.** Operator issues those manually in Fakturoid if needed; this fires only on new debt payments.
- **Per-debt invoice toggle / categorising the debt (rent vs deposit vs penalty).** The admin enters one gross amount taxed as rent (21 %). If a non-VATable debt category is ever needed, that's a follow-up — the invoice code is isolated for easy change/removal.
- **Storing the receipt/invoice as discrete order columns or a new entity.** The `Invoice` row + audit log + `EmailLog` (via `X-Order-Id`) already record it.
- **Reminder-schedule or request-email changes.** Spec 051's request + D+2/D+5 reminders are unchanged; this is purely the confirmation side.
- **Changing `DebtPaymentService` / the webhook / the FIO handler.** They call `markDebtPaid` unchanged; the event recording lives in the entity.

## Open questions

None — proceed.
</content>
</invoke>
