# 055 — Overhaul contract termination (fix bugs, VOP XI compliance, admin UI, payment demand flow)

**Status:** ready
**Type:** feature + bugfix + VOP compliance
**Scope:** large (~30 files: 3 controllers, 4 commands/handlers, 3 events/handlers, 6 email templates, 4 Twig templates, 1 entity change, 1 migration, 2 console command fixes, tests)
**Depends on:** none

## Problem

Contract termination is fundamentally broken across all three use cases:

1. **Customer termination (výpověď ze strany zákazníka):** Works at the controller level, but admin gets no notification, and the termination email to the customer is too generic (no VOP obligations, no vacating deadline per VOP X).

2. **Admin termination (výpověď ze strany admina):** **Completely broken — always 403.** Both `templates/admin/order/detail.html.twig:473` and `templates/portal/landlord/order/detail.html.twig:384` POST to `portal_user_contract_terminate`, which checks `$contract->user->id->equals($user->id)` — admin isn't the contract owner, so it throws `AccessDeniedHttpException`. The `TerminationReason::ADMIN` enum case exists but no code path ever sets it.

3. **Automatic termination for non-payment (automatické ukončení):** **Two critical bugs.** First, `ProcessContractTerminationsCommand` never calls `flush()` after modifying entity state, so scheduled terminations (expired LIMITED + user-notice UNLIMITED) are **never persisted to the database** — the cron runs, sends duplicate emails on every run, but nothing changes. Second, there's no formal "výzva k úhradě" before auto-termination, violating VOP XI which requires a written notice to remedy.

**VOP XI compliance gap (CRITICAL):** The VOP says the landlord can terminate without notice when the tenant is >7 days in arrears, but also generally requires "písemná výzva k nápravě" (written demand to remedy) before termination for any breach. The current system silently retries GoPay charges then terminates without ever sending a formal payment demand email. The retry timing (0 → 3 → 10 days) also doesn't align with the VOP's 7-day rule.

## Goal

All three termination paths work correctly, communicate clearly with the customer, notify the admin, and comply with VOP XI:
- Customer can terminate unlimited contracts with 1-month notice (working, now with better emails + admin notification)
- Admin can terminate any contract via dedicated UI: with 1-month notice OR immediately for breach (new)
- Auto-termination for non-payment: flush bug fixed, formal demand email at day 3, termination at day 7, VOP-compliant timing

## Context (current state)

### Entity & enum
- `src/Entity/Contract.php` — `terminate()`, `requestTermination()`, `hasPendingTermination()`, `isTerminationDue()`, `needsRetry()`, `cancelRecurringPayment()`. Implements `EntityWithEvents` + `HasEvents`.
- `src/Enum/TerminationReason.php` — `EXPIRED`, `TENANT_NOTICE`, `PAYMENT_FAILURE`, `ADMIN` (unused).

### Controllers
- `src/Controller/Portal/User/ContractTerminateController.php` — POST `/portal/smlouvy/{id}/ukoncit`. Checks user ownership (line 47), unlimited-only (line 51), canTerminate (line 57), pending check (line 63). Dispatches `RequestTerminationNoticeCommand`.
- No admin termination controller exists.

### Commands/handlers
- `src/Command/RequestTerminationNoticeCommand.php` + `RequestTerminationNoticeHandler.php` — sets `terminatesAt = +1 month`, dispatches `TerminationNoticeRequested` event.
- `src/Command/CancelRecurringPaymentCommand.php` + `CancelRecurringPaymentHandler.php` — voids GoPay recurrence.

### Console commands
- `src/Console/ProcessContractTerminationsCommand.php` — finds contracts via `findDueForTermination()`, calls `contractService->terminateContract()`, dispatches `ContractTerminated`. **BUG: no flush().** Compare with `RetryFailedPaymentsCommand` (lines 114, 165) which explicitly flushes.
- `src/Console/RetryFailedPaymentsCommand.php` — retries failed payments. At attempt 3 (line 82), calls `terminateForPaymentDefault()` which cancels recurring, calculates debt, terminates, flushes, dispatches `ContractTerminatedDueToPaymentFailure`.

### Voter
- `src/Service/Security/ContractVoter.php` — `TERMINATE`: admins → true (line 39-40); users → own unlimited non-terminated only (line 46-48); landlords → false (line 58-59).

### Events & email handlers
- `TerminationNoticeRequested` → `SendTerminationNoticeEmailHandler` (customer only, no admin)
- `ContractTerminated` → `SendContractTerminatedEmailHandler` (customer only, generic text)
- `ContractTerminatedDueToPaymentFailure` → `SendPaymentDefaultEmailHandler` (customer + admin)

### Templates with bugs
- `templates/admin/order/detail.html.twig:473` — form POSTs to `portal_user_contract_terminate` (wrong route)
- `templates/portal/landlord/order/detail.html.twig:384` — same wrong route (and landlord voter blocks anyway)

### Repository
- `ContractRepository::findDueForTermination()` (line 466) — unlimited with `terminatesAt <= now` OR limited with `endDate <= now`, excludes terminated.
- `ContractRepository::findNeedingRetry()` (line 438) — filters `goPayParentPaymentId IS NOT NULL`, attempt 1 retry after 3d, attempt 2 retry after 7d.

### VOP rules (source: `templates/public/_terms_and_conditions_content.html.twig`, Articles IV, VI, X, XI)
- **Article IV**: Auto-extension stopped when tenant is in arrears with ANY payment.
- **Article VI**: "Pokud Nájemce Předmět nájmu vyklidí dříve... nevzniká mu nárok na vrácení nájemného, a to ani v poměrné části." → **No refunds.**
- **Article X**: After contract ends, tenant has 15 calendar days to vacate. After that, landlord may enter and evict at tenant's cost. Items stored for max 30 days.
- **Article XI**: Landlord can terminate with 1-month notice (no reason) OR immediately for breach (incl. >7 days arrears). Requires "písemná výzva k nápravě" for general breaches.

### Podmínky opakovaných plateb (Article VI)
- Cancelling recurring payment doesn't end the contract or waive payment obligations.

## Requirements

### 1. Fix `ProcessContractTerminationsCommand` flush bug

**File:** `src/Console/ProcessContractTerminationsCommand.php`

Add `ManagerRegistry` dependency (mirror `RetryFailedPaymentsCommand` pattern). After `contractService->terminateContract()` and before event dispatch, call `flush()`. Wrap in try/catch with `$this->doctrine->resetManager()` on failure (same resilience pattern as `RetryFailedPaymentsCommand`).

```php
// After line 62 (terminateContract call), before line 64 (event dispatch):
$this->getEntityManager()->flush();
```

Add the `getEntityManager()` helper method and `ManagerRegistry` dependency identical to `RetryFailedPaymentsCommand`.

### 2. New admin termination controller + command

**New file:** `src/Controller/Admin/AdminContractTerminateController.php`

```php
#[Route('/portal/admin/contracts/{id}/terminate', name: 'admin_contract_terminate', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminContractTerminateController extends AbstractController
```

POST parameters:
- `termination_type`: `'immediate'` or `'with_notice'` (required)
- `reason`: free text (optional, max 500 chars)

Logic:
- Validate contract UUID, load contract via `ContractRepository::get()`
- Check `!$contract->isTerminated()` and `!$contract->hasPendingTermination()` (if with_notice)
- Dispatch `AdminTerminateContractCommand`
- Flash + redirect to `admin_order_detail`

**New file:** `src/Command/AdminTerminateContractCommand.php`

```php
final readonly class AdminTerminateContractCommand
{
    public function __construct(
        public Contract $contract,
        public bool $immediate,
        public ?string $reason = null,
    ) {}
}
```

**New file:** `src/Command/AdminTerminateContractHandler.php`

```php
#[AsMessageHandler]
final readonly class AdminTerminateContractHandler
{
    public function __construct(
        private ContractService $contractService,
        private ClockInterface $clock,
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $eventBus,
        private AuditLogger $auditLogger,
    ) {}

    public function __invoke(AdminTerminateContractCommand $command): void
    {
        $contract = $command->contract;
        $now = $this->clock->now();

        if ($command->immediate) {
            $this->contractService->terminateContract($contract, $now, TerminationReason::ADMIN);

            $this->auditLogger->log(
                'Contract',
                $contract->id->toRfc4122(),
                'admin_terminated_immediately',
                ['reason' => $command->reason],
            );

            $this->eventBus->dispatch(new ContractTerminated(
                contractId: $contract->id,
                occurredOn: $now,
            ));
        } else {
            $terminatesAt = $now->modify('+1 month');
            $contract->requestTermination($now, $terminatesAt);

            $this->auditLogger->log(
                'Contract',
                $contract->id->toRfc4122(),
                'admin_termination_notice',
                ['terminates_at' => $terminatesAt->format('Y-m-d'), 'reason' => $command->reason],
            );

            $this->eventBus->dispatch(new TerminationNoticeRequested(
                contractId: $contract->id,
                terminatesAt: $terminatesAt,
                occurredOn: $now,
            ));
        }
    }
}
```

### 3. Fix admin + landlord order detail templates

**File:** `templates/admin/order/detail.html.twig` (lines 464-484)

Replace the termination section. Admin gets a form with radio (type) + textarea (reason) posting to the new `admin_contract_terminate` route:

```twig
{% if canTerminate %}
<div class="mt-6 bg-white shadow overflow-hidden sm:rounded-lg border border-red-200">
    <div class="px-4 py-5 sm:p-6">
        <h3 class="text-lg font-medium text-red-900">Ukončit smlouvu</h3>
        <form action="{{ path('admin_contract_terminate', {id: contract.id}) }}" method="post"
              onsubmit="return confirm('Opravdu chcete ukončit tuto smlouvu? Tato akce je nevratná.');">
            <div class="mt-3 space-y-3">
                <div>
                    <label class="flex items-center gap-2">
                        <input type="radio" name="termination_type" value="with_notice" checked
                               class="text-red-600 focus:ring-red-500">
                        <span class="text-sm text-gray-700">S výpovědní dobou (1 měsíc)</span>
                    </label>
                </div>
                <div>
                    <label class="flex items-center gap-2">
                        <input type="radio" name="termination_type" value="immediate"
                               class="text-red-600 focus:ring-red-500">
                        <span class="text-sm text-gray-700">Okamžitě pro porušení smlouvy</span>
                    </label>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600">Důvod (volitelný)</label>
                    <textarea name="reason" rows="2" maxlength="500"
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm"></textarea>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="inline-flex items-center px-4 py-2 border border-red-300 shadow-sm text-sm font-medium rounded-md text-red-700 bg-white hover:bg-red-50">
                    Ukončit smlouvu
                </button>
            </div>
        </form>
    </div>
</div>
{% endif %}
```

Also show pending termination status:
```twig
{% if contract.hasPendingTermination() %}
<div class="mt-6 bg-amber-50 border border-amber-200 rounded-lg p-4">
    <p class="text-sm text-amber-800">
        <strong>Výpověď podána:</strong> Smlouva bude ukončena k {{ contract.terminatesAt|date('d.m.Y') }}.
    </p>
</div>
{% endif %}
```

**File:** `templates/portal/landlord/order/detail.html.twig` (lines 375-395)

Remove the entire termination section. Landlords cannot terminate contracts (voter already blocks this; removing the dead UI). Replace with a read-only pending-termination info box if applicable:

```twig
{% if contract and contract.hasPendingTermination() %}
<div class="mt-6 bg-amber-50 border border-amber-200 rounded-lg p-4">
    <p class="text-sm text-amber-800">
        <strong>Výpověď podána:</strong> Smlouva bude ukončena k {{ contract.terminatesAt|date('d.m.Y') }}.
    </p>
</div>
{% endif %}
```

**File:** `templates/portal/user/order/detail.html.twig` (lines 257-277)

Keep existing termination button (customer path works). Add pending-termination status display above it:

```twig
{% if contract and contract.hasPendingTermination() %}
<div class="mt-6 bg-amber-50 border border-amber-200 rounded-lg p-4">
    <p class="text-sm text-amber-800">
        <strong>Výpověď podána {{ contract.terminationNoticedAt|date('d.m.Y') }}.</strong>
        Smlouva bude ukončena k {{ contract.terminatesAt|date('d.m.Y') }}.
        Pravidelné platby budou pokračovat do konce výpovědní lhůty.
    </p>
</div>
{% endif %}
```

### 4. New `Contract.paymentDemandSentAt` field + migration

**File:** `src/Entity/Contract.php`

Add field:
```php
#[ORM\Column(nullable: true)]
public private(set) ?\DateTimeImmutable $paymentDemandSentAt = null;
```

Add method:
```php
public function recordPaymentDemandSent(\DateTimeImmutable $now): void
{
    $this->paymentDemandSentAt = $now;
}
```

Generate migration via `docker compose exec web bin/console make:migration`.

### 5. Payment demand event + email handlers

**New file:** `src/Event/PaymentDemandSent.php`

```php
final readonly class PaymentDemandSent
{
    public function __construct(
        public Uuid $contractId,
        public \DateTimeImmutable $deadline,
        public \DateTimeImmutable $occurredOn,
    ) {}
}
```

**New file:** `src/Event/SendPaymentDemandEmailHandler.php`

Handles `PaymentDemandSent`. Sends "Výzva k úhradě" email to customer:
- Subject: "Výzva k úhradě nájemného – Fajnesklady.cz"
- Body: formal demand quoting the unpaid amount, deadline (7 days from original billing date), warning that the contract will be terminated per VOP XI if not paid by the deadline. Include the storage details, place name, contact info (`skladmistr@fajnesklady.cz`), and link to order status page.
- Template: `templates/email/payment_demand_tenant.html.twig`

**New file:** `src/Event/SendPaymentDemandAdminEmailHandler.php`

Handles `PaymentDemandSent`. Sends notification to all admins:
- Subject: "Výzva k úhradě odeslána – {tenantName}"
- Body: which customer, which contract, what amount, deadline
- Template: `templates/email/payment_demand_admin.html.twig`

### 6. Update `RetryFailedPaymentsCommand` — VOP-compliant timing + payment demand

**File:** `src/Console/RetryFailedPaymentsCommand.php`

After the second failure is recorded (attempt 2, day 3), send the formal payment demand:

In `recordRetryFailure()`, after recording `failedBillingAttempts = 2` and the `RecurringPaymentFailed` event dispatch, add:

```php
if ($contract->failedBillingAttempts === 2 && null === $contract->paymentDemandSentAt) {
    $contract->recordPaymentDemandSent($now);
    $this->getEntityManager()->flush();
    
    $deadline = $now->modify('+4 days'); // day 3 + 4 = day 7 from original failure
    $this->eventBus->dispatch(new PaymentDemandSent(
        contractId: $contract->id,
        deadline: $deadline,
        occurredOn: $now,
    ));
}
```

**File:** `src/Entity/Contract.php` — Update `needsRetry()` to align with 7-day VOP rule:

```php
public function needsRetry(\DateTimeImmutable $now): bool
{
    if (!$this->hasActiveRecurringPayment()) {
        return false;
    }
    if (null === $this->lastBillingFailedAt) {
        return false;
    }

    return match ($this->failedBillingAttempts) {
        1 => $now >= $this->lastBillingFailedAt->modify('+3 days'),
        2 => $now >= $this->lastBillingFailedAt->modify('+4 days'),
        default => false,
    };
}
```

This changes the total timeline from 0→3→10 to 0→3→7:
- Day 0: Charge fails → `RecurringPaymentFailed` email (existing)
- Day 3: First retry fails → `RecurringPaymentFailed` email + **formal "Výzva k úhradě" email with 7-day deadline**
- Day 7: Second retry fails → **terminate** (>7 days in arrears, formal demand was sent, VOP XI satisfied)

Also update `ContractRepository::findNeedingRetry()` — change `retryAfter7Days` to `retryAfter4Days`:

```php
$retryAfter4Days = $now->modify('-4 days');
// ...
'(c.failedBillingAttempts = 2 AND c.lastBillingFailedAt <= :retryAfter4Days)'
// ...
->setParameter('retryAfter4Days', $retryAfter4Days)
```

### 7. Admin notification on customer termination

**New file:** `src/Event/SendTerminationNoticeAdminEmailHandler.php`

Handles `TerminationNoticeRequested`. Sends email to all admins:
- Subject: "Zákazník podal výpověď – {customerName}"
- Body: which customer, which storage/place, termination date, link to admin order detail
- Template: `templates/email/termination_notice_admin.html.twig`

### 8. Richer termination emails (reason-aware, VOP obligations)

**File:** `templates/email/contract_terminated.html.twig`

Complete rewrite. The handler already reads the contract from DB, so the template has access to `contract.terminationReason`. Pass `terminationReason` to the template context.

**File:** `src/Event/SendContractTerminatedEmailHandler.php`

Update to pass reason + obligations to template:

```php
->context([
    'name' => $user->fullName,
    'placeName' => $place->name,
    'storageType' => $storageType->name,
    'storageNumber' => $storage->number,
    'terminationReason' => $contract->terminationReason,
    'hasOutstandingDebt' => $contract->hasOutstandingDebt(),
    'outstandingDebt' => $contract->outstandingDebtAmount ? $contract->outstandingDebtAmount / 100 : 0,
    'statusUrl' => $this->statusUrlGenerator->generate($contract->order),
])
```

Template should include:
- Reason-specific header: "na základě Vaší výpovědi" / "rozhodnutím Pronajímatele" / "z důvodu neuhrazení platby" / "uplynutím doby nájmu"
- VOP Article X obligations: "Dle VOP čl. X jste povinni vyklidit skladovací jednotku do 15 kalendářních dnů od ukončení smlouvy."
- Outstanding debt info if applicable
- Contact info: `skladmistr@fajnesklady.cz`, `+420 605 522 566`
- Link to order status page

**File:** `templates/email/termination_notice.html.twig`

Update to include:
- Mention that payments continue until the end of the notice period
- VOP Article X vacating obligation (15 days after termination)
- Link to order status page (add `statusUrl` to handler context)
- Contact info

Update `SendTerminationNoticeEmailHandler` to inject `OrderStatusUrlGenerator` and pass `statusUrl` to template.

### 9. New email templates

Create these new templates (all follow existing email template style — inline CSS, no Twig `extends`):

- `templates/email/payment_demand_tenant.html.twig` — formal payment demand to customer
- `templates/email/payment_demand_admin.html.twig` — payment demand notification to admin
- `templates/email/termination_notice_admin.html.twig` — customer-submitted termination notice to admin

### 10. Reset `paymentDemandSentAt` on successful payment

When a retry charge succeeds (in `RetryFailedPaymentsCommand` or via webhook), the `paymentDemandSentAt` should be cleared so future failures get a fresh demand cycle.

**File:** `src/Entity/Contract.php` — update `recordBillingCharge()`:

```php
public function recordBillingCharge(...): void
{
    // ...existing code...
    $this->paymentDemandSentAt = null; // Reset demand state on successful charge
}
```

### 11. Tests

**Unit tests:**
- `Contract::needsRetry()` with new timing (day 3 for attempt 1, day 7 for attempt 2)
- `AdminTerminateContractHandler` — immediate vs with-notice paths
- `ContractService::terminateContract()` — verify reason propagation

**Integration tests:**
- `AdminContractTerminateControllerTest` — POST immediate, POST with_notice, non-admin 403, already-terminated 409
- `ContractTerminateControllerTest` — existing customer path still works, non-owner 403
- `ProcessContractTerminationsCommandTest` — verify flush (contract is actually persisted as terminated after the command runs)
- `RetryFailedPaymentsCommandTest` — verify payment demand email is dispatched at attempt 2, verify termination at attempt 3 (day 7)

## Acceptance

- [ ] Admin can terminate a contract immediately via admin order detail → no 403
- [ ] Admin can terminate a contract with 1-month notice via admin order detail
- [ ] `TerminationReason::ADMIN` is set when admin terminates immediately
- [ ] Customer can still terminate unlimited contracts via their order detail (existing path unbroken)
- [ ] `ProcessContractTerminationsCommand` persists terminations to DB (flush bug fixed) — verify by running the cron and checking the contract is marked terminated in the DB
- [ ] Formal "Výzva k úhradě" email sent after 2nd payment failure (day 3)
- [ ] Auto-termination happens at day 7 (not day 10) after initial payment failure
- [ ] `paymentDemandSentAt` is cleared on successful payment charge
- [ ] Admin receives email when customer submits termination notice
- [ ] Contract terminated email includes reason-specific text + VOP X obligations (15-day vacating)
- [ ] Termination notice email includes VOP obligations + order status link
- [ ] Payment demand email includes formal language, deadline, amount, contact info
- [ ] Landlord order detail no longer shows (broken) termination button
- [ ] Admin and customer order detail show pending termination status banner
- [ ] `composer quality` is green
- [ ] `composer test` is green (all existing + new tests pass)

## Out of scope

- **14-day consumer withdrawal (VOP VIII / OZ § 1829)**: Legally distinct from "výpověď" — this is "odstoupení od smlouvy," a different legal instrument with different consequences (full refund). Should be a separate spec.
- **Auto-termination for MANUAL_RECURRING / BANK_TRANSFER non-payers**: These appear in the overdue dashboard for admin manual action. The architecture is designed to be easily extensible (the payment demand + termination flow is in reusable services) — a future spec can widen the candidate query to include these payment types.
- **Refund mechanism**: VOP VI explicitly says "no refund even in prorated part" for early vacating. The system correctly does not implement refunds.
- **Customer-facing "cancel recurring" flow changes**: The existing `CancelRecurringPaymentController` (signed URL) is separate from termination and works correctly per Podmínky čl. VI.
- **Outstanding debt collection/recovery**: Debt is recorded but there's no collection workflow. Tracked in the overdue dashboard (spec 023). Separate concern.
- **Landlord-initiated termination**: VOP says "Pronajímatel" (the landlord entity Mekmann s.r.o.) can terminate, but in practice the admin acts on behalf of the landlord. If landlords need direct termination access, that's a separate feature with different security implications.

## Open questions

None — proceed.
