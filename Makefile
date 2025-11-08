.PHONY: help up down restart reset logs shell db test cs phpstan

# Default target
.DEFAULT_GOAL := help

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Available targets:'
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'

up: ## Start Docker containers
	docker compose up -d

down: ## Stop Docker containers
	docker compose down

restart: ## Restart Docker containers
	docker compose restart

reset: ## Rebuild and restart containers (clean slate)
	docker compose down -v
	docker compose build --no-cache
	docker compose up -d

logs: ## Follow container logs
	docker compose logs -f

logs-php: ## Follow PHP container logs
	docker compose logs -f php

shell: ## Open shell in PHP container
	docker compose exec php bash

db: ## Open PostgreSQL shell
	docker compose exec postgres psql -U app -d app

migrations: ## Run database migrations
	docker compose exec php bin/console doctrine:migrations:migrate --no-interaction

fixtures: ## Load database fixtures
	docker compose exec php bin/console doctrine:fixtures:load --no-interaction

db-reset: ## Reset database (drop, create, migrate, fixtures)
	docker compose exec php bin/console doctrine:database:drop --force --if-exists
	docker compose exec php bin/console doctrine:database:create
	docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
	docker compose exec php bin/console doctrine:fixtures:load --no-interaction

test: ## Run PHPUnit tests
	docker compose exec php bin/phpunit

cs-check: ## Check code style
	docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Fix code style
	docker compose exec php vendor/bin/php-cs-fixer fix

phpstan: ## Run PHPStan analysis
	docker compose exec php vendor/bin/phpstan analyse

quality: cs-check phpstan test ## Run all quality checks

tailwind-build: ## Build Tailwind CSS
	docker compose exec php bin/console tailwind:build

tailwind-watch: ## Watch and rebuild Tailwind CSS
	docker compose exec php bin/console tailwind:build --watch

cache-clear: ## Clear Symfony cache
	docker compose exec php bin/console cache:clear
