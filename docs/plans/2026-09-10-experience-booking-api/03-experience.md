# Fase 3 — Módulo Experience

Requiere Fase 2. Entrega `POST /api/experiences` y `GET /api/experiences/{id}` de punta a punta. `PUT` (edición) se implementa en la Fase 8 porque depende de `BookingRepository`.

---

### Task 4: Experience — dominio

**Files:**
- Create: `src/Experience/Domain/ExperienceId.php`, `ProviderId.php`, `Title.php`, `Description.php`, `ExperienceEditability.php`, `Experience.php`, `ExperienceRepository.php`
- Create: `src/Experience/Domain/Event/ExperienceRegistered.php`, `ExperienceUpdated.php`
- Create: `src/Experience/Domain/Exception/ExperienceNotFound.php`, `ExperienceHasBookings.php`
- Create: `tests/Doubles/Experience/InMemoryExperienceRepository.php`
- Test: `tests/Unit/Experience/Domain/ExperienceTest.php`, `TitleTest.php`

**Interfaces:**
- Consumes: `Shared\Domain\{Uuid, AggregateRoot, DomainEvent, DomainException, NotFoundException, InvalidValue}`.
- Produces: `Experience::register(ExperienceId, Title, Description, ProviderId): self`; `Experience::update(Title, Description, ExperienceEditability): void`; getters `id(): ExperienceId`, `title(): Title`, `description(): Description`, `providerId(): ProviderId`; `ExperienceRepository { save(Experience): void; find(ExperienceId): ?Experience }`; `ExperienceEditability::editable()/locked()`, `isEditable(): bool`; `Title::fromString`, `Description::fromString` (ambos con `public string $value`).

- [ ] **Step 1: Tests**

`tests/Unit/Experience/Domain/TitleTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Experience\Domain;

use App\Experience\Domain\Title;
use App\Shared\Domain\InvalidValue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TitleTest extends TestCase
{
    #[Test]
    public function it_trims_whitespace(): void
    {
        self::assertSame('Kayak at dawn', Title::fromString('  Kayak at dawn  ')->value);
    }

    #[Test]
    public function it_rejects_blank(): void
    {
        $this->expectException(InvalidValue::class);

        Title::fromString('   ');
    }

    #[Test]
    public function it_rejects_over_150_chars(): void
    {
        $this->expectException(InvalidValue::class);

        Title::fromString(str_repeat('a', 151));
    }
}
```

`tests/Unit/Experience/Domain/ExperienceTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Experience\Domain;

use App\Experience\Domain\Description;
use App\Experience\Domain\Event\ExperienceRegistered;
use App\Experience\Domain\Event\ExperienceUpdated;
use App\Experience\Domain\Exception\ExperienceHasBookings;
use App\Experience\Domain\Experience;
use App\Experience\Domain\ExperienceEditability;
use App\Experience\Domain\ExperienceId;
use App\Experience\Domain\ProviderId;
use App\Experience\Domain\Title;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExperienceTest extends TestCase
{
    #[Test]
    public function it_registers_and_records_event(): void
    {
        $id = ExperienceId::generate();
        $provider = ProviderId::generate();

        $experience = Experience::register($id, Title::fromString('Kayak'), Description::fromString('At dawn'), $provider);

        self::assertTrue($experience->id()->equals($id));
        self::assertSame('Kayak', $experience->title()->value);
        self::assertSame('At dawn', $experience->description()->value);
        self::assertTrue($experience->providerId()->equals($provider));

        $events = $experience->pullDomainEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(ExperienceRegistered::class, $events[0]);
        self::assertSame($id->value, $events[0]->aggregateId());
    }

    #[Test]
    public function it_updates_when_editable(): void
    {
        $experience = self::anExperience();
        $experience->pullDomainEvents();

        $experience->update(Title::fromString('New'), Description::fromString('Desc'), ExperienceEditability::editable());

        self::assertSame('New', $experience->title()->value);
        self::assertInstanceOf(ExperienceUpdated::class, $experience->pullDomainEvents()[0]);
    }

    #[Test]
    public function it_refuses_update_when_locked_by_bookings(): void
    {
        $experience = self::anExperience();

        $this->expectException(ExperienceHasBookings::class);

        $experience->update(Title::fromString('New'), Description::fromString('Desc'), ExperienceEditability::locked());
    }

    public static function anExperience(): Experience
    {
        return Experience::register(ExperienceId::generate(), Title::fromString('Kayak'), Description::fromString('At dawn'), ProviderId::generate());
    }
}
```

- [ ] **Step 2: Ejecutar → falla**

Run: `make test-unit` → clases no encontradas.

- [ ] **Step 3: Implementación**

`src/Experience/Domain/ExperienceId.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Domain;

use App\Shared\Domain\Uuid;

final class ExperienceId extends Uuid
{
}
```

`src/Experience/Domain/ProviderId.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Domain;

use App\Shared\Domain\Uuid;

/** Reference to an external provider; the provider itself is not modelled here. */
final class ProviderId extends Uuid
{
}
```

`src/Experience/Domain/Title.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Domain;

use App\Shared\Domain\InvalidValue;

final readonly class Title
{
    public const int MAX_LENGTH = 150;

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = trim($value);
        if ('' === $value) {
            throw new InvalidValue('Title cannot be blank.');
        }
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new InvalidValue(sprintf('Title cannot exceed %d characters.', self::MAX_LENGTH));
        }

        return new self($value);
    }
}
```

`src/Experience/Domain/Description.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Domain;

use App\Shared\Domain\InvalidValue;

final readonly class Description
{
    public const int MAX_LENGTH = 2000;

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = trim($value);
        if ('' === $value) {
            throw new InvalidValue('Description cannot be blank.');
        }
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new InvalidValue(sprintf('Description cannot exceed %d characters.', self::MAX_LENGTH));
        }

        return new self($value);
    }
}
```

`src/Experience/Domain/ExperienceEditability.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Domain;

/**
 * Whether an experience may still be edited. The fact (are there confirmed bookings?)
 * lives in the Booking module; the use case resolves it and the aggregate enforces it.
 */
final readonly class ExperienceEditability
{
    private function __construct(private bool $editable)
    {
    }

    public static function editable(): self
    {
        return new self(true);
    }

    public static function locked(): self
    {
        return new self(false);
    }

    public static function fromHasConfirmedBookings(bool $hasConfirmedBookings): self
    {
        return new self(!$hasConfirmedBookings);
    }

    public function isEditable(): bool
    {
        return $this->editable;
    }
}
```

`src/Experience/Domain/Event/ExperienceRegistered.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Domain\Event;

use App\Shared\Domain\DomainEvent;
use DateTimeImmutable;

final readonly class ExperienceRegistered implements DomainEvent
{
    public function __construct(
        public string $experienceId,
        public string $providerId,
        public string $title,
        private DateTimeImmutable $occurredOn = new DateTimeImmutable(),
    ) {
    }

    public function aggregateId(): string
    {
        return $this->experienceId;
    }

    public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public static function eventName(): string
    {
        return 'experience.registered';
    }
}
```

`src/Experience/Domain/Event/ExperienceUpdated.php`: igual que el anterior con clase `ExperienceUpdated`, mismos campos y `eventName()` → `'experience.updated'`.

`src/Experience/Domain/Exception/ExperienceNotFound.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Domain\Exception;

use App\Experience\Domain\ExperienceId;
use App\Shared\Domain\NotFoundException;

final class ExperienceNotFound extends NotFoundException
{
    public static function withId(ExperienceId $id): self
    {
        return new self(sprintf('Experience <%s> not found.', $id->value));
    }

    public function errorCode(): string
    {
        return 'experience-not-found';
    }
}
```

`src/Experience/Domain/Exception/ExperienceHasBookings.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Domain\Exception;

use App\Experience\Domain\ExperienceId;
use App\Shared\Domain\DomainException;

final class ExperienceHasBookings extends DomainException
{
    public static function withId(ExperienceId $id): self
    {
        return new self(sprintf('Experience <%s> cannot be edited because it already has confirmed bookings.', $id->value));
    }

    public function errorCode(): string
    {
        return 'experience-has-bookings';
    }
}
```

`src/Experience/Domain/Experience.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Domain;

use App\Experience\Domain\Event\ExperienceRegistered;
use App\Experience\Domain\Event\ExperienceUpdated;
use App\Experience\Domain\Exception\ExperienceHasBookings;
use App\Shared\Domain\AggregateRoot;

final class Experience extends AggregateRoot
{
    private function __construct(
        private readonly ExperienceId $id,
        private Title $title,
        private Description $description,
        private readonly ProviderId $providerId,
    ) {
    }

    public static function register(ExperienceId $id, Title $title, Description $description, ProviderId $providerId): self
    {
        $experience = new self($id, $title, $description, $providerId);
        $experience->record(new ExperienceRegistered($id->value, $providerId->value, $title->value));

        return $experience;
    }

    public function update(Title $title, Description $description, ExperienceEditability $editability): void
    {
        if (!$editability->isEditable()) {
            throw ExperienceHasBookings::withId($this->id);
        }

        $this->title = $title;
        $this->description = $description;
        $this->record(new ExperienceUpdated($this->id->value, $this->providerId->value, $title->value));
    }

    public function id(): ExperienceId
    {
        return $this->id;
    }

    public function title(): Title
    {
        return $this->title;
    }

    public function description(): Description
    {
        return $this->description;
    }

    public function providerId(): ProviderId
    {
        return $this->providerId;
    }
}
```

`src/Experience/Domain/ExperienceRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Domain;

interface ExperienceRepository
{
    public function save(Experience $experience): void;

    public function find(ExperienceId $id): ?Experience;
}
```

`tests/Doubles/Experience/InMemoryExperienceRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Doubles\Experience;

use App\Experience\Domain\Experience;
use App\Experience\Domain\ExperienceId;
use App\Experience\Domain\ExperienceRepository;

final class InMemoryExperienceRepository implements ExperienceRepository
{
    /** @var array<string, Experience> */
    private array $items = [];

    public function save(Experience $experience): void
    {
        $this->items[$experience->id()->value] = $experience;
    }

    public function find(ExperienceId $id): ?Experience
    {
        return $this->items[$id->value] ?? null;
    }
}
```

- [ ] **Step 4: Ejecutar → pasan**

Run: `make test-unit` → verde.

- [ ] **Step 5: Commit**

```bash
git add src/Experience/Domain tests/Unit/Experience tests/Doubles/Experience
git commit -m "feat(experience): domain model with editability rule

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: Experience — casos de uso Register y Find

**Files:**
- Create: `src/Experience/Application/ExperienceResponse.php`
- Create: `src/Experience/Application/Register/RegisterExperienceCommand.php`, `RegisterExperienceHandler.php`
- Create: `src/Experience/Application/Find/FindExperienceQuery.php`, `FindExperienceHandler.php`
- Test: `tests/Unit/Experience/Application/RegisterExperienceHandlerTest.php`, `FindExperienceHandlerTest.php`

**Interfaces:**
- Consumes: Task 4; `Shared\Application\DomainEventPublisher`.
- Produces: `RegisterExperienceCommand(string $id, string $title, string $description, string $providerId)`; `RegisterExperienceHandler::__invoke(RegisterExperienceCommand): ExperienceResponse`; `FindExperienceQuery(string $id)`; `FindExperienceHandler::__invoke(FindExperienceQuery): ExperienceResponse`; `ExperienceResponse(string $id, string $title, string $description, string $providerId)` con `static fromExperience(Experience)`.

- [ ] **Step 1: Tests**

`tests/Unit/Experience/Application/RegisterExperienceHandlerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Experience\Application;

use App\Experience\Application\Register\RegisterExperienceCommand;
use App\Experience\Application\Register\RegisterExperienceHandler;
use App\Experience\Domain\Event\ExperienceRegistered;
use App\Experience\Domain\ExperienceId;
use App\Shared\Domain\InvalidValue;
use App\Tests\Doubles\Experience\InMemoryExperienceRepository;
use App\Tests\Doubles\Shared\InMemoryDomainEventPublisher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegisterExperienceHandlerTest extends TestCase
{
    private InMemoryExperienceRepository $repository;
    private InMemoryDomainEventPublisher $events;
    private RegisterExperienceHandler $handler;

    protected function setUp(): void
    {
        $this->repository = new InMemoryExperienceRepository();
        $this->events = new InMemoryDomainEventPublisher();
        $this->handler = new RegisterExperienceHandler($this->repository, $this->events);
    }

    #[Test]
    public function it_registers_persists_and_publishes(): void
    {
        $command = new RegisterExperienceCommand(
            id: '0192b3a4-1234-7abc-8def-0123456789ab',
            title: 'Kayak at dawn',
            description: 'Two hours paddling.',
            providerId: '0192b3a4-1234-7abc-8def-0123456789ac',
        );

        $response = ($this->handler)($command);

        self::assertSame($command->id, $response->id);
        self::assertSame('Kayak at dawn', $response->title);
        self::assertNotNull($this->repository->find(ExperienceId::fromString($command->id)));
        self::assertCount(1, $this->events->publishedOf(ExperienceRegistered::class));
    }

    #[Test]
    public function it_rejects_blank_title(): void
    {
        $this->expectException(InvalidValue::class);

        ($this->handler)(new RegisterExperienceCommand('0192b3a4-1234-7abc-8def-0123456789ab', ' ', 'x', '0192b3a4-1234-7abc-8def-0123456789ac'));
    }
}
```

`tests/Unit/Experience/Application/FindExperienceHandlerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Experience\Application;

use App\Experience\Application\Find\FindExperienceHandler;
use App\Experience\Application\Find\FindExperienceQuery;
use App\Experience\Domain\Exception\ExperienceNotFound;
use App\Tests\Doubles\Experience\InMemoryExperienceRepository;
use App\Tests\Unit\Experience\Domain\ExperienceTest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FindExperienceHandlerTest extends TestCase
{
    #[Test]
    public function it_returns_response_dto(): void
    {
        $repository = new InMemoryExperienceRepository();
        $experience = ExperienceTest::anExperience();
        $repository->save($experience);

        $response = (new FindExperienceHandler($repository))(new FindExperienceQuery($experience->id()->value));

        self::assertSame($experience->id()->value, $response->id);
        self::assertSame('Kayak', $response->title);
    }

    #[Test]
    public function it_throws_when_missing(): void
    {
        $this->expectException(ExperienceNotFound::class);

        (new FindExperienceHandler(new InMemoryExperienceRepository()))(new FindExperienceQuery('0192b3a4-1234-7abc-8def-0123456789ab'));
    }
}
```

- [ ] **Step 2: Ejecutar → falla** (`make test-unit`).

- [ ] **Step 3: Implementación**

`src/Experience/Application/ExperienceResponse.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Application;

use App\Experience\Domain\Experience;

final readonly class ExperienceResponse
{
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public string $providerId,
    ) {
    }

    public static function fromExperience(Experience $experience): self
    {
        return new self(
            $experience->id()->value,
            $experience->title()->value,
            $experience->description()->value,
            $experience->providerId()->value,
        );
    }
}
```

`src/Experience/Application/Register/RegisterExperienceCommand.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Application\Register;

final readonly class RegisterExperienceCommand
{
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public string $providerId,
    ) {
    }
}
```

`src/Experience/Application/Register/RegisterExperienceHandler.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Application\Register;

use App\Experience\Application\ExperienceResponse;
use App\Experience\Domain\Description;
use App\Experience\Domain\Experience;
use App\Experience\Domain\ExperienceId;
use App\Experience\Domain\ExperienceRepository;
use App\Experience\Domain\ProviderId;
use App\Experience\Domain\Title;
use App\Shared\Application\DomainEventPublisher;

final readonly class RegisterExperienceHandler
{
    public function __construct(
        private ExperienceRepository $experiences,
        private DomainEventPublisher $events,
    ) {
    }

    public function __invoke(RegisterExperienceCommand $command): ExperienceResponse
    {
        $experience = Experience::register(
            ExperienceId::fromString($command->id),
            Title::fromString($command->title),
            Description::fromString($command->description),
            ProviderId::fromString($command->providerId),
        );

        $this->experiences->save($experience);
        $this->events->publish(...$experience->pullDomainEvents());

        return ExperienceResponse::fromExperience($experience);
    }
}
```

`src/Experience/Application/Find/FindExperienceQuery.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Application\Find;

final readonly class FindExperienceQuery
{
    public function __construct(public string $id)
    {
    }
}
```

`src/Experience/Application/Find/FindExperienceHandler.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Application\Find;

use App\Experience\Application\ExperienceResponse;
use App\Experience\Domain\Exception\ExperienceNotFound;
use App\Experience\Domain\ExperienceId;
use App\Experience\Domain\ExperienceRepository;

final readonly class FindExperienceHandler
{
    public function __construct(private ExperienceRepository $experiences)
    {
    }

    public function __invoke(FindExperienceQuery $query): ExperienceResponse
    {
        $id = ExperienceId::fromString($query->id);
        $experience = $this->experiences->find($id) ?? throw ExperienceNotFound::withId($id);

        return ExperienceResponse::fromExperience($experience);
    }
}
```

- [ ] **Step 4: Ejecutar → pasan** (`make test-unit`).

- [ ] **Step 5: Commit**

```bash
git add src/Experience/Application tests/Unit/Experience/Application
git commit -m "feat(experience): register and find use cases

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 6: Experience — persistencia Doctrine

**Files:**
- Create: `src/Experience/Infrastructure/Persistence/Doctrine/Type/ExperienceIdType.php`, `ProviderIdType.php`, `TitleType.php`, `DescriptionType.php`
- Create: `src/Experience/Infrastructure/Persistence/Doctrine/DoctrineExperienceRepository.php`
- Create: `config/doctrine/Experience/Experience.orm.xml`
- Create: `migrations/Version20260910120000.php`
- Modify: `config/packages/doctrine.yaml` (`types`), `config/services.yaml` (alias)
- Test: `tests/Integration/Experience/DoctrineExperienceRepositoryTest.php`

**Interfaces:**
- Consumes: Task 3 (`UuidType`, `StringValueObjectType`), Task 4.
- Produces: `DoctrineExperienceRepository implements ExperienceRepository`; tabla `experiences`.

- [ ] **Step 1: Test de integración**

`tests/Integration/Experience/DoctrineExperienceRepositoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Experience;

use App\Experience\Domain\ExperienceId;
use App\Experience\Domain\ExperienceRepository;
use App\Experience\Domain\Title;
use App\Experience\Domain\Description;
use App\Experience\Domain\ExperienceEditability;
use App\Tests\Unit\Experience\Domain\ExperienceTest;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineExperienceRepositoryTest extends KernelTestCase
{
    private ExperienceRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(ExperienceRepository::class);
    }

    #[Test]
    public function it_persists_and_rehydrates(): void
    {
        $experience = ExperienceTest::anExperience();

        $this->repository->save($experience);
        self::getContainer()->get('doctrine')->getManager()->clear();

        $found = $this->repository->find($experience->id());
        self::assertNotNull($found);
        self::assertSame('Kayak', $found->title()->value);
        self::assertTrue($found->providerId()->equals($experience->providerId()));
    }

    #[Test]
    public function it_updates_mutable_fields(): void
    {
        $experience = ExperienceTest::anExperience();
        $this->repository->save($experience);

        $experience->update(Title::fromString('Renamed'), Description::fromString('New'), ExperienceEditability::editable());
        $this->repository->save($experience);
        self::getContainer()->get('doctrine')->getManager()->clear();

        self::assertSame('Renamed', $this->repository->find($experience->id())?->title()->value);
    }

    #[Test]
    public function it_returns_null_when_missing(): void
    {
        self::assertNull($this->repository->find(ExperienceId::generate()));
    }
}
```

- [ ] **Step 2: Ejecutar → falla** (`make test` → servicio no encontrado / tabla inexistente).

- [ ] **Step 3: Implementación**

Types (`src/Experience/Infrastructure/Persistence/Doctrine/Type/`):

`ExperienceIdType.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Infrastructure\Persistence\Doctrine\Type;

use App\Experience\Domain\ExperienceId;
use App\Shared\Infrastructure\Doctrine\Type\UuidType;

final class ExperienceIdType extends UuidType
{
    protected static function valueObjectClass(): string
    {
        return ExperienceId::class;
    }
}
```
`ProviderIdType.php`: idéntico con `ProviderId`.

`TitleType.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Infrastructure\Persistence\Doctrine\Type;

use App\Experience\Domain\Title;
use App\Shared\Infrastructure\Doctrine\Type\StringValueObjectType;

/** @extends StringValueObjectType<Title> */
final class TitleType extends StringValueObjectType
{
    protected static function valueObjectClass(): string
    {
        return Title::class;
    }
}
```
`DescriptionType.php`: idéntico con `Description`, pero sobrescribe la declaración SQL a texto:
```php
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }
```
(importa `Doctrine\DBAL\Platforms\AbstractPlatform`).

`config/doctrine/Experience/Experience.orm.xml`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping
                                      https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd">
  <entity name="App\Experience\Domain\Experience" table="experiences">
    <id name="id" type="experience_id" column="id"/>
    <field name="title" type="experience_title" column="title" length="150"/>
    <field name="description" type="experience_description" column="description"/>
    <field name="providerId" type="provider_id" column="provider_id"/>
  </entity>
</doctrine-mapping>
```

`config/packages/doctrine.yaml` → sustituye `types: {}` por:
```yaml
    types:
      experience_id: App\Experience\Infrastructure\Persistence\Doctrine\Type\ExperienceIdType
      provider_id: App\Experience\Infrastructure\Persistence\Doctrine\Type\ProviderIdType
      experience_title: App\Experience\Infrastructure\Persistence\Doctrine\Type\TitleType
      experience_description: App\Experience\Infrastructure\Persistence\Doctrine\Type\DescriptionType
```

`src/Experience/Infrastructure/Persistence/Doctrine/DoctrineExperienceRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Infrastructure\Persistence\Doctrine;

use App\Experience\Domain\Experience;
use App\Experience\Domain\ExperienceId;
use App\Experience\Domain\ExperienceRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineExperienceRepository implements ExperienceRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Experience $experience): void
    {
        $this->entityManager->persist($experience);
        $this->entityManager->flush();
    }

    public function find(ExperienceId $id): ?Experience
    {
        return $this->entityManager->find(Experience::class, $id->value);
    }
}
```

`migrations/Version20260910120000.php`:
```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create experiences table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE experiences (
            id UUID NOT NULL,
            title VARCHAR(150) NOT NULL,
            description TEXT NOT NULL,
            provider_id UUID NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX idx_experiences_provider ON experiences (provider_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE experiences');
    }
}
```

`config/services.yaml` → añade:
```yaml
  App\Experience\Domain\ExperienceRepository: '@App\Experience\Infrastructure\Persistence\Doctrine\DoctrineExperienceRepository'
```

- [ ] **Step 4: Migrar y ejecutar**

```bash
make migrate
make test
```
Expected: migración aplicada en dev; `make test` crea/migra la BD de test y todo en verde. `make stan` limpio.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(experience): Doctrine repository, XML mapping and migration

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 7: Experience — HTTP (POST y GET)

**Files:**
- Create: `src/Experience/Infrastructure/Http/RegisterExperienceRequest.php`, `RegisterExperienceController.php`, `FindExperienceController.php`
- Test: `tests/Functional/Experience/ExperienceApiTest.php`

**Interfaces:**
- Consumes: Task 5 handlers; `ProblemJsonExceptionListener` (Task 3).
- Produces: `POST /api/experiences` → 201 + `Location`; `GET /api/experiences/{id}` → 200/404. Nombre de ruta `api_experiences_find`.

- [ ] **Step 1: Test funcional**

`tests/Functional/Experience/ExperienceApiTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Experience;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExperienceApiTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function it_registers_an_experience(): void
    {
        $this->client->jsonRequest('POST', '/api/experiences', [
            'title' => 'Kayak at dawn',
            'description' => 'Two hours paddling along the coast.',
            'providerId' => '0192b3a4-1234-7abc-8def-0123456789ac',
        ]);

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertSame('Kayak at dawn', $body['title']);
        self::assertSame('0192b3a4-1234-7abc-8def-0123456789ac', $body['providerId']);
        self::assertResponseHeaderSame('Location', '/api/experiences/'.$body['id']);

        $this->client->request('GET', '/api/experiences/'.$body['id']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($body, $this->json());
    }

    #[Test]
    public function it_validates_payload(): void
    {
        $this->client->jsonRequest('POST', '/api/experiences', ['title' => '', 'description' => 'x', 'providerId' => 'nope']);

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $body = $this->json();
        self::assertSame('/problems/validation-failed', $body['type']);
        $fields = array_column($body['errors'], 'field');
        self::assertContains('title', $fields);
        self::assertContains('providerId', $fields);
    }

    #[Test]
    public function it_returns_404_for_unknown_experience(): void
    {
        $this->client->request('GET', '/api/experiences/0192b3a4-1234-7abc-8def-0123456789ff');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('/problems/experience-not-found', $this->json()['type']);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
```

- [ ] **Step 2: Ejecutar → falla** (`make test` → 404 en POST).

- [ ] **Step 3: Implementación**

`src/Experience/Infrastructure/Http/RegisterExperienceRequest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Infrastructure\Http;

use App\Experience\Domain\Description;
use App\Experience\Domain\Title;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterExperienceRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: Title::MAX_LENGTH)]
        public string $title,
        #[Assert\NotBlank]
        #[Assert\Length(max: Description::MAX_LENGTH)]
        public string $description,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $providerId,
    ) {
    }
}
```

`src/Experience/Infrastructure/Http/RegisterExperienceController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Infrastructure\Http;

use App\Experience\Application\Register\RegisterExperienceCommand;
use App\Experience\Application\Register\RegisterExperienceHandler;
use App\Experience\Domain\ExperienceId;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class RegisterExperienceController
{
    public function __construct(
        private RegisterExperienceHandler $handler,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/api/experiences', name: 'api_experiences_register', methods: ['POST'])]
    public function __invoke(
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        RegisterExperienceRequest $request,
    ): JsonResponse {
        $response = ($this->handler)(new RegisterExperienceCommand(
            id: ExperienceId::generate()->value,
            title: $request->title,
            description: $request->description,
            providerId: $request->providerId,
        ));

        return new JsonResponse($response, Response::HTTP_CREATED, [
            'Location' => $this->urls->generate('api_experiences_find', ['id' => $response->id]),
        ]);
    }
}
```

`src/Experience/Infrastructure/Http/FindExperienceController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Infrastructure\Http;

use App\Experience\Application\Find\FindExperienceHandler;
use App\Experience\Application\Find\FindExperienceQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class FindExperienceController
{
    public function __construct(private FindExperienceHandler $handler)
    {
    }

    #[Route('/api/experiences/{id}', name: 'api_experiences_find', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function __invoke(string $id): JsonResponse
    {
        return new JsonResponse(($this->handler)(new FindExperienceQuery($id)));
    }
}
```

- [ ] **Step 4: Ejecutar → pasan**

```bash
make test
curl -s -i -X POST http://localhost:8080/api/experiences -H 'Content-Type: application/json' \
  -d '{"title":"Kayak","description":"At dawn","providerId":"0192b3a4-1234-7abc-8def-0123456789ac"}'
```
Expected: tests verdes; `curl` devuelve `201`, `Location` y JSON. `make stan`, `make cs` limpios.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(experience): REST endpoints to register and fetch experiences

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```
