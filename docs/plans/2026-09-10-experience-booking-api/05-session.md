# Fase 5 — Módulo Session (aplicación, persistencia, HTTP)

Requiere Fases 3 y 4. Entrega `POST /api/experiences/{id}/sessions` y `GET /api/sessions/{id}`, con la regla "una sesión por experiencia y día" garantizada por el caso de uso y por índice único.

---

### Task 10: Session — casos de uso Schedule y Find

**Files:**
- Create: `src/Session/Application/MoneyResponse.php`, `SessionResponse.php`
- Create: `src/Session/Application/Schedule/ScheduleSessionCommand.php`, `ScheduleSessionHandler.php`
- Create: `src/Session/Application/Find/FindSessionQuery.php`, `FindSessionHandler.php`
- Test: `tests/Unit/Session/Application/ScheduleSessionHandlerTest.php`, `FindSessionHandlerTest.php`

**Interfaces:**
- Consumes: Task 9, `ExperienceRepository`, `Clock`, `DomainEventPublisher`.
- Produces: `ScheduleSessionCommand(string $id, string $experienceId, string $startsAt, int $capacity, int $priceAmount, string $priceCurrency)`; `ScheduleSessionHandler::__invoke(...): SessionResponse`; `FindSessionQuery(string $id)`; `FindSessionHandler`; `SessionResponse(id, experienceId, startsAt, capacity, bookedSeats, availableSeats, MoneyResponse $price)` con `static fromSession(Session)`; `MoneyResponse(int $amount, string $currency)` con `static fromMoney(Money)`.

- [ ] **Step 1: Tests**

`tests/Unit/Session/Application/ScheduleSessionHandlerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Application;

use App\Experience\Domain\Exception\ExperienceNotFound;
use App\Session\Application\Schedule\ScheduleSessionCommand;
use App\Session\Application\Schedule\ScheduleSessionHandler;
use App\Session\Domain\Event\SessionScheduled;
use App\Session\Domain\Exception\SessionAlreadyScheduledForDay;
use App\Session\Domain\Exception\SessionInThePast;
use App\Tests\Doubles\Experience\InMemoryExperienceRepository;
use App\Tests\Doubles\Session\InMemorySessionRepository;
use App\Tests\Doubles\Shared\FixedClock;
use App\Tests\Doubles\Shared\InMemoryDomainEventPublisher;
use App\Tests\Unit\Experience\Domain\ExperienceTest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScheduleSessionHandlerTest extends TestCase
{
    private InMemoryExperienceRepository $experiences;
    private InMemorySessionRepository $sessions;
    private InMemoryDomainEventPublisher $events;
    private ScheduleSessionHandler $handler;
    private string $experienceId;

    protected function setUp(): void
    {
        $this->experiences = new InMemoryExperienceRepository();
        $this->sessions = new InMemorySessionRepository();
        $this->events = new InMemoryDomainEventPublisher();
        $this->handler = new ScheduleSessionHandler($this->experiences, $this->sessions, new FixedClock('2026-10-01T10:00:00+00:00'), $this->events);

        $experience = ExperienceTest::anExperience();
        $this->experiences->save($experience);
        $this->experienceId = $experience->id()->value;
    }

    #[Test]
    public function it_schedules_a_session(): void
    {
        $response = ($this->handler)($this->command(startsAt: '2026-10-05T12:00:00+02:00'));

        self::assertSame($this->experienceId, $response->experienceId);
        self::assertSame('2026-10-05T10:00:00+00:00', $response->startsAt);
        self::assertSame(10, $response->availableSeats);
        self::assertSame(1500, $response->price->amount);
        self::assertCount(1, $this->events->publishedOf(SessionScheduled::class));
    }

    #[Test]
    public function it_rejects_unknown_experience(): void
    {
        $this->expectException(ExperienceNotFound::class);

        ($this->handler)($this->command(experienceId: '0192b3a4-1234-7abc-8def-0123456789ff'));
    }

    #[Test]
    public function it_rejects_two_sessions_same_day_in_platform_time_zone(): void
    {
        ($this->handler)($this->command(startsAt: '2026-10-05T23:30:00+00:00')); // 2026-10-06 in Madrid

        $this->expectException(SessionAlreadyScheduledForDay::class);

        ($this->handler)($this->command(startsAt: '2026-10-06T08:00:00+02:00')); // also 2026-10-06 in Madrid
    }

    #[Test]
    public function it_rejects_past_dates(): void
    {
        $this->expectException(SessionInThePast::class);

        ($this->handler)($this->command(startsAt: '2026-09-30T10:00:00+00:00'));
    }

    private function command(?string $experienceId = null, string $startsAt = '2026-10-05T10:00:00+00:00'): ScheduleSessionCommand
    {
        return new ScheduleSessionCommand(
            id: '0192b3a4-1234-7abc-8def-0123456789aa',
            experienceId: $experienceId ?? $this->experienceId,
            startsAt: $startsAt,
            capacity: 10,
            priceAmount: 1500,
            priceCurrency: 'EUR',
        );
    }
}
```

`tests/Unit/Session/Application/FindSessionHandlerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Application;

use App\Session\Application\Find\FindSessionHandler;
use App\Session\Application\Find\FindSessionQuery;
use App\Session\Domain\Exception\SessionNotFound;
use App\Tests\Doubles\Session\InMemorySessionRepository;
use App\Tests\Doubles\Shared\FixedClock;
use App\Tests\Unit\Session\Domain\SessionTest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FindSessionHandlerTest extends TestCase
{
    #[Test]
    public function it_returns_the_session(): void
    {
        $sessions = new InMemorySessionRepository();
        $session = SessionTest::aSession(new FixedClock());
        $sessions->save($session);

        $response = (new FindSessionHandler($sessions))(new FindSessionQuery($session->id()->value));

        self::assertSame($session->id()->value, $response->id);
        self::assertSame(10, $response->capacity);
    }

    #[Test]
    public function it_throws_when_missing(): void
    {
        $this->expectException(SessionNotFound::class);

        (new FindSessionHandler(new InMemorySessionRepository()))(new FindSessionQuery('0192b3a4-1234-7abc-8def-0123456789aa'));
    }
}
```

- [ ] **Step 2: Ejecutar → falla** (`make test-unit`).

- [ ] **Step 3: Implementación**

`src/Session/Application/MoneyResponse.php`:
```php
<?php

declare(strict_types=1);

namespace App\Session\Application;

use App\Shared\Domain\Money;

final readonly class MoneyResponse
{
    public function __construct(public int $amount, public string $currency)
    {
    }

    public static function fromMoney(Money $money): self
    {
        return new self($money->amount, $money->currency);
    }
}
```

`src/Session/Application/SessionResponse.php`:
```php
<?php

declare(strict_types=1);

namespace App\Session\Application;

use App\Session\Domain\Session;

final readonly class SessionResponse
{
    public function __construct(
        public string $id,
        public string $experienceId,
        public string $startsAt,
        public int $capacity,
        public int $bookedSeats,
        public int $availableSeats,
        public MoneyResponse $price,
    ) {
    }

    public static function fromSession(Session $session): self
    {
        return new self(
            $session->id()->value,
            $session->experienceId()->value,
            $session->startsAt()->toAtom(),
            $session->capacity()->value,
            $session->bookedSeats(),
            $session->availableSeats(),
            MoneyResponse::fromMoney($session->price()),
        );
    }
}
```

`src/Session/Application/Schedule/ScheduleSessionCommand.php`:
```php
<?php

declare(strict_types=1);

namespace App\Session\Application\Schedule;

final readonly class ScheduleSessionCommand
{
    public function __construct(
        public string $id,
        public string $experienceId,
        public string $startsAt,
        public int $capacity,
        public int $priceAmount,
        public string $priceCurrency,
    ) {
    }
}
```

`src/Session/Application/Schedule/ScheduleSessionHandler.php`:
```php
<?php

declare(strict_types=1);

namespace App\Session\Application\Schedule;

use App\Experience\Domain\Exception\ExperienceNotFound;
use App\Experience\Domain\ExperienceId;
use App\Experience\Domain\ExperienceRepository;
use App\Session\Application\SessionResponse;
use App\Session\Domain\Capacity;
use App\Session\Domain\Exception\SessionAlreadyScheduledForDay;
use App\Session\Domain\Session;
use App\Session\Domain\SessionId;
use App\Session\Domain\SessionRepository;
use App\Session\Domain\StartsAt;
use App\Shared\Application\DomainEventPublisher;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Money;

final readonly class ScheduleSessionHandler
{
    public function __construct(
        private ExperienceRepository $experiences,
        private SessionRepository $sessions,
        private Clock $clock,
        private DomainEventPublisher $events,
    ) {
    }

    public function __invoke(ScheduleSessionCommand $command): SessionResponse
    {
        $experienceId = ExperienceId::fromString($command->experienceId);
        $this->experiences->find($experienceId) ?? throw ExperienceNotFound::withId($experienceId);

        $startsAt = StartsAt::fromString($command->startsAt);
        $day = $startsAt->dayIn($this->clock->timeZone());
        // Cross-aggregate invariant: checked here, guaranteed by the unique index (experience_id, day).
        if ($this->sessions->existsForExperienceOn($experienceId, $day)) {
            throw SessionAlreadyScheduledForDay::on($experienceId, $day);
        }

        $session = Session::schedule(
            SessionId::fromString($command->id),
            $experienceId,
            $startsAt,
            Capacity::fromInt($command->capacity),
            Money::fromPrimitives($command->priceAmount, $command->priceCurrency),
            $this->clock,
        );

        $this->sessions->save($session);
        $this->events->publish(...$session->pullDomainEvents());

        return SessionResponse::fromSession($session);
    }
}
```

`src/Session/Application/Find/FindSessionQuery.php`:
```php
<?php

declare(strict_types=1);

namespace App\Session\Application\Find;

final readonly class FindSessionQuery
{
    public function __construct(public string $id)
    {
    }
}
```

`src/Session/Application/Find/FindSessionHandler.php`:
```php
<?php

declare(strict_types=1);

namespace App\Session\Application\Find;

use App\Session\Application\SessionResponse;
use App\Session\Domain\Exception\SessionNotFound;
use App\Session\Domain\SessionId;
use App\Session\Domain\SessionRepository;

final readonly class FindSessionHandler
{
    public function __construct(private SessionRepository $sessions)
    {
    }

    public function __invoke(FindSessionQuery $query): SessionResponse
    {
        $id = SessionId::fromString($query->id);
        $session = $this->sessions->find($id) ?? throw SessionNotFound::withId($id);

        return SessionResponse::fromSession($session);
    }
}
```

- [ ] **Step 4: Ejecutar → pasan** (`make test-unit`).

- [ ] **Step 5: Commit**

```bash
git add src/Session/Application tests/Unit/Session/Application
git commit -m "feat(session): schedule and find use cases with one-session-per-day rule

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 11: Session — persistencia Doctrine con `FOR UPDATE` e índice único

**Files:**
- Create: `src/Session/Infrastructure/Persistence/Doctrine/Type/SessionIdType.php`, `StartsAtType.php`, `SessionDayType.php`, `CapacityType.php`
- Create: `src/Session/Infrastructure/Persistence/Doctrine/DoctrineSessionRepository.php`
- Create: `config/doctrine/Session/Session.orm.xml`
- Create: `migrations/Version20260910120100.php`
- Modify: `config/packages/doctrine.yaml`, `config/services.yaml`
- Test: `tests/Integration/Session/DoctrineSessionRepositoryTest.php`

**Interfaces:**
- Consumes: Task 9, Task 3 (types base), Task 6 (tabla `experiences`).
- Produces: `DoctrineSessionRepository implements SessionRepository`; tabla `sessions` con `UNIQUE (experience_id, day)` y `CHECK`s; `save()` traduce la violación del índice único a `SessionAlreadyScheduledForDay`.

- [ ] **Step 1: Test de integración**

`tests/Integration/Session/DoctrineSessionRepositoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Session;

use App\Experience\Domain\ExperienceRepository;
use App\Session\Domain\Capacity;
use App\Session\Domain\Exception\SessionAlreadyScheduledForDay;
use App\Session\Domain\Session;
use App\Session\Domain\SessionDay;
use App\Session\Domain\SessionId;
use App\Session\Domain\SessionRepository;
use App\Session\Domain\StartsAt;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Money;
use App\Tests\Unit\Experience\Domain\ExperienceTest;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineSessionRepositoryTest extends KernelTestCase
{
    private SessionRepository $sessions;
    private EntityManagerInterface $entityManager;
    private string $experienceId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->sessions = self::getContainer()->get(SessionRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $experience = ExperienceTest::anExperience();
        self::getContainer()->get(ExperienceRepository::class)->save($experience);
        $this->experienceId = $experience->id()->value;
    }

    #[Test]
    public function it_persists_and_rehydrates_value_objects(): void
    {
        $session = $this->aSession('+3 days 10:00');

        $this->sessions->save($session);
        $this->entityManager->clear();

        $found = $this->sessions->find($session->id());
        self::assertNotNull($found);
        self::assertSame($session->startsAt()->toAtom(), $found->startsAt()->toAtom());
        self::assertSame($session->day()->value, $found->day()->value);
        self::assertSame(10, $found->capacity()->value);
        self::assertTrue($found->price()->equals(Money::fromPrimitives(1500, 'EUR')));
        self::assertSame(0, $found->bookedSeats());
    }

    #[Test]
    public function it_checks_existence_by_experience_and_day(): void
    {
        $session = $this->aSession('+3 days 10:00');
        $this->sessions->save($session);

        self::assertTrue($this->sessions->existsForExperienceOn($session->experienceId(), $session->day()));
        self::assertFalse($this->sessions->existsForExperienceOn($session->experienceId(), SessionDay::fromString('2030-01-01')));
    }

    #[Test]
    public function unique_index_is_translated_to_domain_exception(): void
    {
        $this->sessions->save($this->aSession('+3 days 10:00'));

        $this->expectException(SessionAlreadyScheduledForDay::class);

        $this->sessions->save($this->aSession('+3 days 18:00'));
    }

    #[Test]
    public function find_for_update_returns_the_session_inside_a_transaction(): void
    {
        $session = $this->aSession('+3 days 10:00');
        $this->sessions->save($session);
        $this->entityManager->clear();

        $found = $this->entityManager->wrapInTransaction(fn (): ?Session => $this->sessions->findForUpdate($session->id()));

        self::assertNotNull($found);
        self::assertTrue($found->id()->equals($session->id()));
    }

    private function aSession(string $when): Session
    {
        $clock = self::getContainer()->get(Clock::class);

        return Session::schedule(
            SessionId::generate(),
            \App\Experience\Domain\ExperienceId::fromString($this->experienceId),
            StartsAt::fromDateTime(new \DateTimeImmutable($when, new \DateTimeZone('UTC'))),
            Capacity::fromInt(10),
            Money::fromPrimitives(1500, 'EUR'),
            $clock,
        );
    }
}
```

- [ ] **Step 2: Ejecutar → falla** (`make test`).

- [ ] **Step 3: Implementación**

Types (`src/Session/Infrastructure/Persistence/Doctrine/Type/`):

`SessionIdType.php`: como `ExperienceIdType` con `SessionId`.

`CapacityType.php`:
```php
<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Persistence\Doctrine\Type;

use App\Session\Domain\Capacity;
use App\Shared\Infrastructure\Doctrine\Type\IntValueObjectType;

/** @extends IntValueObjectType<Capacity> */
final class CapacityType extends IntValueObjectType
{
    protected static function valueObjectClass(): string
    {
        return Capacity::class;
    }
}
```

`SessionDayType.php`:
```php
<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Persistence\Doctrine\Type;

use App\Session\Domain\SessionDay;
use App\Shared\Infrastructure\Doctrine\Type\StringValueObjectType;
use Doctrine\DBAL\Platforms\AbstractPlatform;

/** @extends StringValueObjectType<SessionDay> */
final class SessionDayType extends StringValueObjectType
{
    protected static function valueObjectClass(): string
    {
        return SessionDay::class;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getDateTypeDeclarationSQL($column);
    }
}
```

`StartsAtType.php`:
```php
<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Persistence\Doctrine\Type;

use App\Session\Domain\StartsAt;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;
use Doctrine\DBAL\Types\Type;

/**
 * Wraps (not extends) DateTimeTzImmutableType: its convertToPHPValue() return type is
 * ?DateTimeImmutable, so a subclass could not narrow it to ?StartsAt.
 */
final class StartsAtType extends Type
{
    private ?DateTimeTzImmutableType $inner = null;

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getDateTimeTzTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?StartsAt
    {
        $dateTime = $this->inner()->convertToPHPValue($value, $platform);

        return null === $dateTime ? null : StartsAt::fromDateTime($dateTime);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return $this->inner()->convertToDatabaseValue($value instanceof StartsAt ? $value->value : $value, $platform);
    }

    private function inner(): DateTimeTzImmutableType
    {
        return $this->inner ??= new DateTimeTzImmutableType();
    }
}
```

`config/doctrine/Session/Session.orm.xml`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping
                                      https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd">
  <entity name="App\Session\Domain\Session" table="sessions">
    <id name="id" type="session_id" column="id"/>
    <field name="experienceId" type="experience_id" column="experience_id"/>
    <field name="startsAt" type="session_starts_at" column="starts_at"/>
    <field name="day" type="session_day" column="day"/>
    <field name="capacity" type="session_capacity" column="capacity"/>
    <field name="bookedSeats" type="integer" column="booked_seats"/>
    <embedded name="price" class="App\Shared\Domain\Money" column-prefix="price_"/>
  </entity>
</doctrine-mapping>
```

`config/packages/doctrine.yaml` → añade bajo `types`:
```yaml
      session_id: App\Session\Infrastructure\Persistence\Doctrine\Type\SessionIdType
      session_starts_at: App\Session\Infrastructure\Persistence\Doctrine\Type\StartsAtType
      session_day: App\Session\Infrastructure\Persistence\Doctrine\Type\SessionDayType
      session_capacity: App\Session\Infrastructure\Persistence\Doctrine\Type\CapacityType
```

`src/Session/Infrastructure/Persistence/Doctrine/DoctrineSessionRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Persistence\Doctrine;

use App\Experience\Domain\ExperienceId;
use App\Session\Domain\Exception\SessionAlreadyScheduledForDay;
use App\Session\Domain\Session;
use App\Session\Domain\SessionDay;
use App\Session\Domain\SessionId;
use App\Session\Domain\SessionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineSessionRepository implements SessionRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Session $session): void
    {
        try {
            $this->entityManager->persist($session);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // uniq_sessions_experience_day: the use case pre-checks, the index closes the race.
            throw SessionAlreadyScheduledForDay::on($session->experienceId(), $session->day());
        }
    }

    public function find(SessionId $id): ?Session
    {
        return $this->entityManager->find(Session::class, $id->value);
    }

    public function findForUpdate(SessionId $id): ?Session
    {
        return $this->entityManager->find(Session::class, $id->value, LockMode::PESSIMISTIC_WRITE);
    }

    public function existsForExperienceOn(ExperienceId $experienceId, SessionDay $day): bool
    {
        $count = $this->entityManager->createQuery(
            'SELECT COUNT(s.id) FROM App\Session\Domain\Session s WHERE s.experienceId = :experienceId AND s.day = :day'
        )
            ->setParameter('experienceId', $experienceId->value)
            ->setParameter('day', $day->value)
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
```

`migrations/Version20260910120100.php`:
```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910120100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sessions table with one-session-per-day unique index and capacity checks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sessions (
            id UUID NOT NULL,
            experience_id UUID NOT NULL,
            starts_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            day DATE NOT NULL,
            capacity INT NOT NULL,
            booked_seats INT NOT NULL DEFAULT 0,
            price_amount BIGINT NOT NULL,
            price_currency CHAR(3) NOT NULL,
            PRIMARY KEY (id),
            CONSTRAINT fk_sessions_experience FOREIGN KEY (experience_id) REFERENCES experiences (id),
            CONSTRAINT chk_sessions_capacity CHECK (capacity > 0),
            CONSTRAINT chk_sessions_booked_seats CHECK (booked_seats >= 0 AND booked_seats <= capacity),
            CONSTRAINT chk_sessions_price CHECK (price_amount >= 0)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_sessions_experience_day ON sessions (experience_id, day)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sessions');
    }
}
```

`config/services.yaml` → añade:
```yaml
  App\Session\Domain\SessionRepository: '@App\Session\Infrastructure\Persistence\Doctrine\DoctrineSessionRepository'
```

- [ ] **Step 4: Migrar y ejecutar**

```bash
make migrate
make test
```
Expected: verde. Si el test del índice único falla con "EntityManager is closed" en tests posteriores, comprueba que cada test hace `bootKernel()` en `setUp` (KernelTestCase apaga el kernel en `tearDown`, así que cada test tiene EM nuevo).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(session): Doctrine repository with pessimistic lock and unique day index

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 12: Session — HTTP

**Files:**
- Create: `src/Session/Infrastructure/Http/PriceRequest.php`, `ScheduleSessionRequest.php`, `ScheduleSessionController.php`, `FindSessionController.php`
- Test: `tests/Functional/Session/SessionApiTest.php`

**Interfaces:**
- Consumes: Task 10.
- Produces: `POST /api/experiences/{id}/sessions` → 201 + `Location`; `GET /api/sessions/{id}` (ruta `api_sessions_find`).

- [ ] **Step 1: Test funcional**

`tests/Functional/Session/SessionApiTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Session;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SessionApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $experienceId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->jsonRequest('POST', '/api/experiences', [
            'title' => 'Kayak', 'description' => 'At dawn', 'providerId' => '0192b3a4-1234-7abc-8def-0123456789ac',
        ]);
        $this->experienceId = $this->json()['id'];
    }

    #[Test]
    public function it_schedules_a_session(): void
    {
        $startsAt = (new \DateTimeImmutable('+3 days'))->setTime(10, 0)->format(DATE_ATOM);

        $this->client->jsonRequest('POST', "/api/experiences/{$this->experienceId}/sessions", [
            'startsAt' => $startsAt, 'capacity' => 12, 'price' => ['amount' => 2500, 'currency' => 'EUR'],
        ]);

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertSame($this->experienceId, $body['experienceId']);
        self::assertSame(12, $body['availableSeats']);
        self::assertSame(['amount' => 2500, 'currency' => 'EUR'], $body['price']);
        self::assertResponseHeaderSame('Location', '/api/sessions/'.$body['id']);

        $this->client->request('GET', '/api/sessions/'.$body['id']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($body, $this->json());
    }

    #[Test]
    public function it_rejects_second_session_same_day(): void
    {
        $day = (new \DateTimeImmutable('+3 days'))->setTime(10, 0);
        $payload = fn (\DateTimeImmutable $at): array => ['startsAt' => $at->format(DATE_ATOM), 'capacity' => 5, 'price' => ['amount' => 100, 'currency' => 'EUR']];

        $this->client->jsonRequest('POST', "/api/experiences/{$this->experienceId}/sessions", $payload($day));
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('POST', "/api/experiences/{$this->experienceId}/sessions", $payload($day->setTime(18, 0)));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/session-already-scheduled-for-day', $this->json()['type']);
    }

    #[Test]
    public function it_rejects_past_sessions(): void
    {
        $this->client->jsonRequest('POST', "/api/experiences/{$this->experienceId}/sessions", [
            'startsAt' => '2020-01-01T10:00:00+00:00', 'capacity' => 5, 'price' => ['amount' => 100, 'currency' => 'EUR'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/session-in-the-past', $this->json()['type']);
    }

    #[Test]
    public function it_validates_payload(): void
    {
        $this->client->jsonRequest('POST', "/api/experiences/{$this->experienceId}/sessions", [
            'startsAt' => 'tomorrow', 'capacity' => 0, 'price' => ['amount' => -1, 'currency' => 'euros'],
        ]);

        self::assertResponseStatusCodeSame(400);
        $fields = array_column($this->json()['errors'], 'field');
        self::assertContains('startsAt', $fields);
        self::assertContains('capacity', $fields);
        self::assertContains('price.amount', $fields);
        self::assertContains('price.currency', $fields);
    }

    #[Test]
    public function it_returns_404_for_unknown_experience(): void
    {
        $this->client->jsonRequest('POST', '/api/experiences/0192b3a4-1234-7abc-8def-0123456789ff/sessions', [
            'startsAt' => (new \DateTimeImmutable('+3 days'))->format(DATE_ATOM), 'capacity' => 5, 'price' => ['amount' => 100, 'currency' => 'EUR'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
```

- [ ] **Step 2: Ejecutar → falla** (`make test`).

- [ ] **Step 3: Implementación**

`src/Session/Infrastructure/Http/PriceRequest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Http;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class PriceRequest
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public int $amount,
        #[Assert\Currency]
        public string $currency,
    ) {
    }
}
```

`src/Session/Infrastructure/Http/ScheduleSessionRequest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Http;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ScheduleSessionRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\DateTime(format: \DateTimeInterface::ATOM, message: 'Use ISO 8601 with offset, e.g. 2026-10-01T10:00:00+02:00.')]
        public string $startsAt,
        #[Assert\Positive]
        public int $capacity,
        #[Assert\Valid]
        public PriceRequest $price,
    ) {
    }
}
```

`src/Session/Infrastructure/Http/ScheduleSessionController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Http;

use App\Session\Application\Schedule\ScheduleSessionCommand;
use App\Session\Application\Schedule\ScheduleSessionHandler;
use App\Session\Domain\SessionId;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class ScheduleSessionController
{
    public function __construct(
        private ScheduleSessionHandler $handler,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/api/experiences/{experienceId}/sessions', name: 'api_sessions_schedule', methods: ['POST'], requirements: ['experienceId' => '[0-9a-fA-F-]{36}'])]
    public function __invoke(
        string $experienceId,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        ScheduleSessionRequest $request,
    ): JsonResponse {
        $response = ($this->handler)(new ScheduleSessionCommand(
            id: SessionId::generate()->value,
            experienceId: $experienceId,
            startsAt: $request->startsAt,
            capacity: $request->capacity,
            priceAmount: $request->price->amount,
            priceCurrency: $request->price->currency,
        ));

        return new JsonResponse($response, Response::HTTP_CREATED, [
            'Location' => $this->urls->generate('api_sessions_find', ['id' => $response->id]),
        ]);
    }
}
```

`src/Session/Infrastructure/Http/FindSessionController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Http;

use App\Session\Application\Find\FindSessionHandler;
use App\Session\Application\Find\FindSessionQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class FindSessionController
{
    public function __construct(private FindSessionHandler $handler)
    {
    }

    #[Route('/api/sessions/{id}', name: 'api_sessions_find', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function __invoke(string $id): JsonResponse
    {
        return new JsonResponse(($this->handler)(new FindSessionQuery($id)));
    }
}
```

- [ ] **Step 4: Ejecutar → pasan** (`make test`, `make stan`, `make cs`).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(session): REST endpoints to schedule and fetch sessions

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```
