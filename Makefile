COMPOSE = docker compose
PHP     = $(COMPOSE) exec -T php
CONSOLE = $(PHP) php bin/console

.DEFAULT_GOAL := help

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-18s\033[0m %s\n", $$1, $$2}'

up: ## Build, start containers, install deps, migrate, start worker
	$(COMPOSE) up -d --build postgres php nginx
	$(PHP) composer install --no-interaction --prefer-dist
	$(MAKE) migrate
	$(COMPOSE) --profile worker up -d worker

down: ## Stop containers
	$(COMPOSE) --profile worker down

destroy: ## Stop containers and remove volumes
	$(COMPOSE) --profile worker down -v

sh: ## Shell into php container
	$(COMPOSE) exec php sh

composer: ## Run composer, e.g. make composer c="require foo/bar"
	$(PHP) composer $(c)

console: ## Run bin/console, e.g. make console c="debug:router"
	$(CONSOLE) $(c)

migrate: ## Run migrations (dev) and set up messenger transports
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration
	$(CONSOLE) messenger:setup-transports --no-interaction

test-db: ## Create/migrate the test database
	$(CONSOLE) --env=test doctrine:database:create --if-not-exists --no-interaction
	$(CONSOLE) --env=test doctrine:migrations:migrate --no-interaction --allow-no-migration

test: test-db ## Run the whole test suite
	$(PHP) vendor/bin/phpunit

test-unit: ## Run unit tests only (no database)
	$(PHP) vendor/bin/phpunit --testsuite Unit

CAPACITY ?= 10
ATTEMPTS ?= 60

test-concurrency: ## Fire concurrent bookings against the running API (override: make test-concurrency CAPACITY=1 ATTEMPTS=100)
	$(COMPOSE) exec -T -e CAPACITY=$(CAPACITY) -e ATTEMPTS=$(ATTEMPTS) php php bin/concurrency-test

stan: ## Static analysis
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

cs: ## Code style check
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Code style fix
	$(PHP) vendor/bin/php-cs-fixer fix

logs: ## Tail logs
	$(COMPOSE) --profile worker logs -f --tail=100

.PHONY: help up down destroy sh composer console migrate test-db test test-unit test-concurrency stan cs cs-fix logs
