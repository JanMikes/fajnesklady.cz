# 031 — Daily overdue admin e-mail digest

**Status:** done
**Type:** feature (admin push notification)
**Scope:** small (~10 files: 1 console command + 1 event + 1 handler + 1 entity + 1 repository + 1 migration + 2 email templates + tests + BACKLOG/PROJECT_MAP touch-ups)
**Depends on:** spec 023 (`OverdueChecker` + `OverdueSummary` + `/portal/admin/po-splatnosti` page).

## Problem

Spec 023 surfaces overdue contracts inside the admin UI: a nav badge with live count, a dashboard tile with top-5 preview, and a dedicated `/portal/admin/po-splatnosti` page. Those surfaces require **logging in to notice**. An admin who's away for a week — or simply not browsing the portal that day — won't see overdue cases pile up. Spec 023 explicitly deferred a daily push notification ("we can add later"). This spec ships it.

The cron pattern is well established: `app:send-external-prepayment-ending-soon` (spec 025) is the closest cousin — daily, idempotent, dispatches one event per match, hands off to a handler that renders + sends the e-mail and records dedup state on a domain entity.

## Goal

A daily console command `app:send-overdue-digest-email` that:

1. Asks `OverdueChecker::summarise($now)` for the current overdue picture.
2. If `summary.count == 0` → exit cleanly without sending anything (no "all clear" e-mails — pure noise).
3. If `summary.count > 0` → send **one e-mail per `ROLE_ADMIN` user**, listing the top 10 overdue contracts grouped by severity (CRITICAL → ERROR → WARNING), with totals and a CTA linking to `/portal/admin/po-splatnosti`.
4. **Idempotent for the same calendar day**: re-running the cron the same day must not produce duplicate e-mails. A fresh row is recorded the first time it sends; subsequent invocations on the same date short-circuit.

The cron runs daily at 08:00 Europe/Prague (deployment cron config — same host that already runs `app:send-external-prepayment-ending-soon`).

## Context (current state)

### Existing pieces we reuse

- **Overdue domain**: `src/Service/Overdue/OverdueChecker.php` — `summarise(\DateTimeImmutable $now): OverdueSummary` returns `count`, `totalAmount`, `top` (5 highest-severity). `findOverdueViews($now)` returns the **full** sorted list (CRITICAL → ERROR → WARNING, then daysOverdue DESC). For the digest we need the **top 10** views — slice the result of `findOverdueViews()`.
- **Value objects**: `src/Value/OverdueSummary.php`, `src/Value/OverdueContractView.php`, `src/Value/OverdueSeverity.php` (`CRITICAL` / `ERROR` / `WARNING`, plus `badgeClass()` / `rowClass()` / `sortRank()`).
- **Admin lookup**: `UserRepository::findByRole(UserRole::ADMIN): User[]` (`src/Repository/UserRepository.php:118`) — already used by `SendExternalPrepaymentEndingSoonEmailHandler::notifyAdmins()` (`src/Event/SendExternalPrepaymentEndingSoonEmailHandler.php:99`).
- **Cron template**: `src/Console/SendExternalPrepaymentEndingSoonCommand.php` — the structural blueprint:
  - `#[AsCommand(name: '…', description: '…')]`, `final class … extends Command`.
  - Constructor injection of `ContractRepository`, `MessageBusInterface` aliased to `event.bus`, `ClockInterface`.
  - `protected function execute(InputInterface, OutputInterface): int` with `SymfonyStyle`, dispatches one event per match, returns `Command::SUCCESS`.
- **Event handler template**: `src/Event/SendExternalPrepaymentEndingSoonEmailHandler.php`:
  - `#[AsMessageHandler] final readonly class …`.
  - Resolves data, builds `TemplatedEmail`, sends per admin, logs `'exception' => $e` (per CLAUDE.md memory), records dedup state on the contract.
- **OrderStatusUrlGenerator**: not needed — the digest links to the admin page, not to per-order status.
- **Email base style**: existing templates (e.g. `templates/email/external_prepayment_ending_soon.html.twig`, `recurring_payment_failed_admin.html.twig`) use a 600px-wide table layout with a coloured header bar, content panel, footer. Follow that style — red header (`#dc2626`) for the digest to match the in-portal red theme.

### What's missing

- A console command — there is none for "overdue digest".
- A domain event + handler dedicated to this digest. (`OverdueChecker::summarise()` happens in the command; the event then targets a single admin recipient and carries the snapshot.)
- An idempotency record. The codebase has nothing at "system-level cron ran today" granularity. Existing dedup uses **per-entity** state (e.g. `Contract.lastAdvanceNoticeSentAt`), which doesn't fit a digest that aggregates many contracts.
- The two e-mail templates (HTML + reusable plain partial sections — single HTML template is enough, mirroring the prepayment notice).

### Conventions worth mirroring

- Entity in `src/Entity/`, identifier via `ProvideIdentity` (`src/Service/Identity/ProvideIdentity.php`) — UUIDv7 passed in constructor.
- `private(set)` for constructor properties; `public private(set)` only when something updates the value later. For an immutable audit row, `private(set)` everywhere.
- No `flush()` in repositories — `doctrine_transaction` middleware wraps the command-bus call, but **console commands are not on the command bus**. The handler invocation here happens via the `event.bus` (which has the same middleware? — verify; if not, the repository method that records the digest must call `flush()` explicitly. See req. 4 for the resolution).
- No `getRepository()` / `findBy()` — use QueryBuilder via `EntityManager` composition.
- Tests: integration tests use DAMA + MockClock pinned at `2025-06-15 12:00:00 UTC`.
- Czech UI text with full diacritics (memory rule).

### Verifying `event.bus` middleware

`config/packages/messenger.yaml` defines three buses. The `event.bus` configuration must be checked: if it carries the `doctrine_transaction` middleware, the handler can mutate entities and let middleware flush. If it does **not**, the handler (or a small repository helper) must call `EntityManager::flush()` explicitly after persisting the dedup row. **Verification step in implementation**: read `config/packages/messenger.yaml`; if `event.bus` lacks `doctrine_transaction`, the dedup-row persistence is the **only** place in this spec that violates the "no flush" rule, and that violation is contained to one method, justified by the bus config.

For the cron command itself: the cron runs outside any bus, so any persistence done **inside the command** (none in this spec — see req. 4) would also need an explicit flush.

## Architecture

```
                ┌─────────────────────────────────────────────────┐
                │  Cron: app:send-overdue-digest-email            │
                │  (daily 08:00 Europe/Prague)                    │
                │                                                 │
                │  1. summary = OverdueChecker::summarise(now)    │
                │  2. if summary.count == 0 → exit                │
                │  3. for each admin (UserRepo::findByRole):      │
                │       if OverdueDigestSentRepo::wasSentToday(   │
                │              admin, today)  → skip              │
                │       else dispatch OverdueDigestRequested(     │
                │              adminId, occurredOn, today)        │
                └────────────────────┬────────────────────────────┘
                                     │ event.bus
                                     ▼
                ┌─────────────────────────────────────────────────┐
                │  SendOverdueDigestEmailHandler                  │
                │  - load admin user                              │
                │  - top10 = OverdueChecker::findOverdueViews()  │
                │             |> slice 0..10                      │
                │  - render TemplatedEmail (HTML, red theme)      │
                │  - on success: persist OverdueDigestSent row    │
                │     (date=today, adminId, sentAt=now,           │
                │      overdueCount=summary.count, …)             │
                └─────────────────────────────────────────────────┘

                ┌─────────────────────────────────────────────────┐
                │  Entity: OverdueDigestSent                      │
                │  unique(date, adminId)                          │
                │  - id (Uuid)                                    │
                │  - date (DATE_IMMUTABLE)                        │
                │  - adminId (Uuid → User)                        │
                │  - sentAt (DATETIME_IMMUTABLE)                  │
                │  - overdueCount, totalAmount (snapshot)         │
                └─────────────────────────────────────────────────┘
```

The dedup record lives next to the user, not next to the contract — matches the "per-admin per-day" granularity. A unique constraint on `(date, adminId)` makes double-insert at the DB level a hard error; the handler's pre-check is a soft fast-path.

## Requirements

### 1. Entity: `App\Entity\OverdueDigestSent`

`src/Entity/OverdueDigestSent.php` — small audit row.

```php
namespace App\Entity;

use App\Repository\OverdueDigestSentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OverdueDigestSentRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_overdue_digest_admin_date', columns: ['admin_id', 'date'])]
class OverdueDigestSent
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,

        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) User $admin,

        /** Calendar day (Europe/Prague) for which the digest was sent. */
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private(set) \DateTimeImmutable $date,

        #[ORM\Column]
        private(set) \DateTimeImmutable $sentAt,

        /** Snapshot of OverdueSummary.count at send time. */
        #[ORM\Column]
        private(set) int $overdueCount,

        /** Snapshot of OverdueSummary.totalAmount at send time (halere). */
        #[ORM\Column]
        private(set) int $totalAmount,
    ) {}
}
```

**Note on `repositoryClass`.** Per CLAUDE.md, repositories never extend `ServiceEntityRepository`. The `repositoryClass` attribute here is **only** a Doctrine hint — `OverdueDigestSentRepository` itself is autowired by `App\:` glob, takes `EntityManagerInterface` in its constructor, and holds no service-locator behaviour. This matches every other repo in the codebase (`ContractRepository`, `OrderRepository`, …) — the attribute exists, the class is composition-style.

**Migration**: `docker compose exec web bin/console make:migration` after adding the entity. Do **not** handwrite SQL (CLAUDE.md memory rule).

### 2. Repository: `App\Repository\OverdueDigestSentRepository`

`src/Repository/OverdueDigestSentRepository.php`:

```php
namespace App\Repository;

use App\Entity\OverdueDigestSent;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class OverdueDigestSentRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function wasSentForAdminOn(User $admin, \DateTimeImmutable $date): bool
    {
        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(OverdueDigestSent::class, 'd')
            ->where('d.admin = :admin')
            ->andWhere('d.date = :date')
            ->setParameter('admin', $admin)
            ->setParameter('date', $date->setTime(0, 0, 0))
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Persists the dedup row. The "no flush in repositories" rule has one
     * documented exception here: this repository is invoked from an event
     * handler outside the command-bus's doctrine_transaction middleware
     * scope (verify config/packages/messenger.yaml — if event.bus has the
     * middleware, drop the flush() call below).
     */
    public function save(OverdueDigestSent $row): void
    {
        $this->entityManager->persist($row);
        $this->entityManager->flush();
    }
}
```

**Verification step (implementer)**: open `config/packages/messenger.yaml`. If the `event.bus` block has `doctrine_transaction` in its middleware list, **delete** the `$this->entityManager->flush()` call (it's redundant) and update the docblock. Otherwise leave it. Either path is acceptable; both are documented.

### 3. Event: `App\Event\OverdueDigestRequested`

`src/Event/OverdueDigestRequested.php` — `final readonly` DTO matching the existing event style (cf. `ExternalPrepaymentEndingSoon`):

```php
namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class OverdueDigestRequested
{
    public function __construct(
        public Uuid $adminId,
        public \DateTimeImmutable $occurredOn,
        /** Calendar day (Europe/Prague) the digest covers. */
        public \DateTimeImmutable $date,
    ) {}
}
```

`$date` is the dedup key. `$occurredOn` is the timestamp of dispatch. Keeping them separate avoids any ambiguity if the cron ever runs near midnight.

### 4. Console command: `App\Console\SendOverdueDigestEmailCommand`

`src/Console/SendOverdueDigestEmailCommand.php`:

```php
namespace App\Console;

use App\Enum\UserRole;
use App\Event\OverdueDigestRequested;
use App\Repository\OverdueDigestSentRepository;
use App\Repository\UserRepository;
use App\Service\Overdue\OverdueChecker;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:send-overdue-digest-email',
    description: 'Send a daily digest e-mail to every admin when ≥1 contract is overdue.',
)]
final class SendOverdueDigestEmailCommand extends Command
{
    public function __construct(
        private readonly OverdueChecker $overdueChecker,
        private readonly UserRepository $userRepository,
        private readonly OverdueDigestSentRepository $digestSentRepository,
        #[Autowire(service: 'event.bus')]
        private readonly MessageBusInterface $eventBus,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->clock->now();
        $today = $now->setTime(0, 0, 0);

        $summary = $this->overdueChecker->summarise($now);

        if (0 === $summary->count) {
            $io->info('No overdue contracts — digest skipped.');

            return Command::SUCCESS;
        }

        $admins = $this->userRepository->findByRole(UserRole::ADMIN);

        if (0 === count($admins)) {
            $io->warning('No admins to notify.');

            return Command::SUCCESS;
        }

        $dispatched = 0;
        $skipped = 0;

        foreach ($admins as $admin) {
            if ($this->digestSentRepository->wasSentForAdminOn($admin, $today)) {
                $skipped++;
                continue;
            }

            $this->eventBus->dispatch(new OverdueDigestRequested(
                adminId: $admin->id,
                occurredOn: $now,
                date: $today,
            ));
            $dispatched++;
        }

        $io->success(sprintf(
            'Overdue digest: %d overdue contracts; dispatched to %d admin(s), %d skipped (already sent today).',
            $summary->count,
            $dispatched,
            $skipped,
        ));

        return Command::SUCCESS;
    }
}
```

**Cron schedule**: 08:00 Europe/Prague daily. The deployment runs cron / supervisor outside the codebase; add the new entry alongside the existing `app:send-external-prepayment-ending-soon` line. (Implementer: locate that file/config — likely `infra/` or `Dockerfile` cron block — and append the new schedule.)

### 5. Event handler: `App\Event\SendOverdueDigestEmailHandler`

`src/Event/SendOverdueDigestEmailHandler.php` — mirrors `SendExternalPrepaymentEndingSoonEmailHandler` style.

```php
namespace App\Event;

use App\Entity\OverdueDigestSent;
use App\Repository\OverdueDigestSentRepository;
use App\Repository\UserRepository;
use App\Service\Identity\ProvideIdentity;
use App\Service\Overdue\OverdueChecker;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final readonly class SendOverdueDigestEmailHandler
{
    private const int TOP_N = 10;

    public function __construct(
        private OverdueChecker $overdueChecker,
        private UserRepository $userRepository,
        private OverdueDigestSentRepository $digestSentRepository,
        private MailerInterface $mailer,
        private ProvideIdentity $identityProvider,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(OverdueDigestRequested $event): void
    {
        // Hard idempotency check (race-safe: unique constraint catches anything we miss).
        $admin = $this->userRepository->get($event->adminId); // existing helper; if not, use EM::find
        if ($this->digestSentRepository->wasSentForAdminOn($admin, $event->date)) {
            return;
        }

        $now = $this->clock->now();
        $allViews = $this->overdueChecker->findOverdueViews($now);

        if (0 === count($allViews)) {
            // Defensive: if the world changed between dispatch and handle.
            return;
        }

        $top = array_slice($allViews, 0, self::TOP_N);
        $totalAmount = array_sum(array_map(static fn ($v) => $v->overdueAmount, $allViews));

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($admin->email, $admin->fullName))
            ->subject(sprintf('Po splatnosti — denní přehled (%d smluv)', count($allViews)))
            ->htmlTemplate('email/overdue_digest.html.twig')
            ->context([
                'adminName' => $admin->fullName,
                'totalCount' => count($allViews),
                'totalAmount' => $totalAmount,
                'top' => $top,
                'overdueUrl' => $this->urlGenerator->generate(
                    'admin_overdue',
                    [],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
                'date' => $event->date,
            ]);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send overdue digest e-mail', [
                'admin_id' => $event->adminId->toRfc4122(),
                'date' => $event->date->format('Y-m-d'),
                'exception' => $e,
            ]);

            return;
        }

        $this->digestSentRepository->save(new OverdueDigestSent(
            id: $this->identityProvider->next(),
            admin: $admin,
            date: $event->date,
            sentAt: $now,
            overdueCount: count($allViews),
            totalAmount: $totalAmount,
        ));
    }
}
```

**`UserRepository::get()`** — verify the helper exists; spec 023 added overdue methods there. If it doesn't, use `$this->entityManager->find(User::class, $event->adminId)` via a small `find()` helper (or inject `EntityManagerInterface` into the handler as a stop-gap — but **prefer** adding `UserRepository::get(Uuid): User` if missing, mirroring `ContractRepository::get()`).

**Why slice in the handler instead of using `OverdueSummary::$top`** — `OverdueSummary::$top` is hard-capped at 5 (used for the dashboard preview). The digest wants 10 (req. from this spec). Two paths: (a) re-call `findOverdueViews()` and slice 10; (b) extend `OverdueSummary` with a configurable top-N. (a) is simpler and the cost is one extra query per admin per day — negligible. Going with (a).

**Why re-query in the handler instead of carrying the views in the event** — events should be small DTOs (CLAUDE.md). Passing 10 fully-hydrated `OverdueContractView` objects (each holding a `Contract` + `Order` + `Storage` + `User` graph) through the bus is wrong. Re-querying with the same `OverdueChecker` produces the latest snapshot, which is **better** behaviour anyway (if a debtor paid up between dispatch and handle, they're correctly excluded).

### 6. Email template: `templates/email/overdue_digest.html.twig`

Mirror the existing styled-table layout (cf. `templates/email/external_prepayment_ending_soon.html.twig`). Red header, severity-coloured rows in the table, total summary on top, CTA button to `/portal/admin/po-splatnosti`.

```twig
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Po splatnosti — denní přehled</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 700px; margin: 0 auto; padding: 20px; }
        .header { background-color: #dc2626; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .header h1 { margin: 0; font-size: 22px; }
        .content { background-color: #f9f9f9; padding: 24px; border: 1px solid #ddd; border-top: none; }
        .summary { background-color: #fef2f2; border: 1px solid #fecaca; padding: 16px 20px; margin-bottom: 20px; border-radius: 5px; }
        .summary strong { color: #b91c1c; font-size: 22px; }
        table.rows { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 20px; }
        table.rows th { text-align: left; padding: 8px; background-color: #f3f4f6; border-bottom: 2px solid #d1d5db; font-size: 12px; text-transform: uppercase; }
        table.rows td { padding: 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        tr.sev-critical td { background-color: #fee2e2; }
        tr.sev-error    td { background-color: #fef2f2; }
        tr.sev-warning  td { background-color: #fffbeb; }
        .badge { display: inline-block; padding: 2px 8px; font-size: 11px; border-radius: 9999px; font-weight: 600; }
        .badge-critical { background-color: #b91c1c; color: white; }
        .badge-error    { background-color: #ef4444; color: white; }
        .badge-warning  { background-color: #f59e0b; color: white; }
        .cta { text-align: center; margin: 24px 0; }
        .cta a { display: inline-block; background-color: #dc2626; color: white; padding: 12px 28px; text-decoration: none; border-radius: 5px; font-size: 14px; font-weight: 600; }
        .footer { text-align: center; padding: 16px; font-size: 12px; color: #666; }
        a { color: #dc2626; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Po splatnosti — denní přehled</h1>
        <p style="margin: 6px 0 0; font-size: 13px;">{{ date|date('d.m.Y') }}</p>
    </div>

    <div class="content">
        <p>Dobrý den {{ adminName }},</p>

        <div class="summary">
            <p style="margin: 0 0 6px;">Smluv po splatnosti: <strong>{{ totalCount }}</strong></p>
            <p style="margin: 0;">Celková částka: <strong>{{ (totalAmount / 100)|number_format(0, ',', ' ') }} Kč</strong></p>
        </div>

        <p>Nejvíc po splatnosti (top {{ top|length }}):</p>

        <table class="rows">
            <thead>
                <tr>
                    <th>Závažnost</th>
                    <th>Zákazník</th>
                    <th>Sklad</th>
                    <th>Důvod</th>
                    <th>Po splatnosti</th>
                    <th style="text-align: right;">Částka</th>
                </tr>
            </thead>
            <tbody>
                {% for view in top %}
                    <tr class="sev-{{ view.severity.value }}">
                        <td>
                            <span class="badge badge-{{ view.severity.value }}">
                                {{- view.severity.value == 'critical' ? 'Kritické' : (view.severity.value == 'error' ? 'Chyba' : 'Upozornění') -}}
                            </span>
                        </td>
                        <td>
                            <strong>{{ view.contract.user.fullName }}</strong><br>
                            <span style="font-size: 12px; color: #666;">{{ view.contract.user.email }}</span>
                        </td>
                        <td>
                            {{ view.contract.storage.storageType.name }} #{{ view.contract.storage.number }}<br>
                            <span style="font-size: 12px; color: #666;">{{ view.contract.storage.place.name }}</span>
                        </td>
                        <td>{{ view.reasonLabel }}</td>
                        <td>
                            <strong>{{ view.daysOverdue }}</strong> {{ view.daysOverdue == 1 ? 'den' : (view.daysOverdue < 5 ? 'dny' : 'dní') }}<br>
                            <span style="font-size: 12px; color: #666;">od {{ view.anchorDate|date('d.m.Y') }}</span>
                        </td>
                        <td style="text-align: right;">
                            <strong>{{ (view.overdueAmount / 100)|number_format(0, ',', ' ') }} Kč</strong>
                        </td>
                    </tr>
                {% endfor %}
            </tbody>
        </table>

        {% if totalCount > top|length %}
            <p style="font-size: 13px; color: #666;">
                ... a dalších {{ totalCount - top|length }} smluv. Kompletní přehled najdete na portálu.
            </p>
        {% endif %}

        <div class="cta">
            <a href="{{ overdueUrl }}">Zobrazit všechny po splatnosti</a>
        </div>

        <p style="font-size: 13px;">Tento e-mail dostáváte denně v 08:00, dokud existují smlouvy po splatnosti. Když všechny zákazníky odbavíte, e-mail přestane chodit.</p>
    </div>

    <div class="footer">
        <p>Mekmann s.r.o., IČO 11678631, Dvořákova 780, 739 11 Frýdlant nad Ostravicí</p>
        <p>Toto je automaticky generovaný admin e-mail.</p>
        <p>&copy; {{ 'now'|date('Y') }} Fajnesklady.cz. Všechna práva vyhrazena.</p>
    </div>
</body>
</html>
```

Severity values come from `OverdueSeverity` enum (`critical` / `error` / `warning`). Czech labels are mapped inline in the template — three values, no need for an extension.

### 7. Migration

`docker compose exec web bin/console make:migration` after the entity exists. Doctrine will diff the new `overdue_digest_sent` table with `(id, admin_id, date, sent_at, overdue_count, total_amount)` columns and the unique index. **Do not handwrite** the SQL.

### 8. Tests

#### Integration — `tests/Integration/Console/SendOverdueDigestEmailCommandTest.php` (new)

- Set up: fixtures already include overdue contracts (via spec 023's fixture top-up; verify by reading `src/DataFixtures/ContractFixtures.php`). At MockClock `2025-06-15 12:00:00 UTC`, expect the `OverdueChecker::summarise()->count > 0`.
- **Case A — sends to every admin**: run the command; assert one e-mail per admin in the test mailer; subject contains "Po splatnosti — denní přehled"; HTML body contains the debtor's `fullName` and "Zobrazit všechny po splatnosti"; one `OverdueDigestSent` row exists per admin for `2025-06-15`.
- **Case B — no overdue contracts**: clear all overdue contracts (or use a clock pinned to a date where fixtures are clean); run the command; assert zero e-mails sent and zero `OverdueDigestSent` rows.
- **Case C — idempotency**: run the command twice in the same test (same MockClock value); assert e-mail count after second run = e-mail count after first run; assert `OverdueDigestSent` row count unchanged.
- **Case D — handler dedup race**: pre-insert an `OverdueDigestSent` row for `admin@example.com` + `2025-06-15`; run the command; assert that admin receives **no** e-mail, but other admins (if any) do.

#### Integration — `tests/Integration/Repository/OverdueDigestSentRepositoryTest.php` (new)

- `wasSentForAdminOn` — returns `false` for fresh date / fresh admin; returns `true` after `save()`.
- `save` — persists the row; the unique constraint on `(admin_id, date)` rejects a second `save` with the same pair (assert `UniqueConstraintViolationException`).

#### Unit — none

The handler does too much I/O (mailer, repos, URL generator, identity provider) for a clean unit test; integration coverage is the right level.

### 9. Fixtures

No fixtures added. Spec 023's overdue contract fixtures already produce a non-empty `OverdueChecker::summarise()` at MockClock time, which is enough to drive the integration tests and a manual `bin/console app:send-overdue-digest-email` walk-through against `db:reset`.

If the implementer finds the existing fixtures yield zero overdue contracts at the MockClock time (regression from later specs), top them up — but only as a corrective fix, not a feature of this spec.

### 10. PROJECT_MAP.md update

Append:

- Entities — new row: `OverdueDigestSent — Audit row for daily admin overdue digest (unique per admin per day) | admin:User`.
- Domain Events — add `OverdueDigestRequested` to the list.
- Console commands (the section currently exists implicitly via cron names elsewhere — add a new bullet under Services if a "Console" subsection is missing): `app:send-overdue-digest-email — daily 08:00 Europe/Prague; one e-mail per admin when ≥1 overdue contract; idempotent via OverdueDigestSent`.

### 11. BACKLOG.md row

Append to the `## Items` table:

```
| 031 | Daily overdue admin e-mail digest — new cron `app:send-overdue-digest-email` (08:00 Europe/Prague), one e-mail per `ROLE_ADMIN` when overdue count > 0, top 10 contracts by severity, CTA to `/portal/admin/po-splatnosti`. New `OverdueDigestSent` entity + repo for per-day per-admin idempotency (unique constraint). New `OverdueDigestRequested` event + `SendOverdueDigestEmailHandler`. New email template `overdue_digest.html.twig`. | draft | [031-overdue-admin-email-digest.md](031-overdue-admin-email-digest.md) |
```

Move to `ready` once the Open questions are resolved (see below).

## Acceptance

- [ ] `docker compose exec web composer quality` is green.
- [ ] `docker compose exec web bin/console doctrine:schema:validate` reports no diff after the new migration.
- [ ] `docker compose exec web bin/console app:send-overdue-digest-email` against `db:reset`:
  - Sends one e-mail per admin user (`admin@example.com` plus any other `ROLE_ADMIN` fixtures).
  - E-mail subject reads `Po splatnosti — denní přehled (N smluv)`.
  - E-mail body contains the total Kč summary, a row for each top-10 overdue contract, severity-coloured backgrounds, and a working `Zobrazit všechny po splatnosti` button linking to `/portal/admin/po-splatnosti`.
- [ ] Re-running the command the same MockClock day creates **zero** new e-mails.
- [ ] When all overdue contracts are cleared (e.g. delete the failing fixtures and re-run), the command exits with `No overdue contracts — digest skipped.` and sends nothing.
- [ ] Mailer error path: when the mailer throws, the dedup row is **not** persisted (so the next cron run retries). Asserted via the integration test.
- [ ] PROJECT_MAP.md and BACKLOG.md updated.

## Out of scope

- **Per-landlord digests.** Landlords don't chase debts; revenue settles via self-billing on cleared payments only. (Same boundary as spec 023.)
- **Customer-facing reminder e-mails.** Customers already receive `payment_default_*.html.twig` per failed charge via `SendPaymentDefaultEmailHandler` — that lifecycle is independent.
- **SMS / push notifications.** Out — single channel for v1.
- **Configurable top-N or recipient list via admin UI.** Hard-coded `TOP_N = 10` and `findByRole(ADMIN)`. Anything more requires a settings entity / page that doesn't exist yet.
- **Configurable cron schedule via admin UI.** Schedule is in deployment cron config; changing it is a deploy.
- **Digest of *changes since yesterday* (new debtors, cleared debtors, growing debt).** Useful but a different feature; this spec ships a snapshot. A diff-based digest would need a yesterday snapshot table and a comparator.
- **Suppressing repeats when count is unchanged for N days.** Acceptable noise for v1; admins can mute the inbox if they're on top of it. The "stops when count == 0" rule already prevents the worst-case spam (every-day red e-mails when nothing is overdue).
- **Plain-text e-mail variant (`.txt.twig`).** All existing templates ship HTML-only; mirror that.
- **`OverdueChecker::summariseTopN(int $n)` method.** Slicing in the handler (req. 5) is one line; an additional API surface is not justified.

## Open questions

None — proceed.

Resolved 2026-05-07:

1. **Cron schedule**: 08:00 Europe/Prague daily. Same cron host as `app:send-external-prepayment-ending-soon`.
2. **Top-N**: 10 (severity DESC, daysOverdue DESC).
3. **Idempotency mechanism**: `OverdueDigestSent` entity with unique `(admin_id, date)` constraint per the spec's design.
