# Fajné Sklady

Warehouse management application built with Symfony 8, PHP 8.5, and PostgreSQL.

## Quick Start

```bash
git clone git@github.com:JanMikes/fajnesklady.cz.git
cd fajnesklady.cz
docker compose up -d
```

Application will be available at http://localhost:8080

## Access Points

| Service | URL |
|---------|-----|
| Application | http://localhost:8080 |
| Mailpit (email testing) | http://localhost:8025 |
| PostgreSQL | localhost:5432 |

## Development Credentials

| Role | Email | Password |
|------|-------|----------|
| Admin | admin@example.com | password |
| User | user@example.com | password |
| Unverified | unverified@example.com | password |

Load fixtures: `docker compose exec web composer db:reset`

## Common Commands

```bash
# Quality checks (run before committing)
docker compose exec web composer quality

# Tests
docker compose exec web composer test:unit
docker compose exec web composer test

# Code style
docker compose exec web composer cs:fix

# Database
docker compose exec web composer db:reset
docker compose exec web bin/console make:migration

# Tailwind CSS
docker compose exec web composer tailwind:watch
```

## Tech Stack

- **Backend**: Symfony 8, PHP 8.5, FrankenPHP
- **Database**: PostgreSQL 17, Doctrine ORM
- **Frontend**: Twig, Tailwind CSS, Stimulus
- **Architecture**: CQRS with Symfony Messenger
