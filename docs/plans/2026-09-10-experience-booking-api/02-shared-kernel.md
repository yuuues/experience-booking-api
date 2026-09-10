# Fase 2 — Shared kernel

Requiere Fase 1. Construye `Shared\Domain` (bloques comunes del dominio), `Shared\Application` (puertos transversales) y `Shared\Infrastructure` (adaptadores Doctrine/Messenger/Symfony).

---

### Task 2: Shared\Domain

**Files:**
- Create: `src/Shared/Domain/DomainException.php`, `NotFoundException.php`, `InvalidValue.php`, `Uuid.php`, `Money.php`, `Clock.php`, `DomainEvent.php`, `AggregateRoot.php`
- Create: `tests/Doubles/Shared/FixedClock.php`
- Test: `tests/Unit/Shared/Domain/UuidTest.php`, `MoneyTest.php`, `AggregateRootTest.php`

**Interfaces:**
- Produces: todo lo listado en "Interfaces compartidas" de `00-overview.md` bajo `Shared\Domain`.

- [ ] **Step 1: Tests**

`tests/Unit/Shared/Domain/UuidTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\InvalidValue;
use App\Shared\Domain\Uuid;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    #[Test]
    public function it_generates_a_valid_uuid(): void
    {
        $id = TestId::generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id->value);
    }

    #[Test]
    public function it_normalizes_to_lowercase(): void
    {
        $id = TestId::fromString('0192B3A4-1234-7ABC-8DEF-0123456789AB');

        self::assertSame('0192b3a4-1234-7abc-8def-0123456789ab', $id->value);
        self::assertSame('0192b3a4-1234-7abc-8def-0123456789ab', (string) $id);
    }

    #[Test]
    public function it_rejects_invalid_uuid(): void
    {
        $this->expectException(InvalidValue::class);

        TestId::fromString('not-a-uuid');
    }

    #[Test]
    public function equality_requires_same_class_and_value(): void
    {
        $a = TestId::fromString('0192b3a4-1234-7abc-8def-0123456789ab');
        $b = TestId::fromString('0192b3a4-1234-7abc-8def-0123456789ab');
        $c = OtherId::fromString('0192b3a4-1234-7abc-8def-0123456789ab');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}

final class TestId extends Uuid
{
}

final class OtherId extends Uuid
{
}
```

`tests/Unit/Shared/Domain/MoneyTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\InvalidValue;
use App\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[Test]
    public function it_multiplies_keeping_currency(): void
    {
        $price = Money::fromPrimitives(1550, 'EUR');

        $total = $price->multiply(3);

        self::assertSame(4650, $total->amount);
        self::assertSame('EUR', $total->currency);
    }

    #[Test]
    public function it_rejects_negative_amount(): void
    {
        $this->expectException(InvalidValue::class);

        Money::fromPrimitives(-1, 'EUR');
    }

    #[Test]
    public function it_rejects_malformed_currency(): void
    {
        $this->expectException(InvalidValue::class);

        Money::fromPrimitives(100, 'eur');
    }

    #[Test]
    public function it_compares_by_value(): void
    {
        self::assertTrue(Money::fromPrimitives(100, 'EUR')->equals(Money::fromPrimitives(100, 'EUR')));
        self::assertFalse(Money::fromPrimitives(100, 'EUR')->equals(Money::fromPrimitives(100, 'USD')));
    }
}
```

`tests/Unit/Shared/Domain/AggregateRootTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\DomainEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AggregateRootTest extends TestCase
{
    #[Test]
    public function it_records_and_releases_events_once(): void
    {
        $aggregate = new class extends AggregateRoot {
            public function doSomething(): void
            {
                $this->record(new SomethingHappened('agg-1'));
            }
        };

        $aggregate->doSomething();
        $aggregate->doSomething();

        $events = $aggregate->pullDomainEvents();

        self::assertCount(2, $events);
        self::assertInstanceOf(SomethingHappened::class, $events[0]);
        self::assertSame([], $aggregate->pullDomainEvents());
    }
}

final readonly class SomethingHappened implements DomainEvent
{
    public function __construct(private string $id)
    {
    }

    public function aggregateId(): string
    {
        return $this->id;
    }

    public function occurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T00:00:00+00:00');
    }

    public static function eventName(): string
    {
        return 'something.happened';
    }
}
```

- [ ] **Step 2: Ejecutar tests → fallan**

Run: `make test-unit`
Expected: errores "Class … not found".

- [ ] **Step 3: Implementación**

`src/Shared/Domain/DomainException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain;

abstract class DomainException extends \DomainException
{
    /** Stable, kebab-case identifier used as problem+json `type`. */
    abstract public function errorCode(): string;
}
```

`src/Shared/Domain/NotFoundException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain;

abstract class NotFoundException extends DomainException
{
}
```

`src/Shared/Domain/InvalidValue.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain;

final class InvalidValue extends DomainException
{
    public function errorCode(): string
    {
        return 'invalid-value';
    }
}
```

`src/Shared/Domain/Uuid.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use Stringable;
use Symfony\Component\Uid\Uuid as SymfonyUuid;

abstract class Uuid implements Stringable
{
    final private function __construct(public readonly string $value)
    {
    }

    public static function generate(): static
    {
        return new static(SymfonyUuid::v7()->toRfc4122());
    }

    public static function fromString(string $value): static
    {
        if (!SymfonyUuid::isValid($value)) {
            throw new InvalidValue(sprintf('<%s> is not a valid UUID.', $value));
        }

        return new static(strtolower($value));
    }

    public function equals(self $other): bool
    {
        return $other::class === static::class && $other->value === $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
```

`src/Shared/Domain/Money.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain;

final readonly class Money
{
    private function __construct(
        public int $amount,
        public string $currency,
    ) {
    }

    /** @param int $amount minor units (cents) */
    public static function fromPrimitives(int $amount, string $currency): self
    {
        if ($amount < 0) {
            throw new InvalidValue('Money amount cannot be negative.');
        }
        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidValue(sprintf('<%s> is not a valid ISO 4217 currency code.', $currency));
        }

        return new self($amount, $currency);
    }

    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw new InvalidValue('Money factor cannot be negative.');
        }

        return new self($this->amount * $factor, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }
}
```

`src/Shared/Domain/Clock.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use DateTimeImmutable;
use DateTimeZone;

interface Clock
{
    /** Current instant, always in UTC. */
    public function now(): DateTimeImmutable;

    /** Platform time zone used for calendar-day rules. */
    public function timeZone(): DateTimeZone;
}
```

`src/Shared/Domain/DomainEvent.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use DateTimeImmutable;

interface DomainEvent
{
    public function aggregateId(): string;

    public function occurredOn(): DateTimeImmutable;

    public static function eventName(): string;
}
```

`src/Shared/Domain/AggregateRoot.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain;

abstract class AggregateRoot
{
    /** @var list<DomainEvent> */
    private array $domainEvents = [];

    /** @return list<DomainEvent> */
    final public function pullDomainEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }

    final protected function record(DomainEvent $event): void
    {
        $this->domainEvents[] = $event;
    }
}
```

`tests/Doubles/Shared/FixedClock.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Doubles\Shared;

use App\Shared\Domain\Clock;
use DateTimeImmutable;
use DateTimeZone;

final class FixedClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $now = '2026-10-01T10:00:00+00:00', private readonly string $timeZone = 'Europe/Madrid')
    {
        $this->now = (new DateTimeImmutable($now))->setTimezone(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function timeZone(): DateTimeZone
    {
        return new DateTimeZone($this->timeZone);
    }

    public function travelTo(string $now): void
    {
        $this->now = (new DateTimeImmutable($now))->setTimezone(new DateTimeZone('UTC'));
    }
}
```

- [ ] **Step 4: Ejecutar tests → pasan**

Run: `make test-unit`
Expected: `OK (9 tests, …)`.

- [ ] **Step 5: Commit**

```bash
git add src/Shared/Domain tests/Unit/Shared tests/Doubles/Shared
git commit -m "feat(shared): domain building blocks (Uuid, Money, Clock, AggregateRoot, exceptions)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: Shared\Application + Shared\Infrastructure

**Files:**
- Create: `src/Shared/Application/TransactionalRunner.php`, `DomainEventPublisher.php`
- Create: `src/Shared/Infrastructure/SystemClock.php`
- Create: `src/Shared/Infrastructure/Doctrine/DoctrineTransactionalRunner.php`
- Create: `src/Shared/Infrastructure/Doctrine/Type/UuidType.php`, `StringValueObjectType.php`, `IntValueObjectType.php`
- Create: `src/Shared/Infrastructure/Messenger/MessengerDomainEventPublisher.php`
- Create: `src/Shared/Infrastructure/Symfony/ProblemJsonExceptionListener.php`
- Create: `config/doctrine/Shared/Money.orm.xml`
- Create: `tests/Doubles/Shared/InMemoryTransactionalRunner.php`, `InMemoryDomainEventPublisher.php`
- Modify: `config/services.yaml` (alias de puertos)
- Test: `tests/Unit/Shared/Infrastructure/ProblemJsonExceptionListenerTest.php`, `tests/Functional/ProblemJsonTest.php`

**Interfaces:**
- Consumes: `Shared\Domain\*` (Task 2).
- Produces: `TransactionalRunner::run(callable): mixed`; `DomainEventPublisher::publish(DomainEvent ...$events): void`; tipos DBAL base `UuidType`, `StringValueObjectType`, `IntValueObjectType` (subclases definen `protected static function valueObjectClass(): string`); listener que convierte excepciones en problem+json; embeddable `Money` mapeado con columnas `amount` y `currency` (prefijo definido por el entity que lo embebe).

- [ ] **Step 1: Test unitario del listener**

`tests/Unit/Shared/Infrastructure/ProblemJsonExceptionListenerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Domain\DomainException;
use App\Shared\Domain\InvalidValue;
use App\Shared\Domain\NotFoundException;
use App\Shared\Infrastructure\Symfony\ProblemJsonExceptionListener;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

final class ProblemJsonExceptionListenerTest extends TestCase
{
    /** @return iterable<string, array{Throwable, int, string}> */
    public static function exceptions(): iterable
    {
        yield 'not found' => [new class('Experience <x> not found.') extends NotFoundException {
            public function errorCode(): string
            {
                return 'experience-not-found';
            }
        }, 404, 'experience-not-found'];

        yield 'invalid value' => [new InvalidValue('bad'), 400, 'invalid-value'];

        yield 'business rule' => [new class('No seats.') extends DomainException {
            public function errorCode(): string
            {
                return 'not-enough-seats-available';
            }
        }, 422, 'not-enough-seats-available'];

        yield 'lock timeout' => [
            new LockWaitTimeoutException(new class('timeout') extends \Exception implements DriverException {
                public function getSQLState(): ?string
                {
                    return '55P03';
                }
            }, null),
            503,
            'lock-timeout',
        ];
    }

    #[Test]
    #[DataProvider('exceptions')]
    public function it_maps_exceptions_to_problem_json(Throwable $exception, int $status, string $type): void
    {
        $event = $this->dispatch($exception);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('/problems/'.$type, $body['type']);
        self::assertSame($status, $body['status']);
        self::assertArrayHasKey('detail', $body);
    }

    #[Test]
    public function it_lists_validation_errors(): void
    {
        $violations = new ConstraintViolationList([
            new ConstraintViolation('This value should not be blank.', null, [], null, 'title', ''),
        ]);
        $exception = new UnprocessableEntityHttpException('Validation failed', new ValidationFailedException(null, $violations));

        $event = $this->dispatch($exception);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('/problems/validation-failed', $body['type']);
        self::assertSame([['field' => 'title', 'message' => 'This value should not be blank.']], $body['errors']);
    }

    #[Test]
    public function it_ignores_non_api_paths(): void
    {
        $event = $this->dispatch(new InvalidValue('bad'), '/not-api');

        self::assertNull($event->getResponse());
    }

    private function dispatch(Throwable $exception, string $path = '/api/x'): ExceptionEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new ExceptionEvent($kernel, Request::create($path), HttpKernelInterface::MAIN_REQUEST, $exception);

        (new ProblemJsonExceptionListener(debug: false))($event);

        return $event;
    }
}
```

- [ ] **Step 2: Ejecutar → falla**

Run: `make test-unit`
Expected: `ProblemJsonExceptionListener` not found.

- [ ] **Step 3: Implementación**

`src/Shared/Application/TransactionalRunner.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\Application;

interface TransactionalRunner
{
    /**
     * Runs the operation atomically. Whatever it returns is returned; whatever it throws
     * rolls the transaction back and is rethrown.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function run(callable $operation): mixed;
}
```

`src/Shared/Application/DomainEventPublisher.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\DomainEvent;

interface DomainEventPublisher
{
    public function publish(DomainEvent ...$events): void;
}
```

`src/Shared/Infrastructure/SystemClock.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Domain\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class SystemClock implements Clock
{
    public function __construct(
        #[Autowire(param: 'app.timezone')]
        private string $timeZone,
    ) {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function timeZone(): DateTimeZone
    {
        return new DateTimeZone($this->timeZone);
    }
}
```

`src/Shared/Infrastructure/Doctrine/DoctrineTransactionalRunner.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Application\TransactionalRunner;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineTransactionalRunner implements TransactionalRunner
{
    private const string LOCK_TIMEOUT = '2000ms';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function run(callable $operation): mixed
    {
        return $this->entityManager->wrapInTransaction(function () use ($operation): mixed {
            // Fail fast instead of piling up requests on a hot session row; mapped to 503 by the exception listener.
            $this->entityManager->getConnection()->executeStatement(sprintf("SET LOCAL lock_timeout = '%s'", self::LOCK_TIMEOUT));

            return $operation();
        });
    }
}
```

`src/Shared/Infrastructure/Doctrine/Type/UuidType.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Type;

use App\Shared\Domain\Uuid;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/** Maps a Uuid value object to a native UUID column. Subclasses name the VO class. */
abstract class UuidType extends Type
{
    /** @return class-string<Uuid> */
    abstract protected static function valueObjectClass(): string;

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getGuidTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Uuid
    {
        if (null === $value) {
            return null;
        }

        return static::valueObjectClass()::fromString((string) $value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return match (true) {
            null === $value => null,
            $value instanceof Uuid => $value->value,
            default => (string) $value,
        };
    }
}
```

`src/Shared/Infrastructure/Doctrine/Type/StringValueObjectType.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Maps a `final readonly` value object exposing `public string $value` and `static fromString(string)`.
 *
 * @template T of object
 */
abstract class StringValueObjectType extends Type
{
    /** @return class-string<T> */
    abstract protected static function valueObjectClass(): string;

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL($column);
    }

    /** @return T|null */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?object
    {
        if (null === $value) {
            return null;
        }

        /** @var T $vo */
        $vo = static::valueObjectClass()::fromString((string) $value);

        return $vo;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return match (true) {
            null === $value => null,
            \is_object($value) && property_exists($value, 'value') => (string) $value->value,
            default => (string) $value,
        };
    }
}
```

`src/Shared/Infrastructure/Doctrine/Type/IntValueObjectType.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Type;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Maps a `final readonly` value object exposing `public int $value` and `static fromInt(int)`.
 *
 * @template T of object
 */
abstract class IntValueObjectType extends Type
{
    /** @return class-string<T> */
    abstract protected static function valueObjectClass(): string;

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getIntegerTypeDeclarationSQL($column);
    }

    /** @return T|null */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?object
    {
        if (null === $value) {
            return null;
        }

        /** @var T $vo */
        $vo = static::valueObjectClass()::fromInt((int) $value);

        return $vo;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?int
    {
        return match (true) {
            null === $value => null,
            \is_object($value) && property_exists($value, 'value') => (int) $value->value,
            default => (int) $value,
        };
    }

    public function getBindingType(): ParameterType
    {
        return ParameterType::INTEGER;
    }
}
```

`src/Shared/Infrastructure/Messenger/MessengerDomainEventPublisher.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Application\DomainEventPublisher;
use App\Shared\Domain\DomainEvent;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerDomainEventPublisher implements DomainEventPublisher
{
    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->messageBus->dispatch($event);
        }
    }
}
```

`src/Shared/Infrastructure/Symfony/ProblemJsonExceptionListener.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Symfony;

use App\Shared\Domain\DomainException;
use App\Shared\Domain\InvalidValue;
use App\Shared\Domain\NotFoundException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

/** Turns any exception thrown under /api into an RFC 7807 problem+json response. */
#[AsEventListener(event: 'kernel.exception', priority: 10)]
final readonly class ProblemJsonExceptionListener
{
    /** @var array<class-string<DomainException>, int> checked in order; first `instanceof` wins */
    private const array DOMAIN_STATUS = [
        NotFoundException::class => Response::HTTP_NOT_FOUND,
        InvalidValue::class => Response::HTTP_BAD_REQUEST,
    ];

    /** @var array<string, int> exact-class overrides (conflicts); classes live in later modules, hence plain strings */
    public const array CONFLICTS = [
        'App\Session\Domain\Exception\SessionAlreadyScheduledForDay' => Response::HTTP_CONFLICT,
        'App\Experience\Domain\Exception\ExperienceHasBookings' => Response::HTTP_CONFLICT,
    ];

    public function __construct(
        #[Autowire(param: 'kernel.debug')]
        private bool $debug,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $exception = $event->getThrowable();
        [$status, $type, $detail, $extra] = $this->describe($exception);

        $body = ['type' => '/problems/'.$type, 'title' => $this->title($type), 'status' => $status, 'detail' => $detail] + $extra;
        $headers = ['Content-Type' => 'application/problem+json'];
        if (Response::HTTP_SERVICE_UNAVAILABLE === $status) {
            $headers['Retry-After'] = '1';
        }

        $event->setResponse(new JsonResponse($body, $status, $headers));
    }

    /** @return array{int, string, string, array<string, mixed>} */
    private function describe(Throwable $exception): array
    {
        if ($exception instanceof DomainException) {
            return [$this->domainStatus($exception), $exception->errorCode(), $exception->getMessage(), []];
        }

        if ($exception instanceof LockWaitTimeoutException) {
            return [Response::HTTP_SERVICE_UNAVAILABLE, 'lock-timeout', 'The resource is busy, please retry.', []];
        }

        if ($exception instanceof HttpExceptionInterface) {
            $previous = $exception->getPrevious();
            if ($previous instanceof ValidationFailedException) {
                $errors = [];
                foreach ($previous->getViolations() as $violation) {
                    $errors[] = ['field' => $violation->getPropertyPath(), 'message' => (string) $violation->getMessage()];
                }

                return [$exception->getStatusCode(), 'validation-failed', 'The request payload is invalid.', ['errors' => $errors]];
            }

            return [$exception->getStatusCode(), 'http-'.$exception->getStatusCode(), $exception->getMessage() ?: 'Request could not be processed.', []];
        }

        $detail = $this->debug ? $exception->getMessage() : 'An unexpected error occurred.';

        return [Response::HTTP_INTERNAL_SERVER_ERROR, 'internal-error', $detail, []];
    }

    private function domainStatus(DomainException $exception): int
    {
        if (isset(self::CONFLICTS[$exception::class])) {
            return self::CONFLICTS[$exception::class];
        }
        foreach (self::DOMAIN_STATUS as $class => $status) {
            if ($exception instanceof $class) {
                return $status;
            }
        }

        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }

    private function title(string $type): string
    {
        return ucfirst(str_replace('-', ' ', $type));
    }
}
```

`config/doctrine/Shared/Money.orm.xml`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping
                                      https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd">
  <embeddable name="App\Shared\Domain\Money">
    <field name="amount" type="bigint" column="amount"/>
    <field name="currency" type="string" column="currency" length="3"/>
  </embeddable>
</doctrine-mapping>
```
Nota: `bigint` en DBAL 4 hidrata como `int` en plataformas 64-bit; `Money::$amount` es `int`.

`tests/Doubles/Shared/InMemoryTransactionalRunner.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Doubles\Shared;

use App\Shared\Application\TransactionalRunner;

final class InMemoryTransactionalRunner implements TransactionalRunner
{
    public int $transactions = 0;

    public function run(callable $operation): mixed
    {
        ++$this->transactions;

        return $operation();
    }
}
```

`tests/Doubles/Shared/InMemoryDomainEventPublisher.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Doubles\Shared;

use App\Shared\Application\DomainEventPublisher;
use App\Shared\Domain\DomainEvent;

final class InMemoryDomainEventPublisher implements DomainEventPublisher
{
    /** @var list<DomainEvent> */
    private array $published = [];

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->published[] = $event;
        }
    }

    /** @return list<DomainEvent> */
    public function published(): array
    {
        return $this->published;
    }

    /**
     * @template T of DomainEvent
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function publishedOf(string $class): array
    {
        return array_values(array_filter($this->published, static fn (DomainEvent $e): bool => $e instanceof $class));
    }
}
```

Añade al final de `config/services.yaml` (los alias de puertos; se irán ampliando en cada fase):
```yaml
  App\Shared\Domain\Clock: '@App\Shared\Infrastructure\SystemClock'
  App\Shared\Application\TransactionalRunner: '@App\Shared\Infrastructure\Doctrine\DoctrineTransactionalRunner'
  App\Shared\Application\DomainEventPublisher: '@App\Shared\Infrastructure\Messenger\MessengerDomainEventPublisher'
```

- [ ] **Step 4: Test funcional del listener (404 de ruta bajo /api)**

`tests/Functional/ProblemJsonTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProblemJsonTest extends WebTestCase
{
    #[Test]
    public function unknown_api_route_is_problem_json(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/does-not-exist');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('/problems/http-404', $body['type']);
    }
}
```

- [ ] **Step 5: Ejecutar → pasan**

Run: `make test`
Expected: unit + functional en verde. `make stan` y `make cs` limpios (arregla con `make cs-fix` si hace falta).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(shared): transactional runner, event publisher, DBAL VO types and problem+json listener

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```
