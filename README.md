# Fajné Sklady

A modern warehouse management application built with Symfony 7.2 LTS, featuring clean architecture, CQRS pattern, and comprehensive user management.

## Features

- **User Management**: Complete registration, email verification, and authentication system
- **Role-Based Access Control**: ROLE_USER and ROLE_ADMIN with proper access restrictions
- **Password Reset**: Secure password reset flow with email verification
- **Admin Dashboard**: User management interface with role assignment
- **Clean Architecture**: CQRS pattern with Symfony Messenger
- **Modern Stack**: Symfony 7.2 LTS, PHP 8.4, PostgreSQL 17, FrankenPHP

## Technology Stack

- **Framework**: Symfony 7.2 LTS
- **PHP**: 8.4
- **Web Server**: FrankenPHP (with worker mode)
- **Database**: PostgreSQL 17
- **Frontend**: Tailwind CSS + DaisyUI (no JS frameworks)
- **Mail Testing**: Mailpit
- **Architecture**: Clean Architecture + CQRS + Domain-Driven Design
- **Messaging**: Symfony Messenger

## Prerequisites

- Docker
- Docker Compose
- Git

## Installation

### 1. Clone the Repository

```bash
git clone git@github.com:JanMikes/fajnesklady.cz.git
cd fajnesklady.cz
```

### 2. Start Docker Services

```bash
docker compose up -d
```

This will start:
- PHP (FrankenPHP) on port 8080 (HTTP)
- PostgreSQL on port 5432
- Mailpit UI on port 8025, SMTP on port 1025

### 3. Install Dependencies

Dependencies are automatically installed via the Docker entrypoint. If needed, you can manually run:

```bash
docker compose exec php composer install
```

### 4. Run Migrations

Migrations are automatically run via the Docker entrypoint. If needed, you can manually run:

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
```

### 5. Load Fixtures (Development Only)

```bash
docker compose exec php bin/console doctrine:fixtures:load --no-interaction
```

## Access Points

- **Application**: http://localhost:8080
- **Mailpit UI**: http://localhost:8025
- **PostgreSQL**: localhost:5432

## Default Credentials (Development Fixtures)

| Role | Email | Password |
|------|-------|----------|
| Admin | admin@example.com | password |
| User | user@example.com | password |
| Unverified User | unverified@example.com | password |

**Note**: These credentials are for development only and should never be used in production.

## Development

### Running Tests

```bash
# Run all unit tests
docker compose exec php composer test:unit

# Run all tests
docker compose exec php composer test

# Run with coverage
docker compose exec php composer test:coverage
```

### Code Quality

```bash
# Check code style
docker compose exec php composer cs:check

# Fix code style
docker compose exec php composer cs:fix

# Run PHPStan (level 8)
docker compose exec php composer phpstan

# Run all quality checks
docker compose exec php composer quality
```

### Database Commands

```bash
# Create migration
docker compose exec php bin/console make:migration

# Run migrations
docker compose exec php bin/console doctrine:migrations:migrate

# Load fixtures
docker compose exec php bin/console doctrine:fixtures:load

# Reset database
docker compose exec php composer db:reset
```

### Tailwind CSS

```bash
# Build Tailwind CSS
docker compose exec php composer tailwind:build

# Watch for changes
docker compose exec php composer tailwind:watch
```

### Accessing the Container

```bash
# PHP container shell
docker compose exec php bash

# PostgreSQL shell
docker compose exec postgres psql -U app -d app
```

## Project Structure

```
src/
├── Common/              # Shared code across modules
│   └── Email/          # Email service
│
├── User/               # User module
│   ├── Entity/        # Domain entities (User, ResetPasswordRequest)
│   ├── Repository/    # Data access
│   ├── Command/       # Write operations (CQRS)
│   ├── Query/         # Read operations (CQRS)
│   ├── Event/         # Domain events and handlers
│   ├── Security/      # Authentication logic
│   ├── Controller/    # HTTP controllers
│   └── Form/          # Form types
│
└── Admin/             # Admin module
    ├── Command/       # Admin write operations
    ├── Query/         # Admin read operations
    ├── Controller/    # Admin controllers
    └── Form/          # Admin forms
```

## Architecture

This project follows **Clean Architecture** principles with **CQRS** pattern:

- **Domain Layer**: Pure PHP entities with business logic (no framework dependencies)
- **Application Layer**: Commands, queries, and event handlers using Symfony Messenger
- **Infrastructure Layer**: Repositories, controllers, and framework integration
- **Presentation Layer**: Twig templates with Tailwind CSS

### Key Patterns

- **CQRS**: Separate command and query buses for write and read operations
- **Event-Driven**: Domain events for decoupled communication
- **Repository Pattern**: Abstract data access
- **Doctrine XML Mapping**: Keeps domain layer clean from ORM annotations

## Available Commands

### Composer Scripts

```bash
# Database
composer db:fixtures      # Load fixtures
composer db:reset         # Reset database (drop, create, migrate, fixtures)

# Testing
composer test             # Run all tests
composer test:unit        # Run unit tests
composer test:coverage    # Generate coverage report

# Code Quality
composer cs:check         # Check code style
composer cs:fix           # Fix code style
composer phpstan          # Run static analysis
composer quality          # Run all quality checks

# Assets
composer tailwind:build   # Build Tailwind CSS
composer tailwind:watch   # Watch Tailwind changes
composer assets:compile   # Compile asset map
```

## Email Testing

All emails are caught by Mailpit in development. Access the Mailpit UI at: http://localhost:8025

## Security

- CSRF protection enabled on all forms
- Password hashing with bcrypt
- Email verification required for login
- Role-based access control (RBAC)
- Secure password reset flow

## Testing

The project includes:
- **Unit Tests**: Testing domain entities and business logic
- **Integration Tests**: Testing repositories and database operations
- **Code Style**: PHP CS Fixer with PSR-12 and Symfony standards
- **Static Analysis**: PHPStan level 8

Current test coverage:
- 10/10 unit tests passing
- Code style: 100% compliant
- PHPStan: No errors (level 8)

## Troubleshooting

### Docker containers not starting

```bash
docker compose down -v
docker compose up -d
```

### Database issues

```bash
# Reset the database
docker compose exec php composer db:reset
```

### Cache issues

```bash
# Clear cache
docker compose exec php bin/console cache:clear
```

### Permission issues

```bash
# Fix var/ directory permissions
docker compose exec php chmod -R 777 var/
```

## Contributing

1. Follow PSR-12 coding standards
2. Ensure all tests pass
3. Run code quality checks before committing
4. Write tests for new features

## License

Proprietary

## Support

For issues and questions, please refer to the project documentation or create an issue on GitHub.
