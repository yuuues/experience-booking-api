# Fase 6 — Módulo Booking (aplicación, persistencia, HTTP)

Requiere Fase 5. Entrega reservar, consultar y cancelar, con transacción + `FOR UPDATE` sobre la sesión.

---

### Task 13: Booking — casos de uso Book, Cancel y Find

**Files:**
- Create: `src/Booking/Application/BookingResponse.php`
- Create: `src/Booking/Application/Book/BookSeatsCommand.php`, `BookSeatsHandler.php`
- Create: `src/Booking/Application/Cancel/CancelBookingCommand.php`, `CancelBookingHandler.php`
- Create: `src/Booking/Application/Find/FindBookingQuery.php`, `FindBookingHandler.php`
- Test: `tests/Unit/Booking/Application/BookSeatsHandlerTest.php`, `CancelBookingHandlerTest.php`, `FindBookingHandlerTest.php`

**Interfaces:**
- Consumes: Tasks 8–9, `TransactionalRunner`, `DomainEventPublisher`, `Clock`, `BookingReferenceGenerator`.
- Produces: `BookSeatsCommand(string $bookingId, string $sessionId, string $userId, int $seats)`; `BookSeatsHandler::__invoke(...): BookingResponse`; `CancelBookingCommand(string $reference)`; `CancelBookingHandler`; `FindBookingQuery(string $reference)`; `FindBookingHandler`; `BookingResponse(reference, sessionId, userId, seats, MoneyResponse $total, status, bookedAt, ?cancelledAt)` con `static fromBooking(Booking)`.

- [ ] **Step 1: Tests**

`tests/Unit/Booking/Application/BookSeatsHandlerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Booking\Application;

use App\Booking\Application\Book\BookSeatsCommand;
use App\Booking\Application\Book\BookSeatsHandler;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\Event\BookingConfirmed;
use App\Session\Domain\Exception\NotEnoughSeatsAvailable;
use App\Session\Domain\Exception\SessionNotFound;
use App\Tests\Doubles\Booking\InMemoryBookingRepository;
use App\Tests\Doubles\Booking\SequentialBookingReferenceGenerator;
use App\Tests\Doubles\Session\InMemorySessionRepository;
use App\Tests\Doubles\Shared\FixedClock;
use App\Tests\Doubles\Shared\InMemoryDomainEventPublisher;
use App\Tests\Doubles\Shared\InMemoryTransactionalRunner;
use App\Tests\Unit\Session\Domain\SessionTest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BookSeatsHandlerTest extends TestCase
{
    private InMemorySessionRepository $sessions;
    private InMemoryBookingRepository $bookings;
    private InMemoryTransactionalRunner $transaction;
    private InMemoryDomainEventPublisher $events;
    private BookSeatsHandler $handler;
    private string $sessionId;

    protected function setUp(): void
    {
        $clock = new FixedClock('2026-10-01T10:00:00+00:00');
        $this->sessions = new InMemorySessionRepository();
        $this->bookings = new InMemoryBookingRepository($this->sessions);
        $this->transaction = new InMemoryTransactionalRunner();
        $this->events = new InMemoryDomainEventPublisher();
        $this->handler = new BookSeatsHandler(
            $this->sessions,
            $this->bookings,
            new SequentialBookingReferenceGenerator(),
            $clock,
            $this->transaction,
            $this->events,
        );

        $session = SessionTest::aSession($clock, capacity: 5, priceAmount: 2000);
        $this->sessions->save($session);
        $this->sessionId = $session->id()->value;
    }

    #[Test]
    public function it_books_inside_a_transaction_with_a_locked_session(): void
    {
        $response = ($this->handler)($this->command(seats: 2));

        self::assertSame('BK-00000001', $response->reference);
        self::assertSame('confirmed', $response->status);
        self::assertSame(4000, $response->total->amount);
        self::assertSame(1, $this->transaction->transactions);
        self::assertSame(1, $this->sessions->lockedReads);
        self::assertNotNull($this->bookings->findByReference(BookingReference::fromString('BK-00000001')));
        self::assertSame(3, $this->sessions->find($this->session())?->availableSeats());
        self::assertCount(1, $this->events->publishedOf(BookingConfirmed::class));
    }

    #[Test]
    public function it_skips_references_already_taken(): void
    {
        // BookingTest::aBooking() carries reference BK-00000001, the first one the sequential generator yields.
        $this->bookings->save(\App\Tests\Unit\Booking\Domain\BookingTest::aBooking());

        $response = ($this->handler)($this->command(seats: 1));

        self::assertSame('BK-00000002', $response->reference);
    }

    #[Test]
    public function it_fails_when_not_enough_seats(): void
    {
        $this->expectException(NotEnoughSeatsAvailable::class);

        ($this->handler)($this->command(seats: 6));
    }

    #[Test]
    public function it_fails_when_session_missing(): void
    {
        $this->expectException(SessionNotFound::class);

        ($this->handler)(new BookSeatsCommand('0192b3a4-1234-7abc-8def-0123456789b1', '0192b3a4-1234-7abc-8def-0123456789ff', '0192b3a4-1234-7abc-8def-0123456789ad', 1));
    }

    private function command(int $seats): BookSeatsCommand
    {
        return new BookSeatsCommand(
            bookingId: \App\Booking\Domain\BookingId::generate()->value,
            sessionId: $this->sessionId,
            userId: '0192b3a4-1234-7abc-8def-0123456789ad',
            seats: $seats,
        );
    }

    private function session(): \App\Session\Domain\SessionId
    {
        return \App\Session\Domain\SessionId::fromString($this->sessionId);
    }
}
```

`tests/Unit/Booking/Application/CancelBookingHandlerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Booking\Application;

use App\Booking\Application\Book\BookSeatsCommand;
use App\Booking\Application\Book\BookSeatsHandler;
use App\Booking\Application\Cancel\CancelBookingCommand;
use App\Booking\Application\Cancel\CancelBookingHandler;
use App\Booking\Domain\BookingId;
use App\Booking\Domain\Event\BookingCancelled;
use App\Booking\Domain\Exception\BookingAlreadyCancelled;
use App\Booking\Domain\Exception\BookingNotFound;
use App\Session\Domain\Exception\CancellationWindowClosed;
use App\Tests\Doubles\Booking\InMemoryBookingRepository;
use App\Tests\Doubles\Booking\SequentialBookingReferenceGenerator;
use App\Tests\Doubles\Session\InMemorySessionRepository;
use App\Tests\Doubles\Shared\FixedClock;
use App\Tests\Doubles\Shared\InMemoryDomainEventPublisher;
use App\Tests\Doubles\Shared\InMemoryTransactionalRunner;
use App\Tests\Unit\Session\Domain\SessionTest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CancelBookingHandlerTest extends TestCase
{
    private FixedClock $clock;
    private InMemorySessionRepository $sessions;
    private InMemoryDomainEventPublisher $events;
    private CancelBookingHandler $handler;
    private string $sessionId;
    private string $reference;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2026-10-01T10:00:00+00:00');
        $this->sessions = new InMemorySessionRepository();
        $bookings = new InMemoryBookingRepository($this->sessions);
        $transaction = new InMemoryTransactionalRunner();
        $this->events = new InMemoryDomainEventPublisher();

        $session = SessionTest::aSession($this->clock, startsAt: '2026-10-05T10:00:00+00:00', capacity: 5);
        $this->sessions->save($session);
        $this->sessionId = $session->id()->value;

        $book = new BookSeatsHandler($this->sessions, $bookings, new SequentialBookingReferenceGenerator(), $this->clock, $transaction, $this->events);
        $this->reference = $book(new BookSeatsCommand(BookingId::generate()->value, $this->sessionId, '0192b3a4-1234-7abc-8def-0123456789ad', 2))->reference;

        $this->handler = new CancelBookingHandler($bookings, $this->sessions, $this->clock, $transaction, $this->events);
    }

    #[Test]
    public function it_cancels_and_releases_seats(): void
    {
        $response = ($this->handler)(new CancelBookingCommand($this->reference));

        self::assertSame('cancelled', $response->status);
        self::assertNotNull($response->cancelledAt);
        self::assertSame(5, $this->sessions->find(\App\Session\Domain\SessionId::fromString($this->sessionId))?->availableSeats());
        self::assertSame(2, $this->sessions->lockedReads); // one from booking, one from cancelling
        self::assertCount(1, $this->events->publishedOf(BookingCancelled::class));
    }

    #[Test]
    public function it_refuses_double_cancellation(): void
    {
        ($this->handler)(new CancelBookingCommand($this->reference));

        $this->expectException(BookingAlreadyCancelled::class);

        ($this->handler)(new CancelBookingCommand($this->reference));
    }

    #[Test]
    public function it_refuses_inside_24h_window(): void
    {
        $this->clock->travelTo('2026-10-04T12:00:00+00:00');

        $this->expectException(CancellationWindowClosed::class);

        ($this->handler)(new CancelBookingCommand($this->reference));
    }

    #[Test]
    public function it_fails_for_unknown_reference(): void
    {
        $this->expectException(BookingNotFound::class);

        ($this->handler)(new CancelBookingCommand('BK-ZZZZZZZZ'));
    }
}
```

`tests/Unit/Booking/Application/FindBookingHandlerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Booking\Application;

use App\Booking\Application\Find\FindBookingHandler;
use App\Booking\Application\Find\FindBookingQuery;
use App\Booking\Domain\Exception\BookingNotFound;
use App\Tests\Doubles\Booking\InMemoryBookingRepository;
use App\Tests\Unit\Booking\Domain\BookingTest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FindBookingHandlerTest extends TestCase
{
    #[Test]
    public function it_returns_the_booking(): void
    {
        $bookings = new InMemoryBookingRepository();
        $booking = BookingTest::aBooking();
        $bookings->save($booking);

        $response = (new FindBookingHandler($bookings))(new FindBookingQuery('BK-00000001'));

        self::assertSame('BK-00000001', $response->reference);
        self::assertSame(2, $response->seats);
        self::assertSame('2026-10-01T10:00:00+00:00', $response->bookedAt);
        self::assertNull($response->cancelledAt);
    }

    #[Test]
    public function it_throws_when_missing(): void
    {
        $this->expectException(BookingNotFound::class);

        (new FindBookingHandler(new InMemoryBookingRepository()))(new FindBookingQuery('BK-00000009'));
    }
}
```

- [ ] **Step 2: Ejecutar → falla** (`make test-unit`).

- [ ] **Step 3: Implementación**

`src/Booking/Application/BookingResponse.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Application;

use App\Booking\Domain\Booking;
use App\Session\Application\MoneyResponse;

final readonly class BookingResponse
{
    public function __construct(
        public string $reference,
        public string $sessionId,
        public string $userId,
        public int $seats,
        public MoneyResponse $total,
        public string $status,
        public string $bookedAt,
        public ?string $cancelledAt,
    ) {
    }

    public static function fromBooking(Booking $booking): self
    {
        return new self(
            $booking->reference()->value,
            $booking->sessionId()->value,
            $booking->userId()->value,
            $booking->seats()->value,
            MoneyResponse::fromMoney($booking->totalPrice()),
            $booking->status()->value,
            $booking->bookedAt()->format(DATE_ATOM),
            $booking->cancelledAt()?->format(DATE_ATOM),
        );
    }
}
```

`src/Booking/Application/Book/BookSeatsCommand.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Application\Book;

final readonly class BookSeatsCommand
{
    public function __construct(
        public string $bookingId,
        public string $sessionId,
        public string $userId,
        public int $seats,
    ) {
    }
}
```

`src/Booking/Application/Book/BookSeatsHandler.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Application\Book;

use App\Booking\Application\BookingResponse;
use App\Booking\Domain\BookingId;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\BookingReferenceGenerator;
use App\Booking\Domain\BookingRepository;
use App\Booking\Domain\Seats;
use App\Booking\Domain\UserId;
use App\Session\Domain\Exception\SessionNotFound;
use App\Session\Domain\SessionId;
use App\Session\Domain\SessionRepository;
use App\Shared\Application\DomainEventPublisher;
use App\Shared\Application\TransactionalRunner;
use App\Shared\Domain\Clock;

final readonly class BookSeatsHandler
{
    public function __construct(
        private SessionRepository $sessions,
        private BookingRepository $bookings,
        private BookingReferenceGenerator $references,
        private Clock $clock,
        private TransactionalRunner $transaction,
        private DomainEventPublisher $events,
    ) {
    }

    public function __invoke(BookSeatsCommand $command): BookingResponse
    {
        $sessionId = SessionId::fromString($command->sessionId);
        $bookingId = BookingId::fromString($command->bookingId);
        $userId = UserId::fromString($command->userId);
        $seats = Seats::fromInt($command->seats);

        return $this->transaction->run(function () use ($sessionId, $bookingId, $userId, $seats): BookingResponse {
            // Row lock on the session: concurrent bookings for the same session queue here.
            $session = $this->sessions->findForUpdate($sessionId) ?? throw SessionNotFound::withId($sessionId);

            $booking = $session->book($bookingId, $this->uniqueReference(), $userId, $seats, $this->clock);

            $this->sessions->save($session);
            $this->bookings->save($booking);
            $this->events->publish(...$session->pullDomainEvents(), ...$booking->pullDomainEvents());

            return BookingResponse::fromBooking($booking);
        });
    }

    private function uniqueReference(): BookingReference
    {
        do {
            $reference = $this->references->next();
        } while ($this->bookings->existsByReference($reference));

        return $reference;
    }
}
```

`src/Booking/Application/Cancel/CancelBookingCommand.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Application\Cancel;

final readonly class CancelBookingCommand
{
    public function __construct(public string $reference)
    {
    }
}
```

`src/Booking/Application/Cancel/CancelBookingHandler.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Application\Cancel;

use App\Booking\Application\BookingResponse;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\BookingRepository;
use App\Booking\Domain\Exception\BookingNotFound;
use App\Session\Domain\Exception\SessionNotFound;
use App\Session\Domain\SessionRepository;
use App\Shared\Application\DomainEventPublisher;
use App\Shared\Application\TransactionalRunner;
use App\Shared\Domain\Clock;

final readonly class CancelBookingHandler
{
    public function __construct(
        private BookingRepository $bookings,
        private SessionRepository $sessions,
        private Clock $clock,
        private TransactionalRunner $transaction,
        private DomainEventPublisher $events,
    ) {
    }

    public function __invoke(CancelBookingCommand $command): BookingResponse
    {
        $reference = BookingReference::fromString($command->reference);

        return $this->transaction->run(function () use ($reference): BookingResponse {
            $booking = $this->bookings->findByReference($reference) ?? throw BookingNotFound::withReference($reference);
            $session = $this->sessions->findForUpdate($booking->sessionId()) ?? throw SessionNotFound::withId($booking->sessionId());

            $session->cancelBooking($booking, $this->clock);

            $this->sessions->save($session);
            $this->bookings->save($booking);
            $this->events->publish(...$session->pullDomainEvents(), ...$booking->pullDomainEvents());

            return BookingResponse::fromBooking($booking);
        });
    }
}
```

`src/Booking/Application/Find/FindBookingQuery.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Application\Find;

final readonly class FindBookingQuery
{
    public function __construct(public string $reference)
    {
    }
}
```

`src/Booking/Application/Find/FindBookingHandler.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Application\Find;

use App\Booking\Application\BookingResponse;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\BookingRepository;
use App\Booking\Domain\Exception\BookingNotFound;

final readonly class FindBookingHandler
{
    public function __construct(private BookingRepository $bookings)
    {
    }

    public function __invoke(FindBookingQuery $query): BookingResponse
    {
        $reference = BookingReference::fromString($query->reference);
        $booking = $this->bookings->findByReference($reference) ?? throw BookingNotFound::withReference($reference);

        return BookingResponse::fromBooking($booking);
    }
}
```

- [ ] **Step 4: Ejecutar → pasan** (`make test-unit`, `make stan`).

- [ ] **Step 5: Commit**

```bash
git add src/Booking/Application tests/Unit/Booking/Application
git commit -m "feat(booking): book, cancel and find use cases under a session row lock

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 14: Booking — persistencia Doctrine y generador de referencias

**Files:**
- Create: `src/Booking/Infrastructure/Persistence/Doctrine/Type/BookingIdType.php`, `UserIdType.php`, `BookingReferenceType.php`, `SeatsType.php`
- Create: `src/Booking/Infrastructure/Persistence/Doctrine/DoctrineBookingRepository.php`
- Create: `src/Booking/Infrastructure/Reference/RandomBookingReferenceGenerator.php`
- Create: `config/doctrine/Booking/Booking.orm.xml`
- Create: `migrations/Version20260910120200.php`
- Modify: `config/packages/doctrine.yaml`, `config/services.yaml`
- Test: `tests/Unit/Booking/Infrastructure/RandomBookingReferenceGeneratorTest.php`, `tests/Integration/Booking/DoctrineBookingRepositoryTest.php`

**Interfaces:**
- Consumes: Task 8, Task 3, Task 11 (tabla `sessions`).
- Produces: `DoctrineBookingRepository implements BookingRepository`; `RandomBookingReferenceGenerator implements BookingReferenceGenerator`; tabla `bookings` (`reference` UNIQUE).

- [ ] **Step 1: Tests**

`tests/Unit/Booking/Infrastructure/RandomBookingReferenceGeneratorTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Booking\Infrastructure;

use App\Booking\Infrastructure\Reference\RandomBookingReferenceGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RandomBookingReferenceGeneratorTest extends TestCase
{
    #[Test]
    public function it_generates_valid_distinct_references(): void
    {
        $generator = new RandomBookingReferenceGenerator();
        $seen = [];

        for ($i = 0; $i < 1000; ++$i) {
            $reference = $generator->next()->value;
            self::assertMatchesRegularExpression('/^BK-[0-9A-HJKMNP-TV-Z]{8}$/', $reference);
            $seen[$reference] = true;
        }

        self::assertCount(1000, $seen);
    }
}
```

`tests/Integration/Booking/DoctrineBookingRepositoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Booking;

use App\Booking\Domain\BookingId;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\BookingRepository;
use App\Booking\Domain\Seats;
use App\Booking\Domain\UserId;
use App\Experience\Domain\ExperienceId;
use App\Experience\Domain\ExperienceRepository;
use App\Session\Domain\Capacity;
use App\Session\Domain\Session;
use App\Session\Domain\SessionId;
use App\Session\Domain\SessionRepository;
use App\Session\Domain\StartsAt;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Money;
use App\Tests\Unit\Experience\Domain\ExperienceTest;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineBookingRepositoryTest extends KernelTestCase
{
    private BookingRepository $bookings;
    private SessionRepository $sessions;
    private EntityManagerInterface $entityManager;
    private Clock $clock;
    private ExperienceId $experienceId;
    private Session $session;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->bookings = $container->get(BookingRepository::class);
        $this->sessions = $container->get(SessionRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->clock = $container->get(Clock::class);

        $experience = ExperienceTest::anExperience();
        $container->get(ExperienceRepository::class)->save($experience);
        $this->experienceId = $experience->id();

        $this->session = Session::schedule(
            SessionId::generate(),
            $this->experienceId,
            StartsAt::fromDateTime(new \DateTimeImmutable('+3 days', new \DateTimeZone('UTC'))),
            Capacity::fromInt(10),
            Money::fromPrimitives(1500, 'EUR'),
            $this->clock,
        );
        $this->sessions->save($this->session);
    }

    #[Test]
    public function it_persists_rehydrates_and_finds_by_reference(): void
    {
        $booking = $this->session->book(BookingId::generate(), BookingReference::fromString('BK-7F3A2C9K'), UserId::generate(), Seats::fromInt(2), $this->clock);
        $this->sessions->save($this->session);
        $this->bookings->save($booking);
        $this->entityManager->clear();

        $found = $this->bookings->findByReference(BookingReference::fromString('BK-7F3A2C9K'));
        self::assertNotNull($found);
        self::assertSame(2, $found->seats()->value);
        self::assertSame(3000, $found->totalPrice()->amount);
        self::assertSame('confirmed', $found->status()->value);
        self::assertTrue($this->bookings->existsByReference(BookingReference::fromString('BK-7F3A2C9K')));
        self::assertFalse($this->bookings->existsByReference(BookingReference::fromString('BK-00000000')));
    }

    #[Test]
    public function it_detects_confirmed_bookings_for_an_experience(): void
    {
        self::assertFalse($this->bookings->existsConfirmedForExperience($this->experienceId));

        $booking = $this->session->book(BookingId::generate(), BookingReference::fromString('BK-7F3A2C9K'), UserId::generate(), Seats::fromInt(1), $this->clock);
        $this->sessions->save($this->session);
        $this->bookings->save($booking);
        self::assertTrue($this->bookings->existsConfirmedForExperience($this->experienceId));

        $this->session->cancelBooking($booking, $this->clock);
        $this->sessions->save($this->session);
        $this->bookings->save($booking);
        self::assertFalse($this->bookings->existsConfirmedForExperience($this->experienceId));
    }
}
```

- [ ] **Step 2: Ejecutar → falla** (`make test`).

- [ ] **Step 3: Implementación**

Types (`src/Booking/Infrastructure/Persistence/Doctrine/Type/`): `BookingIdType`, `UserIdType` extienden `UuidType` (como `ExperienceIdType`); `SeatsType` extiende `IntValueObjectType<Seats>` (como `CapacityType`); `BookingReferenceType`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Persistence\Doctrine\Type;

use App\Booking\Domain\BookingReference;
use App\Shared\Infrastructure\Doctrine\Type\StringValueObjectType;

/** @extends StringValueObjectType<BookingReference> */
final class BookingReferenceType extends StringValueObjectType
{
    protected static function valueObjectClass(): string
    {
        return BookingReference::class;
    }
}
```

`config/doctrine/Booking/Booking.orm.xml`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping
                                      https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd">
  <entity name="App\Booking\Domain\Booking" table="bookings">
    <id name="id" type="booking_id" column="id"/>
    <field name="reference" type="booking_reference" column="reference" length="11" unique="true"/>
    <field name="sessionId" type="session_id" column="session_id"/>
    <field name="userId" type="user_id" column="user_id"/>
    <field name="seats" type="booking_seats" column="seats"/>
    <field name="status" type="string" column="status" length="16" enum-type="App\Booking\Domain\BookingStatus"/>
    <field name="bookedAt" type="datetimetz_immutable" column="booked_at"/>
    <field name="cancelledAt" type="datetimetz_immutable" column="cancelled_at" nullable="true"/>
    <embedded name="totalPrice" class="App\Shared\Domain\Money" column-prefix="total_"/>
  </entity>
</doctrine-mapping>
```

`config/packages/doctrine.yaml` → añade bajo `types`:
```yaml
      booking_id: App\Booking\Infrastructure\Persistence\Doctrine\Type\BookingIdType
      user_id: App\Booking\Infrastructure\Persistence\Doctrine\Type\UserIdType
      booking_reference: App\Booking\Infrastructure\Persistence\Doctrine\Type\BookingReferenceType
      booking_seats: App\Booking\Infrastructure\Persistence\Doctrine\Type\SeatsType
```

`src/Booking/Infrastructure/Persistence/Doctrine/DoctrineBookingRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Persistence\Doctrine;

use App\Booking\Domain\Booking;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\BookingRepository;
use App\Booking\Domain\BookingStatus;
use App\Experience\Domain\ExperienceId;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineBookingRepository implements BookingRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Booking $booking): void
    {
        $this->entityManager->persist($booking);
        $this->entityManager->flush();
    }

    public function findByReference(BookingReference $reference): ?Booking
    {
        return $this->entityManager->getRepository(Booking::class)->findOneBy(['reference' => $reference->value]);
    }

    public function existsByReference(BookingReference $reference): bool
    {
        $count = $this->entityManager->createQuery(
            'SELECT COUNT(b.id) FROM App\Booking\Domain\Booking b WHERE b.reference = :reference'
        )->setParameter('reference', $reference->value)->getSingleScalarResult();

        return (int) $count > 0;
    }

    public function existsConfirmedForExperience(ExperienceId $experienceId): bool
    {
        $count = $this->entityManager->createQuery(
            'SELECT COUNT(b.id) FROM App\Booking\Domain\Booking b
             JOIN App\Session\Domain\Session s WITH s.id = b.sessionId
             WHERE s.experienceId = :experienceId AND b.status = :status'
        )
            ->setParameter('experienceId', $experienceId->value)
            ->setParameter('status', BookingStatus::Confirmed->value)
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
```

`src/Booking/Infrastructure/Reference/RandomBookingReferenceGenerator.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Reference;

use App\Booking\Domain\BookingReference;
use App\Booking\Domain\BookingReferenceGenerator;

/** 8 random Crockford base32 chars (~1.1e12 combinations); uniqueness is enforced by the DB index. */
final class RandomBookingReferenceGenerator implements BookingReferenceGenerator
{
    public function next(): BookingReference
    {
        $alphabet = BookingReference::ALPHABET;
        $max = \strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < BookingReference::LENGTH; ++$i) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return BookingReference::fromString(BookingReference::PREFIX.$code);
    }
}
```

`migrations/Version20260910120200.php`:
```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910120200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create bookings table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bookings (
            id UUID NOT NULL,
            reference CHAR(11) NOT NULL,
            session_id UUID NOT NULL,
            user_id UUID NOT NULL,
            seats INT NOT NULL,
            total_amount BIGINT NOT NULL,
            total_currency CHAR(3) NOT NULL,
            status VARCHAR(16) NOT NULL,
            booked_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            cancelled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            PRIMARY KEY (id),
            CONSTRAINT fk_bookings_session FOREIGN KEY (session_id) REFERENCES sessions (id),
            CONSTRAINT chk_bookings_seats CHECK (seats > 0),
            CONSTRAINT chk_bookings_status CHECK (status IN (\'confirmed\', \'cancelled\'))
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_bookings_reference ON bookings (reference)');
        $this->addSql('CREATE INDEX idx_bookings_session_status ON bookings (session_id, status)');
        $this->addSql('CREATE INDEX idx_bookings_user ON bookings (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bookings');
    }
}
```

`config/services.yaml` → añade:
```yaml
  App\Booking\Domain\BookingRepository: '@App\Booking\Infrastructure\Persistence\Doctrine\DoctrineBookingRepository'
  App\Booking\Domain\BookingReferenceGenerator: '@App\Booking\Infrastructure\Reference\RandomBookingReferenceGenerator'
```

- [ ] **Step 4: Migrar y ejecutar** (`make migrate && make test`, `make stan`).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(booking): Doctrine repository, random reference generator and migration

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 15: Booking — HTTP

**Files:**
- Create: `src/Booking/Infrastructure/Http/BookSeatsRequest.php`, `BookSeatsController.php`, `FindBookingController.php`, `CancelBookingController.php`
- Test: `tests/Functional/Booking/BookingApiTest.php`

**Interfaces:**
- Consumes: Task 13.
- Produces: `POST /api/sessions/{id}/bookings` → 201 + `Location: /api/bookings/{reference}`; `GET /api/bookings/{reference}` (ruta `api_bookings_find`); `POST /api/bookings/{reference}/cancellation` → 200.

- [ ] **Step 1: Test funcional**

`tests/Functional/Booking/BookingApiTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Booking;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BookingApiTest extends WebTestCase
{
    private const string USER = '0192b3a4-1234-7abc-8def-0123456789ad';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function it_books_fetches_and_cancels(): void
    {
        $sessionId = $this->aSession('+3 days', capacity: 5);

        $this->client->jsonRequest('POST', "/api/sessions/{$sessionId}/bookings", ['userId' => self::USER, 'seats' => 2]);
        self::assertResponseStatusCodeSame(201);
        $booking = $this->json();
        self::assertMatchesRegularExpression('/^BK-[0-9A-HJKMNP-TV-Z]{8}$/', $booking['reference']);
        self::assertSame('confirmed', $booking['status']);
        self::assertSame(['amount' => 3000, 'currency' => 'EUR'], $booking['total']);
        self::assertResponseHeaderSame('Location', '/api/bookings/'.$booking['reference']);

        $this->client->request('GET', '/api/sessions/'.$sessionId);
        self::assertSame(3, $this->json()['availableSeats']);

        $this->client->request('GET', '/api/bookings/'.$booking['reference']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($booking, $this->json());

        $this->client->request('POST', "/api/bookings/{$booking['reference']}/cancellation");
        self::assertResponseStatusCodeSame(200);
        self::assertSame('cancelled', $this->json()['status']);
        self::assertNotNull($this->json()['cancelledAt']);

        $this->client->request('GET', '/api/sessions/'.$sessionId);
        self::assertSame(5, $this->json()['availableSeats']);

        $this->client->request('POST', "/api/bookings/{$booking['reference']}/cancellation");
        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/booking-already-cancelled', $this->json()['type']);
    }

    #[Test]
    public function it_refuses_overbooking(): void
    {
        $sessionId = $this->aSession('+3 days', capacity: 2);

        $this->client->jsonRequest('POST', "/api/sessions/{$sessionId}/bookings", ['userId' => self::USER, 'seats' => 3]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/not-enough-seats-available', $this->json()['type']);
    }

    #[Test]
    public function it_refuses_cancellation_within_24_hours(): void
    {
        $sessionId = $this->aSession('+2 hours', capacity: 2);
        $this->client->jsonRequest('POST', "/api/sessions/{$sessionId}/bookings", ['userId' => self::USER, 'seats' => 1]);
        $reference = $this->json()['reference'];

        $this->client->request('POST', "/api/bookings/{$reference}/cancellation");

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/cancellation-window-closed', $this->json()['type']);
    }

    #[Test]
    public function it_validates_payload_and_reference_format(): void
    {
        $sessionId = $this->aSession('+3 days', capacity: 2);

        $this->client->jsonRequest('POST', "/api/sessions/{$sessionId}/bookings", ['userId' => 'x', 'seats' => 0]);
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/bookings/NOPE');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/bookings/BK-ZZZZZZZZ');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('/problems/booking-not-found', $this->json()['type']);
    }

    private function aSession(string $when, int $capacity): string
    {
        $this->client->jsonRequest('POST', '/api/experiences', ['title' => 'Kayak', 'description' => 'At dawn', 'providerId' => '0192b3a4-1234-7abc-8def-0123456789ac']);
        $experienceId = $this->json()['id'];
        $this->client->jsonRequest('POST', "/api/experiences/{$experienceId}/sessions", [
            'startsAt' => (new \DateTimeImmutable($when))->format(DATE_ATOM),
            'capacity' => $capacity,
            'price' => ['amount' => 1500, 'currency' => 'EUR'],
        ]);
        self::assertResponseStatusCodeSame(201);

        return $this->json()['id'];
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

`src/Booking/Infrastructure/Http/BookSeatsRequest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Http;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class BookSeatsRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $userId,
        #[Assert\Positive]
        public int $seats,
    ) {
    }
}
```

`src/Booking/Infrastructure/Http/BookSeatsController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Http;

use App\Booking\Application\Book\BookSeatsCommand;
use App\Booking\Application\Book\BookSeatsHandler;
use App\Booking\Domain\BookingId;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class BookSeatsController
{
    public function __construct(
        private BookSeatsHandler $handler,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/api/sessions/{sessionId}/bookings', name: 'api_bookings_book', methods: ['POST'], requirements: ['sessionId' => '[0-9a-fA-F-]{36}'])]
    public function __invoke(
        string $sessionId,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        BookSeatsRequest $request,
    ): JsonResponse {
        $response = ($this->handler)(new BookSeatsCommand(
            bookingId: BookingId::generate()->value,
            sessionId: $sessionId,
            userId: $request->userId,
            seats: $request->seats,
        ));

        return new JsonResponse($response, Response::HTTP_CREATED, [
            'Location' => $this->urls->generate('api_bookings_find', ['reference' => $response->reference]),
        ]);
    }
}
```

`src/Booking/Infrastructure/Http/FindBookingController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Http;

use App\Booking\Application\Find\FindBookingHandler;
use App\Booking\Application\Find\FindBookingQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class FindBookingController
{
    public const string REFERENCE_REQUIREMENT = 'BK-[0-9A-Za-z]{8}';

    public function __construct(private FindBookingHandler $handler)
    {
    }

    #[Route('/api/bookings/{reference}', name: 'api_bookings_find', methods: ['GET'], requirements: ['reference' => self::REFERENCE_REQUIREMENT])]
    public function __invoke(string $reference): JsonResponse
    {
        return new JsonResponse(($this->handler)(new FindBookingQuery($reference)));
    }
}
```
Nota: el requirement de ruta es laxo a propósito (`[0-9A-Za-z]{8}`); `BookingReference::fromString` hace la validación estricta y una referencia con letras ambiguas termina en `InvalidValue` → 400, que es lo correcto ("el formato es incorrecto"), mientras que una referencia bien formada pero inexistente da 404.

`src/Booking/Infrastructure/Http/CancelBookingController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Http;

use App\Booking\Application\Cancel\CancelBookingCommand;
use App\Booking\Application\Cancel\CancelBookingHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CancelBookingController
{
    public function __construct(private CancelBookingHandler $handler)
    {
    }

    #[Route('/api/bookings/{reference}/cancellation', name: 'api_bookings_cancel', methods: ['POST'], requirements: ['reference' => FindBookingController::REFERENCE_REQUIREMENT])]
    public function __invoke(string $reference): JsonResponse
    {
        return new JsonResponse(($this->handler)(new CancelBookingCommand($reference)));
    }
}
```

- [ ] **Step 4: Ejecutar → pasan**

```bash
make test
make stan
make cs
```
Prueba manual rápida:
```bash
curl -s -X POST http://localhost:8080/api/sessions/<SESSION_ID>/bookings -H 'Content-Type: application/json' -d '{"userId":"0192b3a4-1234-7abc-8def-0123456789ad","seats":2}' -i
```

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(booking): REST endpoints to book, fetch and cancel bookings

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```
