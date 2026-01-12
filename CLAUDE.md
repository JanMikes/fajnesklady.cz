# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands for Development

### Docker Environment
All commands must be run inside the Docker container:
```bash
# Execute commands inside PHP container
docker compose exec web <command>

# Access shell
docker compose exec web bash
```

### Testing
```bash
# Run unit tests only
docker compose exec web composer test:unit

# Run all tests
docker compose exec web composer test

# Generate coverage report (outputs to var/coverage)
docker compose exec web composer test:coverage
```

### Code Quality
```bash
# Check code style (PSR-12 + Symfony standards)
docker compose exec web composer cs:check

# Auto-fix code style issues
docker compose exec web composer cs:fix

# Run PHPStan level 8 static analysis
docker compose exec web composer phpstan

# Run all quality checks (cs:check + phpstan + test:unit)
docker compose exec web composer quality
```

### Database
```bash
# Create a new migration
docker compose exec web bin/console make:migration

# Run migrations
docker compose exec web bin/console doctrine:migrations:migrate

# Load fixtures (development only)
docker compose exec web bin/console doctrine:fixtures:load

# Complete database reset (drop, create, migrate, load fixtures)
docker compose exec web composer db:reset

# Validate schema consistency
docker compose exec web bin/console doctrine:schema:validate
```

### Frontend Assets
```bash
# Build Tailwind CSS (production)
docker compose exec web composer tailwind:build

# Watch Tailwind changes (development)
docker compose exec web composer tailwind:watch

# Compile asset map
docker compose exec web composer assets:compile
```

## Architecture Overview

### CQRS Pattern with Symfony Messenger
This application strictly separates write operations (Commands) from read operations (Queries) using three message buses:

**Command Bus** (`command.bus`):
- Handles write operations that modify state
- Wrapped in database transactions via `doctrine_transaction` middleware
- All commands are validated before execution
- Each command must have exactly ONE handler
- Examples: `RegisterUserCommand`, `ResetPasswordCommand`

**Query Bus** (`query.bus`) via `App\Query\QueryBus`:
- Handles read operations with type-safe results via PHPStan generics
- No transaction wrapper (read-only)
- Validated but no transaction overhead
- **Always inject `QueryBus` class**, not `MessageBusInterface`

**Event Bus** (`event.bus`):
- Handles domain events asynchronously
- Allows zero or more handlers per event (`allow_no_handlers: true`)
- Used for side effects (sending emails, logging, analytics)
- Examples: `UserRegistered`, `EmailVerified`, `PasswordResetRequested`

### Message Routing
- Email sending (`SendEmailMessage`) is routed to `async` transport
- Commands, queries, and events are handled synchronously
- Failed messages are stored in `failed` transport (Doctrine-based)

### Directory Structure
The codebase uses a flat structure organized by technical layer:

```
src/
├── Command/              # Write operations (Commands + Handlers)
├── Controller/           # HTTP entry points
│   └── Admin/           # Admin controllers (routes prefixed with /admin)
├── DataFixtures/         # Development fixtures
├── Entity/               # Domain entities (User, ResetPasswordRequest)
├── Enum/                 # Value objects (UserRole)
├── Event/                # Domain events + Event handlers
├── Exception/            # Domain-specific exceptions
├── Form/                 # Symfony forms
├── Query/                # Read operations (Queries + Handlers + Results)
├── Repository/           # Data access (Doctrine implementations)
├── Security/             # Authentication, login subscribers
└── Kernel.php
```

### Domain Entity Patterns

**Entities use public constructor with ID passed in** - ID is generated externally:
```php
// ✓ CORRECT - ID passed from outside
$place = new Place(
    id: Uuid::v7(),
    name: $name,
    address: $address,
    ...
);

// ✗ WRONG - static named constructors
$place = Place::create(...);
```

**Let Doctrine infer names** - Avoid explicit table/column/index names unless necessary:
```php
// ✓ CORRECT - implicit naming
#[ORM\Entity]
class Place { ... }

#[ORM\Column]
private string $name;

#[ORM\JoinColumn(nullable: false)]
private User $owner;

// ✗ WRONG - explicit naming (unless needed)
#[ORM\Table(name: 'places')]
#[ORM\Index(name: 'places_owner_idx', columns: ['owner_id'])]
#[ORM\Column(name: 'owner_id')]

// Exception: Use explicit names for SQL reserved keywords
#[ORM\Table(name: 'users')]  // 'user' is reserved in PostgreSQL
class User { ... }
```

**Let Doctrine infer types from PHP types** - Use Types constants only when needed:
```php
// ✓ CORRECT - type inferred from PHP type
#[ORM\Column]
private int $price;  // Doctrine infers 'integer'

#[ORM\Column]
private string $name;  // Doctrine infers 'string'

// Use Types constants only when explicit type differs from PHP type
use Doctrine\DBAL\Types\Types;

#[ORM\Column(type: Types::TEXT)]  // text != string
private ?string $description;

#[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
private string $width;  // decimal stored as string

#[ORM\Column(type: 'uuid')]  // uuid is custom type
private Uuid $id;
```

**Use property hooks (PHP 8.4+) instead of getters**:
```php
// ✓ CORRECT - public private(set) with property hook
public private(set) string $name {
    get => $this->name;
}

// ✗ WRONG - explicit getter method
public function getName(): string { return $this->name; }
```

**Entities expose behavior methods, not setters**:
```php
// ✓ CORRECT
$user->changePassword($hashedPassword);
$user->markAsVerified();

// ✗ WRONG
$user->setPassword($password);
$user->setEmailVerified(true);
```

### Repository Pattern
**NEVER extend `ServiceEntityRepository`** - Use composition instead of inheritance:

Repositories use EntityManager injection (composition over inheritance):
```php
// ✓ CORRECT - Composition with EntityManager
final class UserRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function findById(Uuid $id): ?User
    {
        return $this->entityManager->find(User::class, $id);
    }
}

// ✗ WRONG - Inheritance from ServiceEntityRepository
class UserRepository extends ServiceEntityRepository { ... }
```

**Do NOT create repository interfaces** - Single implementation pattern:
- No need for `UserRepositoryInterface` - inject concrete `UserRepository` class
- Interfaces add unnecessary abstraction when there's only one implementation
- Symfony's autowiring works perfectly with concrete classes

**NEVER call `EntityManager::flush()` manually** - The `doctrine_transaction` middleware handles it:
```php
// ✓ CORRECT - persist/remove only, middleware handles flush
public function save(User $user): void
{
    $this->entityManager->persist($user);
}

public function delete(User $user): void
{
    $this->entityManager->remove($user);
}

// ✗ WRONG - manual flush (transaction middleware does this)
public function save(User $user): void
{
    $this->entityManager->persist($user);
    $this->entityManager->flush();  // NEVER do this
}
```

All write operations MUST go through Messenger command handlers where the `doctrine_transaction` middleware automatically:
1. Begins transaction before handler execution
2. Flushes and commits on success
3. Rolls back on exception

Exception: DataFixtures run outside Messenger, so they require manual `flush()`.

Repositories provide:
- Type-safe methods (no generic `find()` or `findBy()`)
- Performance-optimized queries (COUNT instead of loading entities)
- Domain-specific query methods (`countVerified()`, `findByEmail()`)
- EntityManager for complex queries and persistence operations

**NEVER use `getRepository()` or EntityRepository methods** - Always use QueryBuilder:
```php
// ✓ CORRECT - QueryBuilder for queries
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

// ✓ CORRECT - EntityManager::find() for ID lookup
public function findById(Uuid $id): ?User
{
    return $this->entityManager->find(User::class, $id);
}

// ✗ WRONG - Using getRepository()
return $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

// ✗ WRONG - Using EntityRepository methods
return $this->entityManager->getRepository(User::class)->findBy(['status' => 'active']);
```

**Use Query Bus for complex queries** - Custom outputs, aggregations, calculations:
- Repositories return entities or simple counts
- For queries with custom DTOs, aggregations, joins, calculations → use Query Bus pattern
- Query handlers use QueryBuilder or raw SQL for optimized reads
- **Always use `App\Query\QueryBus`** - provides type-safe results via PHPStan generics

**Query naming convention:**
- Message: `GetUserStatistics` (no suffix, implements `Query<ResultType>`)
- Handler: `GetUserStatisticsQuery` (Query suffix, has `#[AsMessageHandler]`)
- Result: `GetUserStatisticsResult` (Result suffix)

```php
// Message - implements Query with generic result type
/**
 * @implements Query<UserStatisticsResult>
 */
final readonly class GetUserStatistics implements Query
{
    public function __construct(public Uuid $userId) {}
}

// Handler - named with Query suffix
#[AsMessageHandler]
final readonly class GetUserStatisticsQuery
{
    public function __invoke(GetUserStatistics $query): UserStatisticsResult
    {
        // Query logic here
    }
}

// Result DTO
final readonly class UserStatisticsResult
{
    public function __construct(
        public int $totalOrders,
        public string $totalRevenue,
        public \DateTimeImmutable $lastOrderDate,
    ) {}
}

// Usage in controller - inject QueryBus, get typed result
public function __construct(private readonly QueryBus $queryBus) {}

public function __invoke(): Response
{
    $stats = $this->queryBus->handle(new GetUserStatistics($userId));
    // $stats is typed as UserStatisticsResult
}
```

### Event-Driven Architecture
Commands dispatch domain events that trigger side effects:

1. **Command Handler** executes business logic → saves entity → dispatches event
2. **Event Handler** reacts to event (sends email, logs action, etc.)

Example flow:
```
RegisterUserCommand
  → RegisterUserHandler (creates user)
    → UserRegistered event
      → SendVerificationEmailHandler (sends email)
      → SendWelcomeEmailHandler (sends welcome email)
```

Events are readonly DTOs with `occurredOn` timestamp. Event handlers must be idempotent.

## Important Conventions

### Single Action Controllers
**All controllers MUST be single-action (invokable)** using the `__invoke()` method:

```php
// ✓ CORRECT - Single action controller
#[Route('/login', name: 'app_login')]
final class LoginController extends AbstractController
{
    public function __invoke(AuthenticationUtils $authenticationUtils): Response
    {
        // Controller logic here
    }
}

// ✗ WRONG - Multiple actions in one controller
final class AuthController extends AbstractController
{
    public function login(): Response { ... }
    public function logout(): Response { ... }
    public function register(): Response { ... }
}
```

Key points:
- Route defined at class level (not method level)
- One controller = one action = one route
- Use `final` for controllers extending `AbstractController`
- Use `final readonly` for standalone controllers without `AbstractController`
- Controller names should reflect their action (e.g., `LoginController`, `RegisterController`, `UserListController`)

### Strict Types
All PHP files MUST declare `declare(strict_types=1);` at the top.

### Readonly Objects
Commands, events, and DTOs are `readonly` to prevent mutation:
```php
final readonly class RegisterUserCommand { ... }
final readonly class UserRegistered { ... }
```

### Enums Over Magic Strings
Use `UserRole` enum instead of string literals:
```php
// ✓ CORRECT
use App\Enum\UserRole;
if (in_array(UserRole::ADMIN->value, $user->getRoles())) { ... }

// ✗ WRONG
if (in_array('ROLE_ADMIN', $user->getRoles())) { ... }
```

### Custom Exceptions
Throw domain-specific exceptions with named constructors:
```php
// ✓ CORRECT
throw UserAlreadyExistsException::withEmail($email);
throw UserNotFoundException::withId($userId);

// ✗ WRONG
throw new \DomainException('User already exists');
throw new \RuntimeException('User not found');
```

Available exceptions:
- `UserAlreadyExistsException`
- `UserNotFoundException`
- `UnverifiedUserException`

### Validation
Commands are validated using Symfony Validator constraints before handler execution. Validation happens automatically via `validation` middleware on all buses.

### UUIDs
All entity IDs use Symfony UID (UUID v7) for better database performance and sortability.

## Security Features

### Password Reset Flow
1. User requests reset → Token generated (1 hour expiry)
2. Email sent with reset link
3. User clicks link → Token validated
4. New password set → Token invalidated
5. Timing attack prevention: Same execution time whether user exists or not

### Email Verification
Registration requires email verification:
- Unverified users cannot log in
- Verification tokens generated by SymfonyCasts Verify Email Bundle
- Email must be verified before account access

### Security Headers
`SecurityHeadersSubscriber` adds:
- Content Security Policy (CSP)
- Permissions Policy
- X-Content-Type-Options
- X-Frame-Options
- Referrer-Policy

**TODO**: Remove `unsafe-inline` from CSP by implementing nonce support.

### User Roles & Permissions
Symfony role hierarchy:
- `ROLE_ADMIN`: Full access, inherits all roles
- `ROLE_LANDLORD`: Warehouse owner/manager, inherits `ROLE_USER`
- `ROLE_USER`: Base role for tenants renting storage

Czech translations:
- Pronajímatel = `ROLE_LANDLORD`
- Nájemce = `ROLE_USER`

## Testing Strategy

### Unit Tests
Located in `tests/Unit/` - test domain entities and business logic in isolation:
- No database required
- Fast execution
- Test entity methods, value objects, calculations

### Integration Tests (Future)
Will test repositories, database operations, and handler integration.

### PHPStan Configuration
- Level 8 (strictest)
- Ignores integration tests temporarily (service container issue)
- Custom ignores for Symfony runtime behavior

## Code Quality Standards

### PHP CS Fixer
- PSR-12 + Symfony coding standards
- Enforces `declare(strict_types=1);`
- Alphabetically ordered imports
- Trailing commas in multiline arrays

### Before Committing
Always run quality checks:
```bash
docker compose exec web composer quality
```
This runs: cs:check + phpstan + test:unit

## Fixtures

Development fixtures create three test users:
- Admin: `admin@example.com` / `password`
- User: `user@example.com` / `password`
- Unverified: `unverified@example.com` / `password`

**NEVER use these in production.**

## Common Pitfalls

### Don't Load Entities for Counting
```php
// ✗ WRONG - Loads ALL users into memory
$users = $userRepository->findAll();
$count = count($users);

// ✓ CORRECT - Efficient COUNT query
$count = $userRepository->countTotal();
```

### Don't Use Generic Repository Methods
```php
// ✗ WRONG - Bypasses type safety
$user = $userRepository->findOneBy(['email' => $email]);

// ✓ CORRECT - Type-safe domain method
$user = $userRepository->findByEmail($email);
```

### Don't Skip Transactions
Command handlers automatically run in transactions. Don't manually manage transactions unless you have a specific reason.

### Don't Call flush() Manually
```php
// ✗ WRONG - manual flush
$this->entityManager->persist($entity);
$this->entityManager->flush();

// ✓ CORRECT - persist only, middleware handles flush
$this->entityManager->persist($entity);
```
The `doctrine_transaction` middleware on the command bus handles flush and transaction management. All write operations must go through Messenger command handlers.

- Always run any command in docker!
- Always run tests, phpstan, coding standard (static analysis) after doing code changes, to make sure everything works!
