# CLAUDE.md

This file provides guidance to Claude Code when working with this repository.

## Commands for Development

All commands must be run inside Docker:

```bash
# Run all quality checks (ALWAYS run before committing)
docker compose exec web composer quality

# Individual commands
docker compose exec web composer test:unit         # Unit tests
docker compose exec web composer test              # All tests
docker compose exec web composer cs:check          # Check code style
docker compose exec web composer cs:fix            # Fix code style
docker compose exec web composer phpstan           # Static analysis (level 8)
docker compose exec web composer db:reset          # Reset database with fixtures
docker compose exec web bin/console make:migration # Create migration
```

## Architecture Overview

### CQRS with Symfony Messenger

Three message buses:

**Command Bus** (`command.bus`): Write operations with `doctrine_transaction` middleware - auto-flushes on success, rolls back on exception.

**Query Bus** (`query.bus`): Read operations via `App\Query\QueryBus` class (NOT `MessageBusInterface`). Provides type-safe results via PHPStan generics.

**Event Bus** (`event.bus`): Domain events with zero-or-more handlers. Used for side effects (emails, logging).

**Project-wide messenger gotchas (READ BEFORE WORKING ON BUS DISPATCHES):** [.claude/MESSENGER.md](.claude/MESSENGER.md). Most importantly: handler exceptions are wrapped in `HandlerFailedException`, so typed `catch` blocks at dispatch sites never match the original — always unwrap via `App\Service\Messenger\HandlerFailureUnwrap::unwrap()`. Also covers the GoPay webhook architecture (per-payment `notification_url`, no global registration) and the failure-recording-outside-transaction pattern for cron loops.

### Directory Structure

```
src/
├── Command/        # Commands + Handlers (write operations)
├── Controller/     # Single-action controllers
│   └── Portal/     # Authenticated user portal
├── Entity/         # Domain entities with PHP 8.4 property hooks
├── Enum/           # Value objects (UserRole)
├── Event/          # Domain events + handlers
├── Exception/      # Domain exceptions with #[WithHttpStatus]
├── Form/           # FormData + FormType pairs
├── Query/          # QueryMessage + Handler + Result (read operations)
├── Repository/     # EntityManager composition (NO ServiceEntityRepository)
└── Service/        # Identity providers, Voters, Subscribers
```

## Key Patterns

### Entities (PHP 8.4 Property Hooks)

```php
#[ORM\Entity]
class Place
{
    #[ORM\Column]
    public private(set) \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,          // ID passed from outside
        #[ORM\Column(length: 255)]
        private(set) string $name,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $this->createdAt;
    }

    // Behavior methods, NOT setters
    public function updateDetails(string $name, \DateTimeImmutable $now): void
    {
        $this->name = $name;
        $this->updatedAt = $now;
    }
}
```

**Rules:**
- Use `private(set)` for constructor properties, `public private(set)` for updatable fields
- ID generated externally via `ProvideIdentity`, passed to constructor
- Let Doctrine infer types from PHP; use `Types::` constants only when needed (TEXT, DECIMAL)
- Explicit table name only for SQL reserved words (`#[ORM\Table(name: 'users')]`)
- No getters - use property hooks or direct property access

### Repositories (Composition)

```php
final class UserRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function save(User $user): void
    {
        $this->entityManager->persist($user);
        // NO flush() - doctrine_transaction middleware handles it
    }

    public function findByEmail(string $email): ?User
    {
        return $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
```

**Rules:**
- NEVER extend `ServiceEntityRepository`
- NEVER call `flush()` - middleware handles it. See "Manual flush()" below for the narrow exceptions; if you need one, you must justify it inline.
- NEVER use `getRepository()` or `findBy()`/`findOneBy()` - use QueryBuilder
- Use `EntityManager::find()` for ID lookups
- No repository interfaces - inject concrete classes

### Commands

```php
// Command (readonly DTO)
final readonly class CreatePlaceCommand
{
    public function __construct(
        public string $name,
        public string $address,
        public Uuid $ownerId,
    ) {}
}

// Handler
#[AsMessageHandler]
final readonly class CreatePlaceHandler
{
    public function __invoke(CreatePlaceCommand $command): Place
    {
        // Create and persist entity
        // Returns entity or void
    }
}
```

### Queries (Type-Safe)

```php
// Message (implements QueryMessage<ResultType>)
/**
 * @implements QueryMessage<GetDashboardStatsResult>
 */
final readonly class GetDashboardStats implements QueryMessage {}

// Handler (named with Query suffix)
#[AsMessageHandler]
final readonly class GetDashboardStatsQuery
{
    public function __invoke(GetDashboardStats $query): GetDashboardStatsResult
    {
        return new GetDashboardStatsResult(...);
    }
}

// Result DTO
final readonly class GetDashboardStatsResult
{
    public function __construct(
        public int $totalUsers,
        public int $verifiedUsers,
    ) {}
}

// Usage - inject QueryBus, NOT MessageBusInterface
$stats = $this->queryBus->handle(new GetDashboardStats());
// $stats is typed as GetDashboardStatsResult
```

### Domain Events

```php
// Entity records events
class User implements EntityWithEvents
{
    use HasEvents;

    public function __construct(/* ... */)
    {
        $this->recordThat(new UserRegistered(
            userId: $this->id,
            email: $this->email,
            occurredOn: $this->createdAt,
        ));
    }
}

// Event (readonly DTO with occurredOn)
final readonly class UserRegistered
{
    public function __construct(
        public Uuid $userId,
        public string $email,
        public \DateTimeImmutable $occurredOn,
    ) {}
}

// Handler
#[AsMessageHandler]
final readonly class SendWelcomeEmailHandler
{
    public function __invoke(EmailVerified $event): void
    {
        // Side effect
    }
}
```

For delete events, use `#[HasDeleteDomainEvent(EventClass::class)]` attribute on entity.

### Single-Action Controllers

```php
#[Route('/portal/places', name: 'portal_place_list')]
final class PlaceListController extends AbstractController
{
    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    public function __invoke(): Response
    {
        // Single action
    }
}
```

**Rules:**
- Route at class level, NOT method level
- One controller = one route = one `__invoke()` method
- Use `final` modifier

### Forms (FormData + FormType)

```php
// FormData with validation
final class PlaceFormData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $name = '';

    public static function fromPlace(Place $place): self { /* ... */ }
}

// FormType maps to FormData
final class PlaceFormType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PlaceFormData::class]);
    }
}
```

### Exceptions

```php
#[WithHttpStatus(409)]
final class UserAlreadyExists extends \DomainException
{
    public static function withEmail(string $email): self
    {
        return new self(sprintf('User with email "%s" already exists.', $email));
    }
}
```

Available: `UserAlreadyExists`, `UserNotFound`, `PlaceNotFound`, `StorageTypeNotFound`, `UnverifiedUser`

## Conventions

- All files: `declare(strict_types=1);`
- Commands, events, DTOs: `final readonly`
- Enums over magic strings: `UserRole::ADMIN->value` not `'ROLE_ADMIN'`
- All entity IDs: UUID v7 via `ProvideIdentity` interface
- ID generation: Production uses `RandomIdentityProvider`, tests use `PredictableIdentityProvider`
- **Logging exceptions**: Always use `'exception' => $e` in logger context, never `$e->getMessage()`. Monolog extracts the message, trace, and class automatically from the `exception` key.
- **Migrations**: NEVER handwrite migration SQL. Always generate via `docker compose exec web bin/console make:migration` (or `doctrine:migrations:diff`) so the diff matches what `doctrine:schema:validate` expects. Handwritten DDL drifts from the runtime schema (entities + bundle-registered tables like `PdoSessionHandler`) and breaks the `migrations-up-to-date` CI job.
- **Manual `flush()`**: writes go through a messenger handler (command bus / event bus); the `doctrine_transaction` middleware flushes on success and rolls back on exception. **Default: never call `flush()` yourself.** Repositories, services, controllers, message handlers, voters — none of them flush. The narrow exceptions, which are the ONLY places it is allowed:
  - `DataFixtures` (no messenger envelope around them).
  - Symfony console `Command`s (no doctrine middleware on the request).
  - Kernel / security event subscribers that fire from the HTTP request lifecycle (`LoginSuccessEvent`, request-terminate hooks, etc.) — no middleware envelope, so they own their own flush.
  - Existing audit-log writer repositories that intentionally commit out-of-band so the audit row survives even if the parent transaction rolls back (e.g. `EmailLogRepository`).
  Every manual `flush()` MUST have an inline comment immediately above the call explaining why it is required. If you can't write that justification cleanly, it shouldn't be there — push the work back through a message handler instead.
- **Writes are silently LOST outside the messenger envelope** (this class of bug has shipped repeatedly — it makes features look like they work while nothing reaches the database):
  - The `doctrine_transaction` flush happens INSIDE `dispatch()`. **Anything persisted or mutated in a controller AFTER a `dispatch()` returns — including `AuditLogger::log()` — is never flushed and silently discarded.** Audit-log inside the handler, or before the dispatch, never after.
  - A controller that mutates an entity / calls a repository `save()` without dispatching anything at all flushes nothing either (that was the `/portal/admin/nastaveni` bug: success flash shown, value never saved).
  - In console commands a manual `flush()` covers only what precedes it; a mutation after the last flush is lost when it's the last iteration — or worse, accidentally committed by the NEXT iteration's dispatch.
  - Domain events recorded on entities (`recordThat()`) are dispatched by `DispatchDomainEventsMiddleware`, which only runs on the buses. **A manual `flush()` in a console command buffers the events forever — they never dispatch** (this silently dropped customer invoice e-mails). Route the mutation through a command on the bus instead.
  Full details and the audit that found these: [.claude/MESSENGER.md](.claude/MESSENGER.md) §5.

## Frontend

### Design system — NOT real DaisyUI (read before using any component class)

CSS is **Tailwind v4** (`assets/styles/app.css`, single `@import "tailwindcss"`), compiled by `symfonycasts/tailwind-bundle` using the **standalone Tailwind binary**. There is **no npm, no `package.json`, no `node_modules`, and no DaisyUI plugin.**

Despite a commit named *"DaisyUI migration"* and class names that look like DaisyUI (`btn-sm`, `btn-ghost`, `card`, `badge-success`, `table-zebra`, `modal-box`, …), **DaisyUI is not installed.** Those are a **hand-rolled subset** of DaisyUI-named classes, each defined manually with `@apply` in `app.css`. A DaisyUI class that nobody defined there (e.g. `input-bordered`, `btn-warning`, `form-select`, `select-bordered`, `textarea-bordered`) **compiles silently to nothing** — the element renders unstyled. Tailwind does not error on unknown classes, so this fails invisibly.

Rules:
- **Form controls** (`<input>`, `<select>`, `<textarea>`): use the `.form-input` class. Do NOT use DaisyUI form classes, and do NOT hand-roll ad-hoc `border-gray-300 rounded-md shadow-sm focus:…` utilities — `.form-input` owns the border, radius, shadow, and brand-accent focus. Checkboxes/radios are the exception (they keep `h-4 w-4 … border-gray-300 rounded`). Forms rendered via Symfony's form theme (`templates/form/tailwind_theme.html.twig`) already get `.form-input` automatically.
- **Any other component class** (`btn-*`, `badge-*`, `card`, `table`, `modal-*`, `alert-*`): before using it, confirm it is defined in `app.css`. If it isn't, either use one that is, or add the new variant to `app.css` (matching the existing `@apply` style) — never assume DaisyUI will provide it.
- After editing `app.css`, run `composer tailwind:build` (or `docker compose exec web php bin/console tailwind:build`). A clean build also validates every `@apply` references a real utility.

### Turbo

Hotwire Turbo is installed but **disabled globally** via `data-turbo="false"` on `<body>` in `base.html.twig`. To enable Turbo on specific elements, add `data-turbo="true"`:

```twig
<form data-turbo="true">...</form>
<a href="..." data-turbo="true">Link</a>
```

## Testing

- `tests/Unit/` - Domain logic (no database, fast)
- `tests/Integration/` - Repository/controller tests (uses DAMA DoctrineTestBundle)
- **MockClock**: Tests use fixed time `2025-06-15 12:00:00 UTC` - never use `new \DateTimeImmutable()`
- **Fixtures**: Prefer using fixture data over creating test data dynamically. See [.claude/FIXTURES.md](.claude/FIXTURES.md) for reference constants and available test data
- **Every controller MUST have at least one integration test.** Minimum bar per controller:
  - **Happy path**: the correct authenticated role gets the correct status code (`200` for GET pages, the expected `3xx` redirect / `Location` for POST actions, the right `Content-Type` for downloads/exports).
  - **Authorization**: assert the guard actually denies — unauthenticated → redirect to `/login`; wrong role → `403`; and for owner-scoped resources, a non-owner of the right role → `403` (cross-tenant isolation). This applies even to controllers that look "firewall-protected": `/portal/admin/*` is only `ROLE_USER` at the firewall, so the `ROLE_ADMIN` gate lives in the controller (`#[IsGranted]`) and must be tested. Public payment/webhook/signed-URL routes (`/objednavka/*`, `/pokuta/*`, `/opakovana-platba/*`, `/qr-platba/*`, document `*.pdf`/`*.png`) are under NO firewall — their only guard is the in-controller token / `UriSigner` / order-id check, so each needs an explicit "bad/missing signature or id → 404/403" test.
  - Shared role/redirect assertions for simple GET pages can live in the data-provider methods of `tests/Integration/Controller/ControllerAccessTest.php`; complex flows get a dedicated test file.
- **`composer quality` does NOT run integration tests** — it is `phpstan` + `test:unit` only. For any controller / template / form / routing change, run the full `composer test` before committing, or the controller tests above won't execute in your loop.

## Fixtures (Development Only)

- Admin: `admin@example.com` / `password`
- User: `user@example.com` / `password`
- Tenant: `tenant@example.com` / `password`
- Landlord: `landlord@example.com` / `password`
- Landlord2: `landlord2@example.com` / `password`
- Unverified: `unverified@example.com` / `password`

## Role Hierarchy

- `ROLE_ADMIN` - Full access
- `ROLE_LANDLORD` - Warehouse owner (Pronajímatel)
- `ROLE_USER` - Tenant (Nájemce)

## Compliance ruleset (READ BEFORE EDITING ORDER FLOW)

Order flow, payment, and any consumer-facing legal text are governed by [.claude/COMPLIANCE.md](.claude/COMPLIANCE.md). It captures locked-in rules from Czech consumer law (OZ § 1826a "tlačítková novela"), GoPay merchant obligations, and our own VOP / Podmínky opakovaných plateb / Poučení spotřebitele. Examples:

- Submit button MUST read exactly `OBJEDNÁVÁM a zaplatím`.
- Recurring-payment consent MUST be a dedicated, visibly separate checkbox — never folded into a bundled "I agree" master.
- Identification (Mekmann s.r.o., IČO 11678631, sídlo, …) MUST appear on every order-flow page.
- Prices always display with `vč. DPH`.
- Card + 3D Secure + GoPay-with-link logos MUST appear at every payment surface.

When a rule conflicts with a feature request, stop and consult [.claude/COMPLIANCE.md](.claude/COMPLIANCE.md) and the source documents (`public/documents/*.pdf`) before deviating.

## Customer-facing documents

Inventory of every document a customer can encounter (contract, invoice, map, VOP, poučení spotřebitele, formuláře, …), where each is generated, where it's stored, and how the customer accesses it: [.claude/CUSTOMER_DOCUMENTS.md](.claude/CUSTOMER_DOCUMENTS.md). Update that file whenever a document is added, removed, or moves between storage tiers / e-mail touchpoints.

**VOP is dynamic.** The legal master is `templates/documents/vop_template.docx`. The operator edits in Google Docs and exports to that path. Supported placeholders: `${PRICELIST_URL}` (resolves to `/pobocka/{id}/cenik`) and `${OPERATING_RULES_URL}` (resolves to the place's uploaded operating rules; falls back to the place detail page when none uploaded). The customer signature is stamped on body pages; the last 2 pages (form annexes) stay unsigned. If the annex count changes, update `VopPdfStamper`'s `$skipLastPages` argument in `config/services.php`. The on-page consent modal (`templates/public/_terms_and_conditions_content.html.twig`) is a separate Twig source — keep modal text and DOCX text in sync manually when wording changes.
