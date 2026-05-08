---
description: Rescan the codebase and rewrite .claude/specs/PROJECT_MAP.md so /spec and /implement always have a fresh orientation reference
argument-hint: (none)
---

You are auditing `.claude/specs/PROJECT_MAP.md` against the current state of the codebase. The map is the orientation reference that `/spec` and `/implement` read first; if it lies, every spec written from it inherits the lie. Your job is to compare what the map claims with what the code actually contains, then rewrite it.

Be methodical. Do not "spot check" — drift creeps in everywhere, and a half-checked map is worse than an obviously stale one.

## Inputs

- `.claude/specs/PROJECT_MAP.md` — the current map. Read it once, end-to-end.
- The codebase under `src/`, `fixtures/`, `templates/`, `bin/console`, `config/packages/messenger.yaml`.
- `CLAUDE.md` — the convention rules; the map's "Conventions reminder" footer must stay in sync with it.

## Process

Run discovery in parallel where possible — the bash calls below don't depend on each other.

### 1. Routes

For every `src/Controller/**/*.php`:

- `grep -rn "^#\[Route" src/Controller --include="*.php"` for single-line attrs.
- For multi-line `#[Route(` declarations, follow up with a focused read so the path + method stays attached.
- Group the resulting list by the same buckets as the current map: Public (unauth), Public ordering (`Public\*`), Portal shared, Portal user (`Portal\User\*`), Portal landlord, Portal admin (`Admin\*` + `Portal\Admin\*`), API, Ops.
- For every route, record path + controller class. Flag UriSigner-protected routes (look for `UriSigner` in the controller body) and POST-only / non-GET methods if relevant.

### 2. Entities

`find src/Entity -type f -name "*.php" | sort`. For each entity:

- Note its purpose and key relations from the class header / property docblocks.
- Mark whether it `implements EntityWithEvents` / `use HasEvents` (record-event entities).
- Mark `#[HasDeleteDomainEvent(...)]` declarations.
- Note non-trivial property hooks or override-bearing fields that future specs need to know about (e.g. `Contract.individualMonthlyAmount`, `Contract.paidThroughDate`, `Order.individualMonthlyAmount`, audit-log writers).

### 3. Commands, Queries, Events

- `find src/Command -name "*.php"` — list `*Command` (DTOs) and `*Handler` pairs. The map shows commands grouped by domain (User/auth, Place/storage, Order/payment/contract, Onboarding, Place access/handover, Self-billing). Re-group anything new.
- `find src/Query -name "*.php"` — every query has three files (`GetX.php` + `GetXQuery.php` + `GetXResult.php`). List the queries.
- `find src/Event -name "*.php"` — split into event DTOs (no `Handler` suffix, no `Subscriber` suffix) and handlers (`*Handler.php`, `*Subscriber.php`). Group handlers by purpose: email side-effects (`Send*EmailHandler`) vs bookkeeping (`IssueInvoice*`, `RecordPayment*`, `ReleaseStorage*`, `ForceReleaseStorage*`).

### 4. Enums, Forms, Repositories, Services, Value, Exception, Console, Twig, Middleware

- `find src/Enum src/Form src/Repository src/Service src/Value src/Exception src/Console src/Twig src/Middleware -name "*.php"`.
- For services, group by sub-folder (`GoPay/`, `Order/`, `Overdue/`, `Excel/`, `Storage/`, `Billing/`, `Form/`, `Security/`, `Identity/`, `Messenger/`, `Fakturoid/`).
- For console commands, extract the `name:` from each `#[AsCommand(...)]` block — that's the cron name the table shows. Add a one-line purpose pulled from the `description:` arg or the class docblock.

### 5. Fixtures

`find fixtures -name "*Fixtures.php" | sort`. Update the fixture inventory line. Test users / passwords go in CLAUDE.md, not here — keep the existing one-line pointer.

### 6. Compliance / docs pointers

Verify each linked file in the "Compliance & docs" section still exists:
- `.claude/COMPLIANCE.md`
- `.claude/CUSTOMER_DOCUMENTS.md`
- `.claude/DOMAIN.md` / `.pdf`
- `.claude/MESSENGER.md`

If any moved or split, update the pointers. Do NOT inline their content — the map points, it does not duplicate.

## Diff before writing

Before you Write the new file, identify what actually changed. You should be able to summarise the diff in 5–15 bullets like:

- Routes added: …
- Routes removed: …
- New entities: …
- New commands: …
- New events: …
- Service folders restructured: …

If the diff is empty, say so and skip the write. The map is dense; rewriting it for cosmetic-only changes is noise.

## Writing the file

Use the existing structure and tone (terse, dense). Keep the footer's "Conventions reminder" up to date with `CLAUDE.md`. Update the date in the second line.

After writing, post a short summary back to the user listing only the actual changes — not a full table-of-contents recap. End with one line on whether any not-yet-done specs in `BACKLOG.md` reference paths or symbols that the rescan changed (path drift, line-number drift, names that have been renamed); if you spot something, name the spec and the line, but do not edit the spec.

## Don't

- Don't expand the map into prose. It's a reference, not documentation. Keep it scannable.
- Don't add sections that aren't in the current map without reason — every section costs scan time for every future spec/implement run.
- Don't list every handler / every controller / every test file when a sub-bucket count or grouping captures the shape better.
- Don't run `composer quality` or any build steps. This is read-only orientation work.
- Don't update specs themselves. Drift discovery is reported back to the user; spec edits are the user's call.
