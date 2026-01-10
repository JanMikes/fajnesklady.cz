# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands for Development

### Docker Environment
All commands must be run inside the Docker container:
```bash
# Execute commands inside PHP container
docker compose exec php <command>

# Access shell
docker compose exec php bash
```

### Testing
```bash
# Run unit tests only
docker compose exec php composer test:unit

# Run all tests
docker compose exec php composer test

# Generate coverage report (outputs to var/coverage)
docker compose exec php composer test:coverage
```

### Code Quality
```bash
# Check code style (PSR-12 + Symfony standards)
docker compose exec php composer cs:check

# Auto-fix code style issues
docker compose exec php composer cs:fix

# Run PHPStan level 8 static analysis
docker compose exec php composer phpstan

# Run all quality checks (cs:check + phpstan + test:unit)
docker compose exec php composer quality
```

### Database
```bash
# Create a new migration
docker compose exec php bin/console make:migration

# Run migrations
docker compose exec php bin/console doctrine:migrations:migrate

# Load fixtures (development only)
docker compose exec php bin/console doctrine:fixtures:load

# Complete database reset (drop, create, migrate, load fixtures)
docker compose exec php composer db:reset

# Validate schema consistency
docker compose exec php bin/console doctrine:schema:validate
```

### Frontend Assets
```bash
# Build Tailwind CSS (production)
docker compose exec php composer tailwind:build

# Watch Tailwind changes (development)
docker compose exec php composer tailwind:watch

# Compile asset map
docker compose exec php composer assets:compile
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

**Query Bus** (`query.bus`):
- Handles read operations (currently unused - queries not yet extracted)
- No transaction wrapper (read-only)
- Validated but no transaction overhead

**Event Bus** (`event.bus`):
- Handles domain events asynchronously
- Allows zero or more handlers per event (`allow_no_handlers: true`)
- Used for side effects (sending emails, logging, analytics)
- Examples: `UserRegistered`, `EmailVerified`, `PasswordResetRequested`

### Message Routing
- Email sending (`SendEmailMessage`) is routed to `async` transport
- Commands, queries, and events are handled synchronously
- Failed messages are stored in `failed` transport (Doctrine-based)

### Module Structure
The codebase is organized by business modules (User, Admin, Common):

```
src/
├── User/                      # User management bounded context
│   ├── Command/              # Write operations (Commands + Handlers)
│   ├── Query/                # Read operations (not yet implemented)
│   ├── Event/                # Domain events + Event handlers
│   ├── Entity/               # Domain entities (User, ResetPasswordRequest)
│   ├── Repository/           # Data access (interface + Doctrine implementation)
│   ├── Controller/           # HTTP entry points
│   ├── Form/                 # Symfony forms
│   ├── Security/             # Authentication, login subscribers
│   ├── Enum/                 # Value objects (UserRole)
│   └── Exception/            # Domain-specific exceptions
├── Admin/                     # Admin panel bounded context
│   ├── Command/              # Admin write operations
│   ├── Query/                # Admin read operations (GetDashboardStats)
│   ├── Controller/           # Admin HTTP controllers
│   └── Form/                 # Admin forms
└── Common/                    # Shared code across modules
    ├── Email/                # Email service
    ├── ValueObject/          # Shared value objects
    ├── Controller/           # Shared controllers (HealthCheck)
    └── EventSubscriber/      # Global subscribers (SecurityHeaders)
```

### Domain Entity Patterns

**Entities use named constructors** - Never use `new Entity()` directly:
```php
// ✓ CORRECT
$user = User::create(email: $email, name: $name, password: '');

// ✗ WRONG
$user = new User();
```

**Entities expose behavior methods, not setters**:
```php
// ✓ CORRECT
$user->changePassword($hashedPassword);
$user->verifyEmail();
$user->recordFailedLoginAttempt();

// ✗ WRONG
$user->setPassword($password);  // This method doesn't exist
$user->setEmailVerified(true);  // This method doesn't exist
```

**Doctrine mapping is XML-based** - No annotations in entities:
- Keeps entities free of framework dependencies
- Mapping files: `config/doctrine/*.orm.xml`
- When modifying entities, update corresponding XML mapping

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

Repositories provide:
- Type-safe methods (no generic `find()` or `findBy()`)
- Performance-optimized queries (COUNT instead of loading entities)
- Domain-specific query methods (`countVerified()`, `findByEmail()`)
- EntityManager for complex queries and persistence operations

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
use App\User\Enum\UserRole;
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
- `AccountLockedException`

### Validation
Commands are validated using Symfony Validator constraints before handler execution. Validation happens automatically via `validation` middleware on all buses.

### UUIDs
All entity IDs use Symfony UID (UUID v7) for better database performance and sortability.

## Security Features

### Account Lockout
Users are locked for 15 minutes after 5 failed login attempts:
- Attempts tracked per user account (not just IP)
- Auto-expiration after lockout period
- Reset on successful login

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
docker compose exec php composer quality
```
This runs: cs:check + phpstan + test:unit

## Database Schema

### Users Table
Key fields:
- `id` (UUID) - Primary key
- `email` (unique) - User email
- `password` - Bcrypt hashed
- `name` - User display name
- `roles` (JSON) - Array of roles
- `is_verified` (indexed) - Email verification status
- `failed_login_attempts` - Failed login counter
- `locked_until` - Account lock expiration
- `created_at` (indexed) - Registration timestamp

### Performance Indexes
- `idx_users_is_verified` - Fast filtering by verification status
- `idx_users_created_at` - Efficient sorting by registration date

## Fixtures

Development fixtures create three test users:
- Admin: `admin@example.com` / `password`
- User: `user@example.com` / `password`
- Unverified: `unverified@example.com` / `password`

**NEVER use these in production.**

## Health Check

`/-/health-check/liveness` endpoint returns JSON with:
- Database connectivity status
- PHP version
- Debug mode status
- HTTP 200 (healthy) or 503 (unhealthy)

Use for Docker health checks and monitoring.

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

### Don't Hash Passwords Yourself
```php
// ✗ WRONG - Manual hashing
$user->changePassword(password_hash($password, PASSWORD_BCRYPT));

// ✓ CORRECT - Use Symfony's UserPasswordHasher
$hashedPassword = $this->passwordHasher->hashPassword($user, $password);
$user->changePassword($hashedPassword);
```

### Don't Skip Transactions
Command handlers automatically run in transactions. Don't manually manage transactions unless you have a specific reason.

- Always run any command in docker!
- Always run tests, phpstan, coding standard (static analysis) after doing code changes, to make sure everything works!
