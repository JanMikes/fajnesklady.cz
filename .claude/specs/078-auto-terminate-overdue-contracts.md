# 078 — Auto-terminate contracts N days po splatnosti (VOP čl. XI), configurable in platform settings

**Status:** done
**Type:** feature (billing lifecycle + ops)
**Scope:** medium (~14 files + 1 migration in this repo, +1 crontab line in the `lily.srv` repo)
**Depends on:** spec 076 (in-progress — touches the same files: `Contract`, `ContractRepository`, retry/termination tests). Implement AFTER 076 lands to avoid conflicts.

## Problem

VOP čl. XI gives the landlord the right to terminate without notice when the tenant is in arrears for more than 7 days ("Nájemce nezaplatil sjednané nájemné … a je v prodlení úhradou po dobu více jak sedm (7) dní"). Spec 055 implemented this **only for the card (AUTO_RECURRING) track**: `RetryFailedPaymentsCommand` retries at day 3 and day 7 and terminates after the third failed attempt. Every other unpaid contract — MANUAL_RECURRING (bank transfer QR cycles), yearly, and externally-prepaid contracts whose "Předplaceno do" lapsed — is **never auto-terminated**. They accumulate on `/portal/admin/po-splatnosti` forever, occupying storage units for free. Production already has a backlog of such missed contracts. The 7-day threshold is also hardcoded in two places instead of being an admin-configurable platform setting.

## Goal

One configurable rule, one termination authority. `PlatformSettings` gains `overdueTerminationDays` (default 7, admin-editable at `/portal/admin/nastaveni`). A new daily cron `app:terminate-overdue-contracts` terminates **every** active non-free contract whose `nextBillingDate` is ≥ N days in the past — immediately, no extra demand phase (user decision; VOP XI's >7-day-arrears ground requires no výzva, and every track already sends pre-termination reminder emails). This includes the existing production backlog on its first run. The card retry track keeps retrying but **stops terminating** — the new cron owns termination for non-payment across all payment types. Customer receives the existing "Smlouva ukončena z důvodu neuhrazení platby" email (updated to cite VOP čl. XI + čl. X and to stop assuming card wording); every admin receives the existing "DLUH: Smlouva ukončena" email. A crontab entry is added to the `lily.srv` repo.

## Context (current state)

- **`Contract.nextBillingDate` is the universal "payment due & unpaid" anchor.** It is only advanced by `Contract::recordBillingCharge()` (`src/Entity/Contract.php:223-231`) — via GoPay webhook reconciliation and the FIO bank-transaction cron (every 15 min). While a cycle is unpaid it stays at the due date for AUTO and MANUAL alike. Externally-prepaid contracts get `nextBillingDate = paidThroughDate + 1 day` (`Contract::markExternallyPrepaid()`, `src/Entity/Contract.php:459-463`). ONE_TIME contracts have it `NULL` (paid upfront) — automatically excluded.
- `recordBillingCharge()` also resets `failedBillingAttempts` and clears `paymentDemandSentAt` — a payment received day 6 removes the contract from all candidate sets.
- **Card track today** — `src/Console/RetryFailedPaymentsCommand.php`: `findNeedingRetry()` (attempts 1 → +3d, attempts 2 → +4d, `src/Repository/ContractRepository.php:482-502`), formal "Výzva k úhradě" at attempt 2 (`PaymentDemandSent` event, `paymentDemandSentAt` guard, lines 143-171), and `terminateForPaymentDefault()` (lines 204-241): cancel recurrence → `calculateOutstandingDebt` → `setOutstandingDebt` → `terminateContract(PAYMENT_FAILURE)` → flush → `ContractTerminatedDueToPaymentFailure`.
- **`ContractService::terminateContract()`** (`src/Service/ContractService.php:61-84`) already voids a live GoPay token, handles handover-aware storage release, and audit-logs via `AuditLogger::logContractTerminated()`. `calculateOutstandingDebt()` (lines 91-111) prorates `paidThroughDate → termination date` at `getEffectiveMonthlyAmount() / 30`.
- **Emails already exist** (spec 055): `ContractTerminatedDueToPaymentFailure` → `src/Event/SendPaymentDefaultEmailHandler.php` sends `email/payment_default_tenant.html.twig` to the customer AND `email/payment_default_admin.html.twig` to every `ROLE_ADMIN`. The tenant template's wording is card-specific ("opakované pokusy o stržení platby", lines 31-32) and cites neither VOP čl. XI nor the čl. X vacating obligation.
- **`PlatformSettings`** singleton (`src/Entity/PlatformSettings.php`) currently holds only `bankTransferSurchargeInHaler`; edited via `AdminSettingsController` → `PlatformSettingsFormData`/`FormType` → `UpdatePlatformSettingsCommand`/`Handler`; template `templates/admin/settings.html.twig` hand-places `form_row`s. `PlatformSettingsRepository::getSettings()` bootstraps the singleton (documented flush exception).
- **Overdue detection** — `OverdueChecker` + `ContractRepository::findWithPaymentIssues()` (`src/Repository/ContractRepository.php:538-563`) drive the admin dashboard; free contracts (`Contract::isFree()`, `individualMonthlyAmount === 0`) are filtered out in PHP. Mirror both patterns.
- **Existing termination cron** `app:process-contract-terminations` (06:00) handles `terminatesAt`/`endDate`-based termination (EXPIRED / TENANT_NOTICE) — untouched; a contract that is both expired and overdue is reaped there first (06:00 < 12:30), which is correct.
- **Cron infra**: `~/www/lily.srv/apps/fajnesklady/cron.d/fajnesklady` (separate repo), one line per job wrapped in `lily-cron-run` + `sentry-cli monitors run`; installed to `/etc/cron.d/fajnesklady` by that repo's `deploy.sh install_cron`. Card charges run 07:00, retries 12:00 Prague.
- **Console-command conventions**: outside the `doctrine_transaction` middleware → explicit `flush()` with inline justification, per-item try/catch with `$this->doctrine->resetManager()` (see `ProcessContractTerminationsCommand` / `RetryFailedPaymentsCommand`, and `.claude/MESSENGER.md` §failure-recording).

## Architecture

```
                     due date D (= Contract.nextBillingDate, unpaid ⇒ never advances)
                              │
  AUTO_RECURRING (card):  D+0 charge fails → D+3 retry + "Výzva k úhradě" → D+7 final retry (12:00)
  MANUAL_RECURRING:       D−7/−2/0 requests → D+3/D+7 overdue chases (existing, unchanged)
  EXTERNAL prepaid:       "ending soon" email 7 days before paidThroughDate (existing, unchanged)
                              │
                              ▼
  NEW  app:terminate-overdue-contracts  (daily 12:30 Prague, after the 12:00 final retry)
       ├─ N = PlatformSettings.overdueTerminationDays (default 7, min 7)
       ├─ candidates: terminatedAt IS NULL ∧ nextBillingDate ≤ now − N days ∧ !isFree()
       └─ per contract: outstanding debt → terminateContract(PAYMENT_FAILURE) [voids token]
                        → flush → ContractTerminatedDueToPaymentFailure
                              │
                              ▼
       SendPaymentDefaultEmailHandler (existing): customer + all admins
```

`RetryFailedPaymentsCommand` loses its termination branch — it only retries and records failures. Running at 12:30 (30 min after the final retry) guarantees the card customer's last charge attempt happens before the sweep; a successful retry advances `nextBillingDate`, so the fresh 12:30 query no longer matches. Cron fires mid-day against a midnight `nextBillingDate`, so on calendar day N the arrears are N days + ~12.5 h — strictly "více jak sedm (7) dní" for N = 7.

## Requirements

### 1. `PlatformSettings.overdueTerminationDays` + migration

`src/Entity/PlatformSettings.php`:

```php
#[ORM\Column(options: ['default' => 7])]
public private(set) int $overdueTerminationDays = 7;

public function updateOverdueTerminationDays(int $days, \DateTimeImmutable $now): void
{
    $this->overdueTerminationDays = $days;
    $this->updatedAt = $now;
}
```

Generate the migration via `docker compose exec web bin/console make:migration` (NOT NULL DEFAULT 7 backfills the existing singleton row).

### 2. Settings command, form, controller, template

- `src/Command/UpdatePlatformSettingsCommand.php`: add `public int $overdueTerminationDays` to the constructor. Handler calls `updateOverdueTerminationDays($command->overdueTerminationDays, $now)` alongside the existing surcharge update.
- `src/Form/PlatformSettingsFormData.php`:

```php
#[Assert\NotNull(message: 'Zadejte počet dní.')]
#[Assert\Range(min: 7, max: 60, notInRangeMessage: 'Počet dní musí být mezi {{ min }} a {{ max }}.')]
public ?int $overdueTerminationDays = null;
```

  populated in `fromSettings()`. **Min 7 is the VOP floor** — čl. XI only permits no-notice termination after more than 7 days of arrears; a lower value would be an unlawful termination. Add a one-line comment saying exactly that above the constraint.
- `src/Form/PlatformSettingsFormType.php`: `IntegerType` field, `label: 'Automatické ukončení smlouvy po splatnosti (dny)'`, `help: 'Smlouva je automaticky ukončena bez výpovědní doby, pokud platba není přijata do tohoto počtu dní po splatnosti (VOP čl. XI). Minimum je 7 dní.'`.
- `src/Controller/Admin/AdminSettingsController.php:37`: pass the new value into `UpdatePlatformSettingsCommand` (non-null after validation; cast defensively like `toHaler()` does).
- `templates/admin/settings.html.twig`: below the "Bankovní převody" heading block (inside the same `form_start`/`form_end`), add a second section:

```twig
<h2 class="text-lg font-semibold mb-4 mt-8">Platby po splatnosti</h2>
<div class="mb-4">
    {{ form_row(form.overdueTerminationDays) }}
</div>
```

### 3. `ContractRepository::findOverdueForTermination()`

New method (place next to `findWithPaymentIssues`, mirror its fetch-joins so the debt calc + audit log don't lazy-load per row):

```php
/**
 * Contracts in arrears long enough for VOP čl. XI no-notice termination:
 * payment was due (nextBillingDate) and has not been received for the
 * platform-configured number of days. Covers AUTO (failed charges),
 * MANUAL (unpaid cycle), and externally-prepaid (lapsed paidThroughDate)
 * alike — nextBillingDate only advances when a payment is recorded.
 * ONE_TIME contracts (nextBillingDate NULL) never match.
 *
 * @return Contract[]
 */
public function findOverdueForTermination(\DateTimeImmutable $overdueSince): array
```

DQL: `SELECT c, u, s, st, p, o` with the same `leftJoin`s as `findWithPaymentIssues()`, `WHERE c.terminatedAt IS NULL AND c.nextBillingDate IS NOT NULL AND c.nextBillingDate <= :overdueSince`, ordered by `c.nextBillingDate ASC`. Free contracts are filtered in PHP by the caller (mirrors `OverdueChecker`, keeps the "free" definition in one place: `Contract::isFree()`).

### 4. New console command `app:terminate-overdue-contracts`

New file `src/Console/TerminateOverdueContractsCommand.php`, `#[AsCommand(name: 'app:terminate-overdue-contracts', description: 'Terminate contracts overdue past the platform-configured limit (VOP čl. XI)')]`. Dependencies: `ContractRepository`, `ContractService`, `PlatformSettingsRepository`, `ManagerRegistry`, event bus (`#[Autowire(service: 'event.bus')]`), `ClockInterface`, `LoggerInterface`. Copy the `getEntityManager()` helper + per-contract resilience pattern from `ProcessContractTerminationsCommand` verbatim.

```php
$now = $this->clock->now();
$days = $this->settingsRepository->getSettings()->overdueTerminationDays;
$overdueSince = $now->modify(sprintf('-%d days', $days));

$contracts = array_filter(
    $this->contractRepository->findOverdueForTermination($overdueSince),
    static fn (Contract $c): bool => !$c->isFree(),
);

foreach ($contracts as $contract) {
    try {
        $outstandingDebt = $this->contractService->calculateOutstandingDebt($contract, $now);
        if ($outstandingDebt > 0) {
            $contract->setOutstandingDebt($outstandingDebt);
        }

        // Voids a live GoPay token, releases storage (handover-aware), audit-logs.
        $this->contractService->terminateContract($contract, $now, TerminationReason::PAYMENT_FAILURE);

        // Console commands are outside the doctrine_transaction middleware,
        // so we must flush explicitly to persist the termination.
        $this->getEntityManager()->flush();

        $this->eventBus->dispatch(new ContractTerminatedDueToPaymentFailure(
            contractId: $contract->id,
            outstandingDebtAmount: $outstandingDebt,
            occurredOn: $now,
        ));
    } catch (\Throwable $e) {
        $this->logger->error('Overdue contract termination failed', [
            'contract_id' => $contract->id->toRfc4122(),
            'exception' => $e,
        ]);
        $this->doctrine->resetManager();
    }
}
```

SymfonyStyle output mirroring the sibling commands (found count / per-contract `[OK] … terminated, N dní po splatnosti, dluh X Kč` / summary). No new event, no demand phase — reuse `ContractTerminatedDueToPaymentFailure` end to end.

### 5. `RetryFailedPaymentsCommand` stops terminating (unification)

`src/Console/RetryFailedPaymentsCommand.php`:

- Delete `terminateForPaymentDefault()` and the `if ($isExpectedFailure && $isLastAttempt)` termination branch (lines 85-88 collapse into the plain `[RETRY LATER]`-style logging; after attempts hit 3 the contract simply stops matching `findNeedingRetry()` and waits ≤ 24 h for the 12:30 sweep). Remove the now-unused `CancelRecurringPaymentCommand` dispatch/import and `ContractTerminatedDueToPaymentFailure` import.
- Keep `$isLastAttempt` in the audit payload (it still marks "no more retries will happen").
- The attempt-2 "Výzva k úhradě" block stays, but its deadline must reflect the configurable N instead of the hardcoded `+4 days`: inject `PlatformSettingsRepository` and compute

```php
$days = $this->settingsRepository->getSettings()->overdueTerminationDays;
/** @var \DateTimeImmutable $dueDate */
$dueDate = $contract->nextBillingDate; // set while a charge is unpaid
$deadline = max($now, $dueDate->modify(sprintf('+%d days', $days)));
```

- Update the command description: `'Retry failed recurring payments (day 3 and day 7); termination is handled by app:terminate-overdue-contracts'`.

Net effect with the default N=7: identical timeline to today (final retry 12:00, termination 12:30 the same day). Changing the setting now applies to card customers too.

### 6. Termination email copy — VOP citations, payment-method-aware

`src/Event/SendPaymentDefaultEmailHandler.php`: add to both tenant and admin template contexts:

```php
'isCardPayment' => BillingMode::AUTO_RECURRING === $contract->billingMode,
```

`templates/email/payment_default_tenant.html.twig` — replace the alert-box body (lines 31-32) with:

```twig
<p>Vaše smlouva na pronájem skladovací jednotky byla <strong>ukončena bez výpovědní doby z důvodu neuhrazení nájemného</strong>, v souladu s čl. XI Všeobecných obchodních podmínek (prodlení s úhradou delší než sedm dní).</p>
{% if isCardPayment %}
    <p>I přes opakované pokusy o stržení platby z Vaší karty se úhradu nepodařilo provést.</p>
{% else %}
    <p>Platba za aktuální období nebyla ani po splatnosti připsána na náš účet.</p>
{% endif %}
```

and add a vacating-obligation paragraph after the storage-details table (before the "Pokud se jednalo o omyl…" line):

```twig
<p><strong>Upozornění dle čl. X VOP:</strong> Skladovací jednotku jste povinni vyklidit do 15 kalendářních dnů od ukončení smlouvy. Po uplynutí této lhůty je Pronajímatel oprávněn jednotku vyklidit na Vaše náklady.</p>
```

`templates/email/payment_default_admin.html.twig`: add one row to its details table — `Způsob platby: {{ isCardPayment ? 'Karta (GoPay)' : 'Bankovní převod / externí' }}`.

All Czech text with full diacritics, as written above.

### 7. Crontab entry (lily.srv repo)

Append to `~/www/lily.srv/apps/fajnesklady/cron.d/fajnesklady`, keeping the existing single-line format exactly (12:30 Prague — after `retry-failed-payments` at 12:00):

```
30 12 * * * root lily-cron-run fajnesklady terminate-overdue-contracts -- docker compose --file /srv/fajnesklady/compose.yaml run --rm messenger-consumer sentry-cli monitors run --schedule "30 12 * * *" --timezone "Europe/Prague" terminate-overdue-contracts -- bin/console app:terminate-overdue-contracts >> /var/log/lily/fajnesklady-cron.log 2>&1
```

Commit in the `lily.srv` repo per its conventions (file header says it is installed by `deploy.sh install_cron` — deploy or run that step so `/etc/cron.d/fajnesklady` picks it up; do NOT edit on the box).

### 8. Tests

- **New** `tests/Integration/Console/TerminateOverdueContractsCommandTest.php` (MockClock fixed at 2025-06-15 12:00 UTC; build contracts in-test or extend fixtures — see `.claude/FIXTURES.md`):
  - MANUAL_RECURRING contract, `nextBillingDate` 8 days past → terminated with `TerminationReason::PAYMENT_FAILURE`, `outstandingDebtAmount > 0`, persisted (re-fetch from DB), `EmailLog` rows for tenant + admin payment-default emails.
  - Contract 5 days overdue (N=7) → untouched.
  - Free contract (`individualMonthlyAmount = 0`) 30 days overdue → untouched.
  - AUTO_RECURRING contract with live token, 8 days overdue → terminated AND recurrence voided (`goPayParentPaymentId` cleared via `cancelRecurringPayment()` — assert on entity state; GoPay client is the test double already used by `ContractService` tests).
  - Externally-prepaid contract with `paidThroughDate` 9 days past (so `nextBillingDate` 8 days past) → terminated.
  - Setting honored: `overdueTerminationDays = 14`, contract 8 days overdue → untouched; 15 days overdue → terminated.
- **Update** `RetryFailedPaymentsCommandTest`: the last-attempt failure now records `failedBillingAttempts = 3` + `RecurringPaymentFailed` but does NOT terminate; drop/rewrite the termination assertions. Demand-deadline assertion switches to the `nextBillingDate + N days` formula.
- **Settings**: validation test that `overdueTerminationDays = 5` is rejected and `7` accepted (extend the existing `PlatformSettingsFormData`/`AdminSettingsController` test wherever spec 049's coverage lives); `AdminSettingsControllerTest` POST round-trip persists the new value.
- Run **`composer test`** (full suite), not just `composer quality` — controller/template/email changes.

### 9. Docs

- `.claude/specs/PROJECT_MAP.md`: add `app:terminate-overdue-contracts` to the console-commands table; note the changed `app:retry-failed-payments` description; add `overdueTerminationDays` to the `PlatformSettings` entity row.

## Rollout note

The first 12:30 run after deploy terminates the **entire existing backlog** at once (user decision: immediate, no grace). Before deploying, eyeball `/portal/admin/po-splatnosti` and fix any known-stale records (offline payments not recorded, "Předplaceno do" not extended) — a wrong record now means a real termination + customer email. Admins receive one "DLUH: Smlouva ukončena" email per terminated contract.

## Acceptance

- [ ] `/portal/admin/nastaveni` shows "Automatické ukončení smlouvy po splatnosti (dny)" with value 7; saving 5 is rejected with a Czech validation message; saving 14 persists (survives reload).
- [ ] `docker compose exec web bin/console app:terminate-overdue-contracts` against fixtures terminates a ≥8-days-overdue MANUAL contract: DB row has `terminatedAt`, `terminationReason = payment_failure`, positive `outstandingDebtAmount`; storage released (or held for pending handover).
- [ ] Terminated customer's email cites VOP čl. XI, uses non-card wording for bank-transfer contracts, and includes the čl. X 15-day vacating notice; every admin gets the DLUH email with the payment-method row.
- [ ] A contract paid on day 6 (nextBillingDate advanced) is not touched by the day-7 run.
- [ ] Free and ONE_TIME contracts are never candidates.
- [ ] `RetryFailedPaymentsCommand` no longer terminates: after the third failed attempt the contract survives until the sweep terminates it the same day (integration test proves the pair).
- [ ] Card contract terminated by the sweep has its GoPay recurrence voided.
- [ ] Crontab line added in `lily.srv` repo, format identical to siblings, schedule `30 12 * * *` Europe/Prague.
- [ ] Migration generated via `make:migration`, `doctrine:schema:validate` clean.
- [ ] `composer quality` green; full `composer test` green.

## Out of scope

- **Unpaid fines** — own reminder track (D+7/D+14, spec 053); a fine alone never terminates a contract.
- **Onboarding debt** (`Order.debtPaidAt`) — pre-completion, own reminder cron (spec 051); no contract billing cycle involved.
- **Unpaid orders** (never paid the first payment) — `app:expire-orders` already handles them via the per-place expiration window.
- **Contracts with `nextBillingDate = NULL` in cancelled-recurring holding states** (spec 018 territory) — unreachable by this sweep's predicate; their termination path is `terminatesAt`/`endDate` via `app:process-contract-terminations`.
- **A demand/grace phase before termination** — user explicitly chose immediate termination; VOP XI's >7-day-arrears ground requires no written demand, and each track already sends pre-termination reminders (card day-3 výzva, manual D+3/D+7 chases, external ending-soon).
- **Per-place override of the N days** — single global setting only.
- **Reworking the manual-billing chase email wording** to mention upcoming termination — nice-to-have, separate copy task.
- **Retroactive výzva or debt-collection workflow for already-terminated debtors** — Po splatnosti dashboard covers visibility; collection is out of scope (as in 055).

## Open questions

None — proceed. (Three design decisions confirmed by user 2026-07-02: terminate immediately with no extra demand phase; externally-prepaid contracts included; the setting governs the card track too — `RetryFailedPaymentsCommand` stops terminating.)
