# 078 — Mark unmatched bank transactions as non-related (ignore / unignore)

**Status:** done
**Type:** feature (admin UX — wiring up dormant domain code)
**Scope:** small (~9 files: entity tweak, 2 commands + 2 handlers, repo, 2 new controllers, 1 controller touch, template, tests)
**Depends on:** none (spec 049 already shipped the domain layer)

## Problem

The company FIO account receives incoming transfers that have nothing to do with orders — operational payments, refunds, transfers between own accounts. The FIO cron records them as `unmatched` `BankTransaction` rows and they sit in the admin "Bankovní platby" list forever, inflating the "Nespárované" count so the admin can never tell "inbox zero" from "payments needing attention". Spec 049 designed an "Ignorovat" action and shipped the domain methods — `BankTransaction::markIgnored()` / `unignore()`, the `ignoreReason` column, unit tests, even the "Ignorováno" badge branch in the template — but the controller/UI layer was never built. The methods are dead code today.

## Goal

Admin can mark an unmatched transaction as non-related (with an optional note), which removes it from the default list view and from the "Nespárované ({N})" count. A new "Ignorované" filter chip shows the hidden transactions; each has an "Obnovit" action that returns it to `unmatched`. Fully reversible, audit-logged, no password gate.

## Context (current state)

- `src/Entity/BankTransaction.php:115-129` — `markIgnored(User $admin, string $reason, \DateTimeImmutable $now)` and `unignore()` exist but are called from nowhere in `src/`. Status is a plain string column: `unmatched | matched | amount_mismatch | ignored`. `ignoreReason` column (500, nullable) already exists — **no migration needed**.
- `src/Repository/BankTransactionRepository.php:44-57` — `findAll(string $statusFilter = 'all')`; `'all'` currently returns every status **including ignored**. `countUnmatched()` counts only `status = 'unmatched'`, so ignoring a row already shrinks the chip count with no extra work.
- `src/Controller/Admin/AdminBankPaymentsController.php` — read-only list at `/portal/admin/bankovni-platby`, `filter` from query string, default `'all'`.
- `templates/admin/bank_payments/index.html.twig` — chips Vše / Nespárované / Spárované / Nesouhlasí částka; the status cell already renders `badge-ghost` "Ignorováno" for `status == 'ignored'` (lines 66-68). There is **no actions column** — the whole table is read-only.
- Write convention: POST single-action controller → command bus. Mirror `src/Controller/Admin/AdminFineCancelController.php` + `src/Command/CancelFineHandler.php` (load in handler via `EntityManager::find`, guard, mutate, `AuditLogger::log()`), **minus the password gate** — fine cancellation is destructive, ignoring is reversible.
- `AuditLogger::log()` (`src/Service/AuditLogger.php:355`) — generic public method; `payload`, `orderId`, `userIdContext` all optional. Actor (current user) is captured automatically from `Security`.
- Modal pattern: `templates/components/_danger_modal.html.twig` uses native `<dialog class="modal">` + `showModal()`/`close()` inline handlers. `modal`, `modal-box`, `modal-action`, `modal-backdrop`, `btn`, `btn-ghost`, `btn-error` are all proven-defined in `assets/styles/app.css` (design system is hand-rolled — do NOT introduce any other `btn-*`/`modal-*` class without checking `app.css` first).
- FIO cron (`src/Command/ProcessIncomingBankTransactionHandler.php`) only processes **new** FIO transactions (`existsByFioTransactionId` guard at ingest). It never re-touches existing rows → an ignored row stays ignored; an unignored row is **not** re-auto-matched (auto-matching happens only at ingest). This is fine — unignore exists for mis-clicks, and manual re-pairing is out of scope.
- Existing tests: `tests/Unit/Entity/BankTransactionTest.php` (covers `markIgnored`/`unignore`), `tests/Integration/Controller/Admin/AdminBankPaymentsControllerTest.php` (has a `createBankTransaction()` helper to reuse). No bank-transaction fixtures exist — tests create rows ad hoc.

**Decisions made (user AFK — defaults chosen, flag before implementing if you disagree):**
- Ignore note is **optional** (spec 049 said mandatory; relaxed because operational transfers are frequent and self-evident). Entity param widens `string $reason` → `?string $reason`.
- **No auto-ignore rules** by sender account — manual only; revisit if it becomes a monthly chore.
- Only `unmatched` transactions can be ignored. `amount_mismatch` rows are paired to an order — they ARE related; hiding them would hide real customer money.
- Default "Vše" chip excludes ignored rows (that's the "default list" the user wants them hidden from); only the new "Ignorované" chip shows them.

## Requirements

### 1. Entity — optional reason

`src/Entity/BankTransaction.php:115` — widen the signature:

```php
public function markIgnored(User $admin, ?string $reason, \DateTimeImmutable $now): void
```

Body unchanged (`$this->ignoreReason = $reason;` accepts null). Update `tests/Unit/Entity/BankTransactionTest.php` accordingly and add a null-reason case.

### 2. Commands + handlers

`src/Command/IgnoreBankTransactionCommand.php`:

```php
final readonly class IgnoreBankTransactionCommand
{
    public function __construct(
        public Uuid $transactionId,
        public Uuid $adminId,
        public ?string $reason,
    ) {}
}
```

`src/Command/IgnoreBankTransactionHandler.php` — mirror `CancelFineHandler` exactly:
- `EntityManager::find()` transaction + admin, `\DomainException` when missing.
- Guard: `if (!$transaction->isUnmatched()) { throw new \DomainException('Only unmatched transactions can be ignored.'); }`
- `$transaction->markIgnored($admin, $command->reason, $this->clock->now());`
- Audit: `entityType: 'bank_transaction'`, `entityId: $transactionId->toRfc4122()`, `eventType: 'ignored'`, payload: `fio_transaction_id`, `amount`, `variable_symbol`, `reason`. Omit `orderId`/`userIdContext` (no order relation — that's the point).

`src/Command/UnignoreBankTransactionCommand.php` (`transactionId`, `adminId`) + `UnignoreBankTransactionHandler.php`:
- Guard `isIgnored()`, capture `$transaction->ignoreReason` into the audit payload **before** calling `unignore()` (it nulls the field), then `$transaction->unignore();`, audit `eventType: 'unignored'`.

### 3. Repository

`src/Repository/BankTransactionRepository.php`:

```php
public function findAll(string $statusFilter = 'all'): array
{
    // ...
    if ('all' === $statusFilter) {
        $qb->where('bt.status != :ignored')->setParameter('ignored', 'ignored');
    } else {
        $qb->where('bt.status = :status')->setParameter('status', $statusFilter);
    }
    // ...
}
```

Add `countIgnored(): int` — copy of `countUnmatched()` with `'ignored'`.

### 4. Controllers

`src/Controller/Admin/AdminBankTransactionIgnoreController.php`:
- `#[Route('/portal/admin/bankovni-platby/{id}/ignorovat', name: 'admin_bank_transaction_ignore', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]`, `#[IsGranted('ROLE_ADMIN')]`, `#[CurrentUser] User $admin`.
- Load via `BankTransactionRepository::find()`; `NotFoundHttpException` when null.
- Controller-side guard before dispatch (mirrors the fine-cancel UX; the handler guard is the backstop): if `!$tx->isUnmatched()` → `addFlash('error', 'Ignorovat lze pouze nespárované transakce.')` + redirect.
- Reason: `$reason = trim($request->request->getString('reason')); $reason = '' === $reason ? null : $reason;`
- Dispatch, `addFlash('success', 'Transakce byla označena jako nesouvisející.')`.
- Redirect to `admin_bank_payments` preserving the source view: read hidden `filter` from the POST body, pass it back as query param when non-`'all'`.

`src/Controller/Admin/AdminBankTransactionUnignoreController.php`:
- `#[Route('/portal/admin/bankovni-platby/{id}/obnovit', name: 'admin_bank_transaction_unignore', ...)]`, same shape.
- Guard `isIgnored()` → error flash 'Obnovit lze pouze ignorované transakce.'
- Success flash 'Transakce byla vrácena mezi nespárované.', redirect to `admin_bank_payments` with `['filter' => 'ignored']` (stay on the ignored view).

`src/Controller/Admin/AdminBankPaymentsController.php` — add `'ignoredCount' => $this->bankTransactionRepository->countIgnored()` to the render params.

### 5. Template

`templates/admin/bank_payments/index.html.twig`:

- New chip after "Nesouhlasí částka" (gray palette — visually "archived"):

```twig
<a href="{{ path('admin_bank_payments', {filter: 'ignored'}) }}" class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-medium {{ filter == 'ignored' ? 'bg-gray-600 text-white' : 'bg-gray-100 text-gray-500 hover:bg-gray-200' }}">
    Ignorované{% if ignoredCount > 0 %} ({{ ignoredCount }}){% endif %}
</a>
```

- New last column `<th>Akce</th>`:
  - `tx.isUnmatched` → button `btn btn-ghost btn-sm` "Ignorovat" with `onclick="document.getElementById('ignore-{{ tx.id }}').showModal()"`, plus a per-row `<dialog id="ignore-{{ tx.id }}" class="modal">` mirroring `_danger_modal.html.twig` markup **without** the password block: title "Označit jako nesouvisející", body "Transakce bude skryta z výchozího seznamu. Akci lze kdykoli vrátit ve filtru Ignorované."; form POSTs to `path('admin_bank_transaction_ignore', {id: tx.id})` with optional `<textarea name="reason" maxlength="500" class="form-input" placeholder="Např. provozní platba, převod mezi vlastními účty (nepovinné)">` and `<input type="hidden" name="filter" value="{{ filter }}">`; actions: Zrušit (`btn btn-ghost`, `close()`) + submit "Ignorovat" (`btn btn-error` — the only filled button variant confirmed in `app.css`; check there before substituting another).
  - `tx.isIgnored` → plain inline POST form to `admin_bank_transaction_unignore`, single button `btn btn-ghost btn-sm` "Obnovit".
  - Other statuses → `—`.
- Status cell: under the existing "Ignorováno" badge, show the note when present:

```twig
{% if tx.ignoreReason %}
    <div class="text-xs text-gray-400 max-w-[16rem] truncate" title="{{ tx.ignoreReason }}">{{ tx.ignoreReason }}</div>
{% endif %}
```

Czech UI text with full diacritics throughout.

### 6. Tests

- Unit: extend `tests/Unit/Entity/BankTransactionTest.php` for the nullable reason.
- Integration — new `tests/Integration/Controller/Admin/AdminBankTransactionIgnoreControllerTest.php` and `...UnignoreControllerTest.php` (reuse the `createBankTransaction()` helper style from `AdminBankPaymentsControllerTest`):
  - Ignore: admin POST on unmatched → 302 to list, status `ignored`, reason persisted (and a second case with empty reason → `ignoreReason` null); POST on matched tx → row unchanged + error flash; unauthenticated → redirect `/login`; `ROLE_USER` → 403.
  - Unignore: admin POST on ignored → status `unmatched`, `ignoreReason`/`pairedBy`/`pairedAt` null; POST on unmatched → error flash; auth cases as above.
- Extend `AdminBankPaymentsControllerTest`: default list (no filter) does NOT render an ignored transaction's sender name; `?filter=ignored` does.
- **Run full `composer test`** (controllers + templates changed — `composer quality` alone skips integration tests).

## Acceptance

- [ ] On `/portal/admin/bankovni-platby`, an unmatched row has an "Ignorovat" action; confirming (with or without a note) hides the row from "Vše" and "Nespárované" and decrements the "Nespárované (N)" chip count.
- [ ] New "Ignorované (N)" chip lists hidden transactions with badge, note (truncated, full text in `title`), and an "Obnovit" action.
- [ ] "Obnovit" returns the transaction to `unmatched` (note cleared) and it reappears in the default list.
- [ ] Matched / amount_mismatch rows have no ignore action; forced POST against them changes nothing and flashes an error.
- [ ] Both actions write `bank_transaction` audit rows (`ignored` with reason in payload / `unignored`).
- [ ] No migration generated (schema untouched); `bin/console doctrine:schema:validate` still green.
- [ ] `composer quality` green AND full `composer test` green.

## Out of scope

- **Manual pairing UI** (the other unshipped half of spec 049's modal) — separate feature, different flow; ignore/unignore does not need it.
- **Auto-ignore rules by sender account** — deliberately deferred; add only if re-ignoring recurring operational transfers becomes a chore.
- **Bulk ignore** (checkbox multi-select) — volume doesn't justify it yet.
- **Ignoring `amount_mismatch` transactions** — they're order-paired, i.e. related money; they have their own promote/resolve path.
- **Password gate / danger modal** — action is fully reversible; the gate is reserved for destructive actions.
- **Nav or dashboard badges** — the unmatched count only surfaces on the page's own chip today; nothing else to update.

## Open questions

None — proceed. (Two defaults were chosen while the user was away — optional note, no auto-ignore rules — both flagged in Context; confirm with the user only if implementation reveals a conflict.)
