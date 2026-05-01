# 004 — Audit log of all outgoing emails

**Status:** ready
**Type:** feature (admin observability)
**Scope:** large (~15 files: entity + migration + listener + repository + 2 controllers + 2 templates + nav update + tests)
**Depends on:** none

## Problem

There is no record of which emails the system actually sent. For audit / compliance / debugging customer "I never received it" claims, we need a complete, queryable history of every email (or attempted email) leaving the platform.

## Goal

Persist every outgoing email (and every attempt) into a new `email_log` table via a Symfony Mailer event listener. Add an admin page to browse / filter / inspect them. Failures in the logger MUST NOT block delivery.

## Context (current state)

- Mailer config: `config/packages/mailer.php` — single `MAILER_DSN`, default `from` is `MAILER_FROM_NAME <MAILER_FROM_EMAIL>`.
- All email sends use `Symfony\Bridge\Twig\Mime\TemplatedEmail` (Twig template + context). Attachments go through either `Email::attachFromPath($path, $name, $mime)` (most cases — VOP, consumer notice, contracts, invoices) or `Email::attach($bytes, $name, $mime)` (one case — generated map image in `SendOrderConfirmationEmailHandler`).
- Sender pattern across handlers: `(new TemplatedEmail())->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))->to(new Address($user->email, $user->fullName))->subject(...)->htmlTemplate('email/foo.html.twig')->context([...])`. Some handlers add multiple recipients or a separate admin email (e.g. `SendPaymentDefaultEmailHandler`).
- Existing audit-related admin page: `/portal/admin/audit-log` → `App\Controller\Admin\AdminAuditLogController` + template `templates/admin/audit_log/list.html.twig`. Filter UI is the visual reference to copy from.
- Admin nav lives in `templates/portal/layout.html.twig` (desktop sidebar around lines 70–134, mobile sidebar duplicated around lines 207–340). Existing items inside `{% if is_granted('ROLE_ADMIN') %}` group: Uživatelé / Všechna místa / Všechny objednávky / Onboarding / Historie změn / Žádosti o přístup.
- Conventions reminder: PHP 8.4 entity property hooks, `private(set)` constructor params, `final readonly` for events/DTOs, repositories compose `EntityManager` (no `flush()`), single-action controllers with `__invoke()`, all UUIDs from `App\Service\Identity\ProvideIdentity`. See repo `CLAUDE.md`.

## Architecture

```
Mailer::send()
   │
   └─► Transport
         │
         ├─► dispatches SentMessageEvent  ──┐
         │                                  │
         └─► dispatches FailedMessageEvent ─┤
                                            ▼
                                  EmailLoggerListener
                                  (single class, 2 listener methods)
                                            │
                                            └─► EmailLogRepository::save()
                                                  (wrapped in try/catch — never re-throws)
```

We listen to `Symfony\Component\Mailer\Event\SentMessageEvent` and `Symfony\Component\Mailer\Event\FailedMessageEvent`. By the time these fire, `TemplatedEmail` body rendering has already happened (`BodyRendererListener` runs on the earlier `MessageEvent`), so `Email::getHtmlBody()` / `getTextBody()` are populated.

## Requirements

### 1. New entity `EmailLog`

`src/Entity/EmailLog.php`. Fields:

| Property | Type | Notes |
|---|---|---|
| `id` | `Uuid` (constructor, `private(set)`) | UUID v7 from `ProvideIdentity` |
| `attemptedAt` | `\DateTimeImmutable` | Timestamp of `Sent`/`Failed` event |
| `status` | `EmailLogStatus` enum | `SENT` / `FAILED` |
| `errorMessage` | `?string` (TEXT, nullable) | Filled only on failure |
| `fromEmail` | `string` (255) | |
| `fromName` | `?string` (255) | |
| `toAddresses` | `array` (JSONB, `Types::JSON`) | `[{email: string, name: ?string}, …]` |
| `ccAddresses` | `?array` (JSONB) | Same shape as `to` |
| `bccAddresses` | `?array` (JSONB) | Same shape |
| `replyToAddresses` | `?array` (JSONB) | Same shape |
| `subject` | `string` (TEXT) — store as TEXT not VARCHAR (subjects can be long) | |
| `htmlBody` | `?string` (TEXT) | |
| `textBody` | `?string` (TEXT) | |
| `templateName` | `?string` (255) | Twig template path, e.g. `email/order_confirmation.html.twig`; null for non-`TemplatedEmail` |
| `attachments` | `?array` (JSONB) | `[{name: string, sizeBytes: int, mimeType: string}, …]` |
| `messageId` | `?string` (255) | The RFC 5322 `Message-ID` header value if present |

Use Doctrine table name `email_log` (`#[ORM\Table(name: 'email_log')]`). Add an index on `(attempted_at DESC)` (default ordering for the list page) and on `status`.

Create `src/Enum/EmailLogStatus.php` — string-backed enum with `SENT = 'sent'`, `FAILED = 'failed'`.

The entity does NOT implement `EntityWithEvents` — this is a purely write-only audit record, no domain events needed.

### 2. Doctrine migration

Run `docker compose exec web bin/console make:migration` after adding the entity, sanity-check the generated SQL. Make sure:
- `id` is `UUID` PK (matches other entity migrations).
- All JSONB columns are `JSONB`, not `JSON` (Postgres-specific, faster for queries).
- Indexes on `attempted_at DESC` and `status`.
- TEXT columns for `error_message`, `subject`, `html_body`, `text_body`.

### 3. Repository

`src/Repository/EmailLogRepository.php`. Inject `EntityManagerInterface` (composition pattern, no `ServiceEntityRepository`). Methods needed:

- `save(EmailLog $log): void` — `persist()` only; **explicitly call `$em->flush()` here**. Reason: this listener runs outside the command bus's `doctrine_transaction` middleware (it fires during transport send, which may happen mid-request in any context including controllers, console commands, or messenger workers). The standard "no flush in repos" rule does not apply.
- `findPaginated(int $page, int $limit, EmailLogFilter $filter): array<EmailLog>`
- `countWithFilter(EmailLogFilter $filter): int`
- `getDistinctTemplateNames(): array<string>` — for the template filter dropdown
- `find(Uuid $id): ?EmailLog`
- `get(Uuid $id): EmailLog` — throws `EmailLogNotFound` if missing (new exception in `src/Exception/`, with `#[WithHttpStatus(404)]`)

Build queries with `EntityManager::createQueryBuilder()` per project convention. For JSONB recipient search use `LOWER(CAST(to_addresses AS TEXT)) LIKE LOWER(:search)` — adequate for thousands of rows. Optimize later if it becomes slow.

`EmailLogFilter` is a small `final readonly` DTO in `src/Repository/EmailLogFilter.php` with nullable fields: `dateFrom`, `dateTo`, `recipient`, `subject`, `templateName`, `status`. The controller builds it from the request, the repository consumes it. Keeps the repository signature small.

### 4. Listener

`src/Service/EmailLogger.php` (single class with two listener methods using attribute-based registration):

```php
final class EmailLogger
{
    public function __construct(
        private readonly EmailLogRepository $repository,
        private readonly ProvideIdentity $identity,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {}

    #[AsEventListener]
    public function onSent(SentMessageEvent $event): void
    {
        $this->logSafely($event->getMessage(), EmailLogStatus::SENT, errorMessage: null);
    }

    #[AsEventListener]
    public function onFailed(FailedMessageEvent $event): void
    {
        $this->logSafely(
            $event->getMessage(),
            EmailLogStatus::FAILED,
            errorMessage: $event->getError()->getMessage(),
        );
    }

    private function logSafely(RawMessage $message, EmailLogStatus $status, ?string $errorMessage): void
    {
        try {
            $log = $this->buildLog($message, $status, $errorMessage);
            if (null !== $log) {
                $this->repository->save($log);
            }
        } catch (\Throwable $e) {
            // NEVER re-throw — logging must not break sending.
            $this->logger->error('EmailLogger failed to persist email log', [
                'exception' => $e,
                'status' => $status->value,
            ]);
        }
    }

    private function buildLog(RawMessage $message, EmailLogStatus $status, ?string $errorMessage): ?EmailLog { /* … */ }
}
```

`buildLog()` rules:
- Only handles `Symfony\Component\Mime\Email` (and its subclasses including `TemplatedEmail`). For any other `RawMessage` type, return `null` — do not log. (We have no production code paths that send raw messages, but be safe.)
- Extract `from`: first `From` address.
- Extract `to`/`cc`/`bcc`/`replyTo`: convert each `Address` to `['email' => $a->getAddress(), 'name' => $a->getName() ?: null]`. Empty lists become `null`.
- Extract `subject`: `$email->getSubject() ?? ''`.
- Extract `htmlBody`/`textBody`: `$email->getHtmlBody()` / `$email->getTextBody()` (already strings after rendering).
- Extract `templateName`: if `$email instanceof TemplatedEmail` use `$email->getHtmlTemplate()` (fall back to `$email->getTextTemplate()` if html is null), else `null`.
- Extract attachments: iterate `$email->getAttachments()` (returns `DataPart[]`); for each, capture:
  - `name` = `$part->getFilename() ?? $part->getName() ?? 'attachment'`
  - `sizeBytes` = `strlen($part->getBody())` — caveat: this materializes the body into memory; acceptable since the transport already did this to send.
  - `mimeType` = `$part->getMediaType().'/'.$part->getMediaSubtype()`
  - **Do not store the attachment bytes themselves.**
- Extract `messageId`: `$email->getHeaders()->get('Message-ID')?->getBodyAsString()`. May be null pre-send; for `SentMessageEvent` it's typically set by the transport.
- `attemptedAt` = `$this->clock->now()`.

Register the listener via Symfony's autoconfig — `#[AsEventListener]` on each method is sufficient (services have `autoconfigure: true` per `config/services.php`).

### 5. List controller + template

`src/Controller/Admin/AdminEmailLogController.php`:
- Route: `#[Route('/portal/admin/email-log', name: 'admin_email_log')]`
- `#[IsGranted('ROLE_ADMIN')]`
- Reads filter values from query string: `date_from` (`Y-m-d`), `date_to` (`Y-m-d`), `recipient`, `subject`, `template`, `status`. Empty strings become null.
- Builds `EmailLogFilter`, calls `findPaginated($page, 50, $filter)` + `countWithFilter($filter)`.
- Passes `templateNames` (from `getDistinctTemplateNames()`) and `EmailLogStatus::cases()` to the template for dropdowns.

Template `templates/admin/email_log/list.html.twig` — model the structure on `templates/admin/audit_log/list.html.twig`:
- Page title "Odeslané e-maily"
- Filter card (single row, wrap on mobile): from-date input, to-date input, recipient text input, subject text input, template `<select>` (showing distinct template names), status `<select>` (Vše / Odesláno / Selhalo), Filtrovat / Vymazat buttons.
- Results card with table columns: **Čas**, **Stav** (badge — green for sent, red for failed), **Příjemce** (primary = first to-address; tooltip/title with full list incl. cc/bcc), **Předmět** (truncated to ~60 chars with `…`), **Šablona** (badge with last path segment, full path on hover), **Akce** (link "Zobrazit" → detail page).
- Pagination via existing `components/pagination.html.twig`.
- Empty-state row "Žádné e-maily nenalezeny".

### 6. Detail controller + template

`src/Controller/Admin/AdminEmailLogDetailController.php`:
- Route: `#[Route('/portal/admin/email-log/{id}', name: 'admin_email_log_detail')]`
- `#[IsGranted('ROLE_ADMIN')]`
- Loads via `$repository->get(Uuid::fromString($id))` (404 via `EmailLogNotFound` exception).
- Renders `templates/admin/email_log/detail.html.twig`.

Template `detail.html.twig`:
- Breadcrumb: `Odeslané e-maily → {{ subject|u.truncate(40, '…') }}`
- Header: status badge + subject + attemptedAt
- Two-column metadata grid (similar to user view template): From, To, CC (only if present), BCC (only if present), Reply-To (only if present), Template, Message-ID, Status, Error (only if failed — red alert box with `errorMessage`).
- **HTML body preview**: `<iframe sandbox="" srcdoc="{{ log.htmlBody|e('html_attr') }}" class="w-full h-[600px] border rounded"></iframe>` (the empty `sandbox=""` disables scripts, plugins, popups, top-nav, forms — read-only render of the visual output the recipient saw). Skip the iframe entirely if `htmlBody` is null.
- **Tabs/toggles** (Alpine.js — already used in the layout) to switch between:
  - "Náhled" — the iframe above (default)
  - "HTML zdroj" — `<pre><code>{{ log.htmlBody }}</code></pre>` with a "Kopírovat" button
  - "Textová verze" — `<pre>{{ log.textBody }}</pre>` (only if not null)
- **Attachments**: bullet list `{name} ({sizeBytes|format_bytes}, {mimeType})`. No download links (we don't store bytes). Hide the section if attachments is empty/null.
- Back-link to `admin_email_log` (preserve any filters from referer if simple; otherwise just go to filter-less list).

If you don't already have a `format_bytes` Twig filter, write a small helper inline: `{{ (size / 1024)|number_format(1) }} kB` for >1024 else `{{ size }} B`. Don't add a new Twig extension just for this.

### 7. Admin nav update

In `templates/portal/layout.html.twig`, add a new sidebar item inside the `is_granted('ROLE_ADMIN')` block, **immediately after** `Historie změn` and **before** `Žádosti o přístup`:

```twig
<a href="{{ path('admin_email_log') }}" class="{% if app.request.attributes.get('_route') starts with 'admin_email_log' %}bg-gray-900 text-white{% else %}text-gray-300 hover:bg-gray-700 hover:text-white{% endif %} group flex items-center px-3 py-2 text-sm font-medium rounded-md">
    <svg class="mr-3 h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
    </svg>
    Odeslané e-maily
</a>
```

Add the same item in **both** the desktop sidebar (around line 122 area) AND the mobile sidebar (the duplicated nav block further down).

### 8. Tests

`tests/Unit/Service/EmailLoggerTest.php`:
- Build a `TemplatedEmail` with from/to/cc/subject/html template/text body/attachment, dispatch a fake `SentMessageEvent`, assert the saved `EmailLog` has the expected fields (use mocked repository + `MockClock` fixed at `2025-06-15 12:00:00 UTC`).
- Same for `FailedMessageEvent` — assert `status = FAILED`, `errorMessage` populated.
- `RawMessage` (non-`Email`) input → no save call.
- Repository throws `\RuntimeException` → listener does NOT re-throw, monolog logger captures it (verify with a spy logger).

`tests/Integration/Controller/Admin/AdminEmailLogControllerTest.php`:
- Admin can load `/portal/admin/email-log`, sees seeded log entries (add a small `EmailLogFixtures` for a few rows — sent + failed mix).
- Filter by status / template / recipient works.
- Non-admin (regular user) → 403.
- Detail page renders, iframe present, attachments listed.

### 9. Fixtures (small)

`fixtures/EmailLogFixtures.php` — load 5–10 representative rows: a couple of `SENT` with various templates (`email/order_confirmation.html.twig`, `email/welcome.html.twig`), one with attachments, one `FAILED` with an error message. Useful for local exploration of the new admin page after `composer db:reset`.

## Acceptance

- `docker compose exec web composer quality` is green.
- After running migrations, the `email_log` table exists.
- Triggering any email-sending flow (e.g. registering a new user — `welcome.html.twig` is sent) creates a row in `email_log` with status=`sent`, the rendered HTML body, the template path, and the recipient.
- Force a transport error (e.g. invalid `MAILER_DSN` temporarily) → row appears with status=`failed` and `error_message` populated; the original code path that called `mailer->send()` either receives the underlying transport exception or behaves exactly as before — the listener does NOT add a second exception or block anything.
- Simulate a DB-write failure in `EmailLogger` (e.g. by mocking the repo to throw) → email still sends, no exception bubbles up, monolog has an error entry.
- Admin can browse `/portal/admin/email-log`, filter by status / recipient / subject / template / date range. Pagination works.
- Detail page shows email metadata, the rendered HTML in a sandboxed iframe (no scripts execute), source / text toggles work, attachments listed with size + mime.
- Non-admin gets 403 on both routes.
- Sidebar shows "Odeslané e-maily" for admins on both desktop and mobile.

## Out of scope

- **Linking attachments to source files on disk.** Symfony's `DataPart` API doesn't expose the source filesystem path publicly; doing so would require either reflection hacks or refactoring every `attachFromPath` call site. If you later want clickable file links from the email log, the cleaner path is to add references to the related entity (e.g. an `invoice_id` FK) — out of scope for now.
- **Storing attachment bytes.** Filename / size / mime only.
- **Auto-purge / retention policy.** Keep all rows forever for now; add a cleanup console command later if the table grows.
- **Free-text search across the body.** Subject and recipient search is enough for v1.
- **Re-sending a logged email** from the admin UI.
- **Listening to non-`Email` `RawMessage` types** (e.g. SMS via Notifier). We send only emails today.
- **Tracking email opens / clicks / bounces** (would require a real ESP webhook integration — separate feature).
- **Sentry breadcrumb / metric on send failures** (orthogonal — not part of this audit log).

## Open questions

None — proceed.
