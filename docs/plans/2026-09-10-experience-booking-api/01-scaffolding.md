# Fase 1 — Scaffolding

Lee primero `00-overview.md` (Global Constraints). Esta fase deja el proyecto arrancando en Docker con Symfony 8.1, PostgreSQL 18, PHPUnit, PHPStan y CS-Fixer, y un test de humo verde.

---

### Task 1: Proyecto Symfony en Docker + herramientas de calidad

**Files:**
- Create: `composer.json`, `compose.yaml`, `Makefile`, `.env`, `.env.test`, `.gitignore`, `.editorconfig`
- Create: `docker/php/Dockerfile`, `docker/php/conf.d/app.ini`, `docker/php/php-fpm.d/zz-app.conf`, `docker/nginx/default.conf`
- Create: `phpunit.dist.xml`, `phpstan.dist.neon`, `.php-cs-fixer.dist.php`, `tests/bootstrap.php`
- Overwrite (tras `composer install`, Flex los genera): `config/services.yaml`, `config/routes.yaml`, `config/packages/framework.yaml`, `config/packages/doctrine.yaml`, `config/packages/messenger.yaml`, `config/packages/mailer.yaml`, `config/packages/validator.yaml`, `config/packages/dama_doctrine_test.yaml`
- Test: `tests/Functional/KernelBootsTest.php`

**Interfaces:**
- Produces: comandos `make up`, `make composer c="…"`, `make console c="…"`, `make migrate`, `make test`, `make test-unit`, `make stan`, `make cs`, `make cs-fix`, `make test-concurrency`. Contenedor `php` con `/app` montado. API en `http://localhost:8080`.

- [ ] **Step 1: `.gitignore`, `.editorconfig`**

`.gitignore`:
```
/vendor/
/var/
/.env.local
/.env.*.local
/.phpunit.cache/
/.php-cs-fixer.cache
/phpstan.neon
/phpunit.xml
/.php-cs-fixer.php
```

`.editorconfig`:
```
root = true

[*]
charset = utf-8
end_of_line = lf
insert_final_newline = true
indent_style = space
indent_size = 4
trim_trailing_whitespace = true

[Makefile]
indent_style = tab

[*.{yaml,yml,json,xml,md}]
indent_size = 2
```

- [ ] **Step 2: `composer.json`**

```json
{
    "name": "yuuues/experience-booking-api",
    "description": "Experience booking API — DDD + hexagonal architecture",
    "type": "project",
    "license": "MIT",
    "minimum-stability": "stable",
    "prefer-stable": true,
    "require": {
        "php": ">=8.5",
        "ext-ctype": "*",
        "ext-iconv": "*",
        "ext-intl": "*",
        "ext-pdo_pgsql": "*",
        "doctrine/dbal": "^4.4",
        "doctrine/doctrine-bundle": "^3.3",
        "doctrine/doctrine-migrations-bundle": "^4.0",
        "doctrine/orm": "^3.7",
        "symfony/console": "8.1.*",
        "symfony/doctrine-messenger": "8.1.*",
        "symfony/dotenv": "8.1.*",
        "symfony/flex": "^2.11",
        "symfony/framework-bundle": "8.1.*",
        "symfony/mailer": "8.1.*",
        "symfony/messenger": "8.1.*",
        "symfony/monolog-bundle": "^4.0",
        "symfony/property-access": "8.1.*",
        "symfony/runtime": "8.1.*",
        "symfony/serializer": "8.1.*",
        "symfony/uid": "8.1.*",
        "symfony/validator": "8.1.*",
        "symfony/yaml": "8.1.*"
    },
    "require-dev": {
        "dama/doctrine-test-bundle": "^8.6",
        "friendsofphp/php-cs-fixer": "^3.95",
        "phpstan/phpstan": "^2.2",
        "phpstan/phpstan-doctrine": "^2.0",
        "phpstan/phpstan-symfony": "^2.0",
        "phpunit/phpunit": "^13.3",
        "symfony/browser-kit": "8.1.*",
        "symfony/http-client": "8.1.*"
    },
    "config": {
        "allow-plugins": {
            "php-http/discovery": true,
            "symfony/flex": true,
            "symfony/runtime": true
        },
        "optimize-autoloader": true,
        "sort-packages": true
    },
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "App\\Tests\\": "tests/"
        }
    },
    "replace": {
        "symfony/polyfill-ctype": "*",
        "symfony/polyfill-iconv": "*",
        "symfony/polyfill-php72": "*",
        "symfony/polyfill-php73": "*",
        "symfony/polyfill-php74": "*",
        "symfony/polyfill-php80": "*",
        "symfony/polyfill-php81": "*",
        "symfony/polyfill-php82": "*",
        "symfony/polyfill-php83": "*",
        "symfony/polyfill-php84": "*"
    },
    "scripts": {
        "auto-scripts": {
            "cache:clear": "symfony-cmd"
        },
        "post-install-cmd": ["@auto-scripts"],
        "post-update-cmd": ["@auto-scripts"]
    },
    "extra": {
        "symfony": {
            "allow-contrib": false,
            "require": "8.1.*"
        }
    }
}
```

- [ ] **Step 3: Docker**

`docker/php/Dockerfile`:
```dockerfile
FROM php:8.5-fpm-alpine

RUN apk add --no-cache icu-dev libpq-dev $PHPIZE_DEPS \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql opcache \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY conf.d/app.ini /usr/local/etc/php/conf.d/app.ini
COPY php-fpm.d/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf

ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app
```

`docker/php/conf.d/app.ini`:
```ini
date.timezone = UTC
memory_limit = 512M
opcache.enable = 1
opcache.enable_cli = 0
opcache.validate_timestamps = 1
realpath_cache_size = 4096K
```

`docker/php/php-fpm.d/zz-app.conf` (aforo de workers para el test de concurrencia):
```ini
[www]
pm = dynamic
pm.max_children = 32
pm.start_servers = 8
pm.min_spare_servers = 4
pm.max_spare_servers = 16
```

`docker/nginx/default.conf`:
```nginx
server {
    listen 80;
    server_name _;
    root /app/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass php:9000;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }
}
```

`compose.yaml`:
```yaml
services:
  php:
    build: docker/php
    volumes:
      - .:/app
    depends_on:
      postgres:
        condition: service_healthy

  nginx:
    image: nginx:1.27-alpine
    ports:
      - "8080:80"
    volumes:
      - .:/app:ro
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      - php

  worker:
    build: docker/php
    command: php bin/console messenger:consume async --time-limit=3600 -vv
    volumes:
      - .:/app
    restart: unless-stopped
    profiles: [worker]
    depends_on:
      postgres:
        condition: service_healthy

  postgres:
    image: postgres:18-alpine
    environment:
      POSTGRES_DB: app
      POSTGRES_USER: app
      POSTGRES_PASSWORD: app
    ports:
      - "5432:5432"
    volumes:
      - pgdata:/var/lib/postgresql
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U app -d app"]
      interval: 3s
      timeout: 3s
      retries: 20

volumes:
  pgdata:
```

- [ ] **Step 4: `Makefile`** (indentación con TAB real)

```makefile
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

test-concurrency: ## Fire concurrent bookings against the running API
	$(PHP) php bin/concurrency-test

stan: ## Static analysis
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

cs: ## Code style check
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Code style fix
	$(PHP) vendor/bin/php-cs-fixer fix

logs: ## Tail logs
	$(COMPOSE) --profile worker logs -f --tail=100

.PHONY: help up down destroy sh composer console migrate test-db test test-unit test-concurrency stan cs cs-fix logs
```

- [ ] **Step 5: Build e instalación (Flex genera el esqueleto)**

```bash
docker compose up -d --build postgres php nginx
docker compose exec -T php composer install --no-interaction --prefer-dist
```
Expected: `vendor/` creado, Flex genera `bin/console`, `public/index.php`, `src/Kernel.php`, `config/bundles.php`, `config/packages/*.yaml`, `.env`, `symfony.lock`. Si Flex pregunta por Docker config, contesta `n`.

Comprueba: `docker compose exec -T php php -v` → `PHP 8.5.x`.

- [ ] **Step 6: Entorno**

`.env` (sobrescribir el generado):
```dotenv
APP_ENV=dev
APP_SECRET=change-me-in-production
APP_TIMEZONE=Europe/Madrid
DATABASE_URL="postgresql://app:app@postgres:5432/app?serverVersion=18&charset=utf8"
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=false
MAILER_DSN=null://null
MAILER_FROM=bookings@experiences.test
```

`.env.test`:
```dotenv
APP_SECRET=test
MESSENGER_TRANSPORT_DSN=sync://
MAILER_DSN=null://null
```

- [ ] **Step 7: Configuración Symfony (sobrescribir lo generado por Flex)**

`config/services.yaml`:
```yaml
parameters:
  app.timezone: '%env(APP_TIMEZONE)%'
  app.mailer_from: '%env(MAILER_FROM)%'

services:
  _defaults:
    autowire: true
    autoconfigure: true

  App\:
    resource: '../src/'
    exclude:
      - '../src/Kernel.php'
      - '../src/*/Domain/'
      - '../src/*/Application/**/*Command.php'
      - '../src/*/Application/**/*Query.php'
      - '../src/*/Application/*Response.php'
      - '../src/*/Infrastructure/Http/*Request.php'
```

`config/routes.yaml`:
```yaml
controllers:
  resource:
    path: ../src/
    namespace: App
  type: attribute
```

`config/packages/framework.yaml`:
```yaml
framework:
  secret: '%env(APP_SECRET)%'
  http_method_override: false
  handle_all_throwables: true
  php_errors:
    log: true
  session: false
  serializer:
    enabled: true
  property_access:
    enabled: true

when@test:
  framework:
    test: true
```

`config/packages/doctrine.yaml` (los `types` se irán añadiendo en fases posteriores; deja el bloque vacío ahora):
```yaml
doctrine:
  dbal:
    url: '%env(resolve:DATABASE_URL)%'
    types: {}
  orm:
    enable_native_lazy_objects: true
    naming_strategy: doctrine.orm.naming_strategy.underscore_number_aware
    controller_resolver:
      auto_mapping: false
    mappings:
      Shared:
        type: xml
        dir: '%kernel.project_dir%/config/doctrine/Shared'
        prefix: 'App\Shared\Domain'
        is_bundle: false
      Experience:
        type: xml
        dir: '%kernel.project_dir%/config/doctrine/Experience'
        prefix: 'App\Experience\Domain'
        is_bundle: false
      Session:
        type: xml
        dir: '%kernel.project_dir%/config/doctrine/Session'
        prefix: 'App\Session\Domain'
        is_bundle: false
      Booking:
        type: xml
        dir: '%kernel.project_dir%/config/doctrine/Booking'
        prefix: 'App\Booking\Domain'
        is_bundle: false

when@test:
  doctrine:
    dbal:
      dbname_suffix: '_test%env(default::TEST_TOKEN)%'

when@prod:
  doctrine:
    orm:
      query_cache_driver:
        type: pool
        pool: doctrine.system_cache_pool
      result_cache_driver:
        type: pool
        pool: doctrine.result_cache_pool
  framework:
    cache:
      pools:
        doctrine.result_cache_pool:
          adapter: cache.app
        doctrine.system_cache_pool:
          adapter: cache.system
```
Crea los cuatro directorios de mapping vacíos con un `.gitkeep`: `config/doctrine/{Shared,Experience,Session,Booking}/.gitkeep`.

`config/packages/messenger.yaml`:
```yaml
framework:
  messenger:
    failure_transport: failed
    transports:
      async:
        dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
        retry_strategy:
          max_retries: 3
          multiplier: 2
      failed: 'doctrine://default?queue_name=failed'
    routing:
      App\Shared\Domain\DomainEvent: async
```

`config/packages/mailer.yaml`:
```yaml
framework:
  mailer:
    dsn: '%env(MAILER_DSN)%'
```

`config/packages/validator.yaml`:
```yaml
framework:
  validation:
    email_validation_mode: html5
```

`config/packages/dama_doctrine_test.yaml`:
```yaml
when@test:
  dama_doctrine_test:
    enable_static_connection: true
    enable_static_meta_data_cache: true
    enable_static_query_cache: true
```

Comprueba que `config/bundles.php` contiene `DAMA\DoctrineTestBundle\DAMADoctrineTestBundle::class => ['test' => true]` (Flex lo añade; si no, añádelo).

- [ ] **Step 8: PHPUnit, PHPStan, CS-Fixer**

`tests/bootstrap.php`:
```php
<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
```

`phpunit.dist.xml`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         cacheDirectory=".phpunit.cache"
         failOnWarning="true"
         failOnDeprecation="false"
         displayDetailsOnTestsThatTriggerErrors="true"
         displayDetailsOnTestsThatTriggerWarnings="true">
  <php>
    <ini name="display_errors" value="1"/>
    <ini name="error_reporting" value="-1"/>
    <server name="APP_ENV" value="test" force="true"/>
    <server name="KERNEL_CLASS" value="App\Kernel"/>
    <server name="SHELL_VERBOSITY" value="-1"/>
  </php>
  <testsuites>
    <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
    <testsuite name="Integration"><directory>tests/Integration</directory></testsuite>
    <testsuite name="Functional"><directory>tests/Functional</directory></testsuite>
  </testsuites>
  <source>
    <include><directory>src</directory></include>
  </source>
  <extensions>
    <bootstrap class="DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension"/>
  </extensions>
</phpunit>
```

`phpstan.dist.neon`:
```neon
includes:
  - vendor/phpstan/phpstan-symfony/extension.neon
  - vendor/phpstan/phpstan-doctrine/extension.neon
  - vendor/phpstan/phpstan-doctrine/rules.neon

parameters:
  level: max
  paths:
    - src
    - tests
  symfony:
    containerXmlPath: var/cache/test/App_KernelTestDebugContainer.xml
  doctrine:
    objectManagerLoader: tests/object-manager.php
  tmpDir: var/phpstan
```

`tests/object-manager.php`:
```php
<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__.'/../.env');

$kernel = new Kernel('test', true);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
```

`.php-cs-fixer.dist.php`:
```php
<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/migrations', __DIR__.'/bin'])
    ->name('*.php')
    ->name('concurrency-test');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PER-CS2.0' => true,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'global_namespace_import' => ['import_classes' => true, 'import_constants' => false, 'import_functions' => false],
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
        'phpdoc_to_comment' => false,
    ])
    ->setFinder($finder);
```

Crea `migrations/.gitkeep` y `bin/.gitkeep` si el directorio `bin/` no existe todavía (Flex crea `bin/console`).

- [ ] **Step 9: Test de humo**

`tests/Functional/KernelBootsTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class KernelBootsTest extends KernelTestCase
{
    #[Test]
    public function kernel_boots_in_test_environment(): void
    {
        self::bootKernel();

        self::assertSame('test', self::$kernel->getEnvironment());
        self::assertTrue(self::getContainer()->has('doctrine'));
    }
}
```

- [ ] **Step 10: Verificar**

```bash
make migrate
make test
```
Expected: migraciones "no migrations to execute", transports creados, `OK (1 test, 2 assertions)`.

```bash
make stan
make cs
```
Expected: ambos sin errores (la base es pequeña todavía). Si `stan` falla por `containerXmlPath` inexistente, ejecuta antes `make console c="cache:warmup --env=test"`.

```bash
curl -i http://localhost:8080/api/anything
```
Expected: `404` (HTML de Symfony por ahora; el listener problem+json llega en la fase 2).

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -m "chore: bootstrap Symfony 8.1 project with Docker, PostgreSQL 18 and quality tools

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```
