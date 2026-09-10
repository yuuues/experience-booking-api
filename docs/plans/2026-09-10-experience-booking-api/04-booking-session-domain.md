# Fase 4 — Dominio de Booking y de Session

Requiere Fase 2 (y Fase 3 para `ExperienceId`). Aquí viven todas las reglas de negocio de reservas y sesiones; se prueban en unitario con `FixedClock`. Nota de dependencia: `Session::book()` fabrica `Booking`, y `Booking` referencia `SessionId` y `Seats`; por eso `SessionId` se crea en la Task 8 aunque pertenezca a `Session\Domain`.

---

### Task 8: Booking — dominio

**Files:**
- Create: `src/Session/Domain/SessionId.php`
- Create: `src/Booking/Domain/BookingId.php`, `UserId.php`, `Seats.php`, `BookingReference.php`, `BookingReferenceGenerator.php`, `BookingStatus.php`, `Booking.php`, `BookingRepository.php`
- Create: `src/Booking/Domain/Event/BookingConfirmed.php`, `BookingCancelled.php`
- Create: `src/Booking/Domain/Exception/BookingNotFound.php`, `BookingAlreadyCancelled.php`
- Create: `tests/Doubles/Booking/InMemoryBookingRepository.php`, `SequentialBookingReferenceGenerator.php`
- Test: `tests/Unit/Booking/Domain/BookingTest.php`, `BookingReferenceTest.php`, `SeatsTest.php`

**Interfaces:**
- Consumes: `Shared\Domain\*`, `Experience\Domain\ExperienceId`.
- Produces: ver `00-overview.md` → `Booking\Domain`. Además `Seats::fromInt(int)` (`public int $value`), `BookingReference::fromString(string)` (`public string $value`, patrón `^BK-[0-9A-HJKMNP-TV-Z]{8}$`), `BookingStatus` enum string `Confirmed='confirmed'`, `Cancelled='cancelled'`. Eventos con props públicas: `BookingConfirmed(reference, bookingId, sessionId, userId, seats, totalAmount, totalCurrency)`, `BookingCancelled(reference, bookingId, sessionId, userId, seats)`.

- [ ] **Step 1: Tests**

`tests/Unit/Booking/Domain/SeatsTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Booking\Domain;

use App\Booking\Domain\Seats;
use App\Shared\Domain\InvalidValue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SeatsTest extends TestCase
{
    #[Test]
    public function it_requires_at_least_one_seat(): void
    {
        self::assertSame(1, Seats::fromInt(1)->value);

        $this->expectException(InvalidValue::class);
        Seats::fromInt(0);
    }
}
```

`tests/Unit/Booking/Domain/BookingReferenceTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Booking\Domain;

use App\Booking\Domain\BookingReference;
use App\Shared\Domain\InvalidValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BookingReferenceTest extends TestCase
{
    #[Test]
    public function it_accepts_crockford_base32_and_uppercases(): void
    {
        self::assertSame('BK-7F3A2C9K', BookingReference::fromString('bk-7f3a2c9k')->value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'wrong prefix' => ['XX-7F3A2C9K'];
        yield 'too short' => ['BK-7F3A2C'];
        yield 'ambiguous letter I' => ['BK-7F3A2CIK'];
        yield 'ambiguous letter O' => ['BK-7F3A2COK'];
        yield 'letter U' => ['BK-7F3A2CUK'];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function it_rejects_invalid(string $value): void
    {
        $this->expectException(InvalidValue::class);

        BookingReference::fromString($value);
    }
}
```

`tests/Unit/Booking/Domain/BookingTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Booking\Domain;

use App\Booking\Domain\Booking;
use App\Booking\Domain\BookingId;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\BookingStatus;
use App\Booking\Domain\Event\BookingCancelled;
use App\Booking\Domain\Event\BookingConfirmed;
use App\Booking\Domain\Exception\BookingAlreadyCancelled;
use App\Booking\Domain\Seats;
use App\Booking\Domain\UserId;
use App\Session\Domain\SessionId;
use App\Shared\Domain\Money;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BookingTest extends TestCase
{
    #[Test]
    public function it_is_confirmed_on_creation_and_records_event(): void
    {
        $booking = self::aBooking();

        self::assertSame(BookingStatus::Confirmed, $booking->status());
        self::assertFalse($booking->isCancelled());
        self::assertNull($booking->cancelledAt());
        self::assertSame(3000, $booking->totalPrice()->amount);

        $events = $booking->pullDomainEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(BookingConfirmed::class, $events[0]);
        self::assertSame('BK-00000001', $events[0]->reference);
        self::assertSame(2, $events[0]->seats);
        self::assertSame(3000, $events[0]->totalAmount);
    }

    #[Test]
    public function it_cancels_once(): void
    {
        $booking = self::aBooking();
        $booking->pullDomainEvents();
        $at = new DateTimeImmutable('2026-10-02T09:00:00+00:00');

        $booking->cancel($at);

        self::assertTrue($booking->isCancelled());
        self::assertEquals($at, $booking->cancelledAt());
        self::assertInstanceOf(BookingCancelled::class, $booking->pullDomainEvents()[0]);

        $this->expectException(BookingAlreadyCancelled::class);
        $booking->cancel($at);
    }

    public static function aBooking(): Booking
    {
        return Booking::confirm(
            BookingId::generate(),
            BookingReference::fromString('BK-00000001'),
            SessionId::generate(),
            UserId::generate(),
            Seats::fromInt(2),
            Money::fromPrimitives(3000, 'EUR'),
            new DateTimeImmutable('2026-10-01T10:00:00+00:00'),
        );
    }
}
```

- [ ] **Step 2: Ejecutar → falla** (`make test-unit`).

- [ ] **Step 3: Implementación**

`src/Session/Domain/SessionId.php`:
```php
<?php

declare(strict_types=1);

namespace App\Session\Domain;

use App\Shared\Domain\Uuid;

final class SessionId extends Uuid
{
}
```

`src/Booking/Domain/BookingId.php` y `UserId.php`: subclases vacías de `Uuid` (namespace `App\Booking\Domain`). `UserId` con docblock "Reference to an external user; not modelled here."

`src/Booking/Domain/Seats.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Domain;

use App\Shared\Domain\InvalidValue;

final readonly class Seats
{
    private function __construct(public int $value)
    {
    }

    public static function fromInt(int $value): self
    {
        if ($value < 1) {
            throw new InvalidValue('A booking needs at least one seat.');
        }

        return new self($value);
    }
}
```

`src/Booking/Domain/BookingReference.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Domain;

use App\Shared\Domain\InvalidValue;

/** Human-friendly public identifier: BK- + 8 Crockford base32 chars (no I, L, O, U). */
final readonly class BookingReference
{
    public const string PREFIX = 'BK-';
    public const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    public const int LENGTH = 8;
    private const string PATTERN = '/^BK-[0-9A-HJKMNP-TV-Z]{8}$/';

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = strtoupper(trim($value));
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new InvalidValue(sprintf('<%s> is not a valid booking reference.', $value));
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
```

`src/Booking/Domain/BookingReferenceGenerator.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Domain;

interface BookingReferenceGenerator
{
    public function next(): BookingReference;
}
```

`src/Booking/Domain/BookingStatus.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Domain;

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
}
```

`src/Booking/Domain/Event/BookingConfirmed.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Domain\Event;

use App\Shared\Domain\DomainEvent;
use DateTimeImmutable;

final readonly class BookingConfirmed implements DomainEvent
{
    public function __construct(
        public string $reference,
        public string $bookingId,
        public string $sessionId,
        public string $userId,
        public int $seats,
        public int $totalAmount,
        public string $totalCurrency,
        private DateTimeImmutable $occurredOn,
    ) {
    }

    public function aggregateId(): string
    {
        return $this->bookingId;
    }

    public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public static function eventName(): string
    {
        return 'booking.confirmed';
    }
}
```

`src/Booking/Domain/Event/BookingCancelled.php`: misma forma con props `reference, bookingId, sessionId, userId, seats` + `occurredOn`; `eventName()` → `'booking.cancelled'`.

`src/Booking/Domain/Exception/BookingNotFound.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Domain\Exception;

use App\Booking\Domain\BookingReference;
use App\Shared\Domain\NotFoundException;

final class BookingNotFound extends NotFoundException
{
    public static function withReference(BookingReference $reference): self
    {
        return new self(sprintf('Booking <%s> not found.', $reference->value));
    }

    public function errorCode(): string
    {
        return 'booking-not-found';
    }
}
```

`src/Booking/Domain/Exception/BookingAlreadyCancelled.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Domain\Exception;

use App\Booking\Domain\BookingReference;
use App\Shared\Domain\DomainException;

final class BookingAlreadyCancelled extends DomainException
{
    public static function withReference(BookingReference $reference): self
    {
        return new self(sprintf('Booking <%s> is already cancelled.', $reference->value));
    }

    public function errorCode(): string
    {
        return 'booking-already-cancelled';
    }
}
```

`src/Booking/Domain/Booking.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Domain;

use App\Booking\Domain\Event\BookingCancelled;
use App\Booking\Domain\Event\BookingConfirmed;
use App\Booking\Domain\Exception\BookingAlreadyCancelled;
use App\Session\Domain\SessionId;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Money;
use DateTimeImmutable;

final class Booking extends AggregateRoot
{
    private BookingStatus $status;
    private ?DateTimeImmutable $cancelledAt = null;

    private function __construct(
        private readonly BookingId $id,
        private readonly BookingReference $reference,
        private readonly SessionId $sessionId,
        private readonly UserId $userId,
        private readonly Seats $seats,
        private readonly Money $totalPrice,
        private readonly DateTimeImmutable $bookedAt,
    ) {
        $this->status = BookingStatus::Confirmed;
    }

    /**
     * @internal Only Session::book() may create bookings: the session owns the seat count and the price.
     */
    public static function confirm(
        BookingId $id,
        BookingReference $reference,
        SessionId $sessionId,
        UserId $userId,
        Seats $seats,
        Money $totalPrice,
        DateTimeImmutable $bookedAt,
    ): self {
        $booking = new self($id, $reference, $sessionId, $userId, $seats, $totalPrice, $bookedAt);
        $booking->record(new BookingConfirmed(
            $reference->value,
            $id->value,
            $sessionId->value,
            $userId->value,
            $seats->value,
            $totalPrice->amount,
            $totalPrice->currency,
            $bookedAt,
        ));

        return $booking;
    }

    /** @internal Only Session::cancelBooking() may cancel: the session enforces the time window and releases seats. */
    public function cancel(DateTimeImmutable $at): void
    {
        if ($this->isCancelled()) {
            throw BookingAlreadyCancelled::withReference($this->reference);
        }

        $this->status = BookingStatus::Cancelled;
        $this->cancelledAt = $at;
        $this->record(new BookingCancelled(
            $this->reference->value,
            $this->id->value,
            $this->sessionId->value,
            $this->userId->value,
            $this->seats->value,
            $at,
        ));
    }

    public function isCancelled(): bool
    {
        return BookingStatus::Cancelled === $this->status;
    }

    public function id(): BookingId
    {
        return $this->id;
    }

    public function reference(): BookingReference
    {
        return $this->reference;
    }

    public function sessionId(): SessionId
    {
        return $this->sessionId;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function seats(): Seats
    {
        return $this->seats;
    }

    public function totalPrice(): Money
    {
        return $this->totalPrice;
    }

    public function status(): BookingStatus
    {
        return $this->status;
    }

    public function bookedAt(): DateTimeImmutable
    {
        return $this->bookedAt;
    }

    public function cancelledAt(): ?DateTimeImmutable
    {
        return $this->cancelledAt;
    }
}
```

`src/Booking/Domain/BookingRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Domain;

use App\Experience\Domain\ExperienceId;

interface BookingRepository
{
    public function save(Booking $booking): void;

    public function findByReference(BookingReference $reference): ?Booking;

    public function existsByReference(BookingReference $reference): bool;

    /** True when any session of the experience has at least one confirmed booking. */
    public function existsConfirmedForExperience(ExperienceId $experienceId): bool;
}
```

`tests/Doubles/Booking/InMemoryBookingRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Doubles\Booking;

use App\Booking\Domain\Booking;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\BookingRepository;
use App\Experience\Domain\ExperienceId;
use App\Session\Domain\SessionRepository;

final class InMemoryBookingRepository implements BookingRepository
{
    /** @var array<string, Booking> keyed by reference */
    private array $items = [];

    public function __construct(private readonly ?SessionRepository $sessions = null)
    {
    }

    public function save(Booking $booking): void
    {
        $this->items[$booking->reference()->value] = $booking;
    }

    public function findByReference(BookingReference $reference): ?Booking
    {
        return $this->items[$reference->value] ?? null;
    }

    public function existsByReference(BookingReference $reference): bool
    {
        return isset($this->items[$reference->value]);
    }

    public function existsConfirmedForExperience(ExperienceId $experienceId): bool
    {
        foreach ($this->items as $booking) {
            if ($booking->isCancelled()) {
                continue;
            }
            $session = $this->sessions?->find($booking->sessionId());
            if (null !== $session && $session->experienceId()->equals($experienceId)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Booking> */
    public function all(): array
    {
        return array_values($this->items);
    }
}
```
(`SessionRepository` se define en la Task 9; el double compila igualmente porque solo lo tipa. Si ejecutas los tests antes de la Task 9, PHP no carga la interfaz hasta instanciar con ella, así que no rompe.)

`tests/Doubles/Booking/SequentialBookingReferenceGenerator.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Doubles\Booking;

use App\Booking\Domain\BookingReference;
use App\Booking\Domain\BookingReferenceGenerator;

final class SequentialBookingReferenceGenerator implements BookingReferenceGenerator
{
    private int $counter = 0;

    public function next(): BookingReference
    {
        ++$this->counter;

        return BookingReference::fromString(sprintf('BK-%08d', $this->counter));
    }
}
```

- [ ] **Step 4: Ejecutar → pasan** (`make test-unit`).

- [ ] **Step 5: Commit**

```bash
git add src/Session/Domain/SessionId.php src/Booking/Domain tests/Unit/Booking tests/Doubles/Booking
git commit -m "feat(booking): booking aggregate, reference and status

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 9: Session — dominio (aforo, ventanas temporales)

**Files:**
- Create: `src/Session/Domain/StartsAt.php`, `SessionDay.php`, `Capacity.php`, `Session.php`, `SessionRepository.php`
- Create: `src/Session/Domain/Event/SessionScheduled.php`
- Create: `src/Session/Domain/Exception/SessionNotFound.php`, `SessionInThePast.php`, `SessionAlreadyStarted.php`, `SessionAlreadyScheduledForDay.php`, `NotEnoughSeatsAvailable.php`, `CancellationWindowClosed.php`, `BookingDoesNotBelongToSession.php`
- Create: `tests/Doubles/Session/InMemorySessionRepository.php`
- Test: `tests/Unit/Session/Domain/StartsAtTest.php`, `SessionTest.php`

**Interfaces:**
- Consumes: Task 8, `Shared\Domain\{Clock, Money}`, `Experience\Domain\ExperienceId`.
- Produces: `StartsAt::fromString(string)`, `StartsAt::fromDateTime(DateTimeImmutable)`, `public DateTimeImmutable $value` (UTC), `dayIn(DateTimeZone): SessionDay`, `isAtOrBefore(DateTimeImmutable): bool`, `isWithinHoursBefore(DateTimeImmutable, int): bool`, `toAtom(): string`; `SessionDay::fromString('Y-m-d')` (`public string $value`); `Capacity::fromInt(int)` (`public int $value`, ≥1); `Session` (ver overview); `SessionRepository { save; find; findForUpdate; existsForExperienceOn }`.

- [ ] **Step 1: Tests**

`tests/Unit/Session/Domain/StartsAtTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Domain;

use App\Session\Domain\StartsAt;
use App\Shared\Domain\InvalidValue;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StartsAtTest extends TestCase
{
    #[Test]
    public function it_normalizes_to_utc(): void
    {
        $startsAt = StartsAt::fromString('2026-10-01T10:00:00+02:00');

        self::assertSame('2026-10-01T08:00:00+00:00', $startsAt->toAtom());
    }

    #[Test]
    public function day_depends_on_platform_time_zone(): void
    {
        $startsAt = StartsAt::fromString('2026-10-01T23:30:00+00:00');

        self::assertSame('2026-10-02', $startsAt->dayIn(new DateTimeZone('Europe/Madrid'))->value);
        self::assertSame('2026-10-01', $startsAt->dayIn(new DateTimeZone('UTC'))->value);
    }

    #[Test]
    public function it_knows_the_cancellation_window(): void
    {
        $startsAt = StartsAt::fromString('2026-10-10T10:00:00+00:00');

        self::assertFalse($startsAt->isWithinHoursBefore(new DateTimeImmutable('2026-10-09T09:59:59+00:00'), 24));
        self::assertTrue($startsAt->isWithinHoursBefore(new DateTimeImmutable('2026-10-09T10:00:01+00:00'), 24));
        self::assertTrue($startsAt->isWithinHoursBefore(new DateTimeImmutable('2026-10-11T00:00:00+00:00'), 24));
    }

    #[Test]
    public function it_rejects_garbage(): void
    {
        $this->expectException(InvalidValue::class);

        StartsAt::fromString('next tuesday-ish');
    }
}
```

`tests/Unit/Session/Domain/SessionTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Domain;

use App\Booking\Domain\BookingId;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\Exception\BookingAlreadyCancelled;
use App\Booking\Domain\Seats;
use App\Booking\Domain\UserId;
use App\Experience\Domain\ExperienceId;
use App\Session\Domain\Capacity;
use App\Session\Domain\Event\SessionScheduled;
use App\Session\Domain\Exception\BookingDoesNotBelongToSession;
use App\Session\Domain\Exception\CancellationWindowClosed;
use App\Session\Domain\Exception\NotEnoughSeatsAvailable;
use App\Session\Domain\Exception\SessionAlreadyStarted;
use App\Session\Domain\Exception\SessionInThePast;
use App\Session\Domain\Session;
use App\Session\Domain\SessionId;
use App\Session\Domain\StartsAt;
use App\Shared\Domain\Money;
use App\Tests\Doubles\Shared\FixedClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2026-10-01T10:00:00+00:00');
    }

    #[Test]
    public function it_schedules_a_future_session_and_derives_the_day(): void
    {
        $session = self::aSession($this->clock, startsAt: '2026-10-05T23:30:00+00:00', capacity: 10);

        self::assertSame(10, $session->availableSeats());
        self::assertSame(0, $session->bookedSeats());
        self::assertSame('2026-10-06', $session->day()->value); // Europe/Madrid is UTC+2 in October
        self::assertInstanceOf(SessionScheduled::class, $session->pullDomainEvents()[0]);
    }

    #[Test]
    public function it_rejects_sessions_in_the_past_or_now(): void
    {
        $this->expectException(SessionInThePast::class);

        self::aSession($this->clock, startsAt: '2026-10-01T10:00:00+00:00');
    }

    #[Test]
    public function it_books_seats_and_computes_total(): void
    {
        $session = self::aSession($this->clock, capacity: 10, priceAmount: 1500);

        $booking = $session->book(BookingId::generate(), BookingReference::fromString('BK-00000001'), UserId::generate(), Seats::fromInt(3), $this->clock);

        self::assertSame(7, $session->availableSeats());
        self::assertSame(3, $session->bookedSeats());
        self::assertSame(4500, $booking->totalPrice()->amount);
        self::assertSame('EUR', $booking->totalPrice()->currency);
        self::assertTrue($booking->sessionId()->equals($session->id()));
        self::assertEquals($this->clock->now(), $booking->bookedAt());
    }

    #[Test]
    public function it_refuses_to_overbook(): void
    {
        $session = self::aSession($this->clock, capacity: 3);
        $session->book(BookingId::generate(), BookingReference::fromString('BK-00000001'), UserId::generate(), Seats::fromInt(2), $this->clock);

        $this->expectException(NotEnoughSeatsAvailable::class);

        $session->book(BookingId::generate(), BookingReference::fromString('BK-00000002'), UserId::generate(), Seats::fromInt(2), $this->clock);
    }

    #[Test]
    public function it_refuses_booking_once_started(): void
    {
        $session = self::aSession($this->clock, startsAt: '2026-10-05T10:00:00+00:00');
        $this->clock->travelTo('2026-10-05T10:00:00+00:00');

        $this->expectException(SessionAlreadyStarted::class);

        $session->book(BookingId::generate(), BookingReference::fromString('BK-00000001'), UserId::generate(), Seats::fromInt(1), $this->clock);
    }

    #[Test]
    public function cancelling_releases_seats(): void
    {
        $session = self::aSession($this->clock, startsAt: '2026-10-05T10:00:00+00:00', capacity: 5);
        $booking = $session->book(BookingId::generate(), BookingReference::fromString('BK-00000001'), UserId::generate(), Seats::fromInt(2), $this->clock);
        $this->clock->travelTo('2026-10-04T09:59:00+00:00'); // 24h + 1 min before

        $session->cancelBooking($booking, $this->clock);

        self::assertTrue($booking->isCancelled());
        self::assertSame(5, $session->availableSeats());
    }

    #[Test]
    public function it_refuses_cancellation_within_24_hours(): void
    {
        $session = self::aSession($this->clock, startsAt: '2026-10-05T10:00:00+00:00');
        $booking = $session->book(BookingId::generate(), BookingReference::fromString('BK-00000001'), UserId::generate(), Seats::fromInt(1), $this->clock);
        $this->clock->travelTo('2026-10-04T10:00:01+00:00');

        $this->expectException(CancellationWindowClosed::class);

        $session->cancelBooking($booking, $this->clock);
    }

    #[Test]
    public function it_refuses_double_cancellation_before_checking_the_window(): void
    {
        $session = self::aSession($this->clock, startsAt: '2026-10-05T10:00:00+00:00');
        $booking = $session->book(BookingId::generate(), BookingReference::fromString('BK-00000001'), UserId::generate(), Seats::fromInt(1), $this->clock);
        $session->cancelBooking($booking, $this->clock);
        $this->clock->travelTo('2026-10-05T09:00:00+00:00');

        $this->expectException(BookingAlreadyCancelled::class);

        $session->cancelBooking($booking, $this->clock);
    }

    #[Test]
    public function it_refuses_bookings_of_other_sessions(): void
    {
        $session = self::aSession($this->clock);
        $other = self::aSession($this->clock, startsAt: '2026-10-07T10:00:00+00:00');
        $booking = $other->book(BookingId::generate(), BookingReference::fromString('BK-00000001'), UserId::generate(), Seats::fromInt(1), $this->clock);

        $this->expectException(BookingDoesNotBelongToSession::class);

        $session->cancelBooking($booking, $this->clock);
    }

    public static function aSession(FixedClock $clock, string $startsAt = '2026-10-05T10:00:00+00:00', int $capacity = 10, int $priceAmount = 1000): Session
    {
        return Session::schedule(
            SessionId::generate(),
            ExperienceId::generate(),
            StartsAt::fromString($startsAt),
            Capacity::fromInt($capacity),
            Money::fromPrimitives($priceAmount, 'EUR'),
            $clock,
        );
    }
}
```

- [ ] **Step 2: Ejecutar → falla** (`make test-unit`).

- [ ] **Step 3: Implementación**

`src/Session/Domain/StartsAt.php`:
```php
<?php

declare(strict_types=1);

namespace App\Session\Domain;

use App\Shared\Domain\InvalidValue;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

final readonly class StartsAt
{
    /** @param DateTimeImmutable $value always UTC */
    private function __construct(public DateTimeImmutable $value)
    {
    }

    public static function fromString(string $iso8601): self
    {
        try {
            $value = new DateTimeImmutable($iso8601);
        } catch (Exception) {
            throw new InvalidValue(sprintf('<%s> is not a valid date-time.', $iso8601));
        }

        return self::fromDateTime($value);
    }

    public static function fromDateTime(DateTimeImmutable $value): self
    {
        return new self($value->setTimezone(new DateTimeZone('UTC')));
    }

    public function dayIn(DateTimeZone $timeZone): SessionDay
    {
        return SessionDay::fromString($this->value->setTimezone($timeZone)->format('Y-m-d'));
    }

    public function isAtOrBefore(DateTimeImmutable $moment): bool
    {
        return $this->value <= $moment;
    }

    /** True when `moment` is later than `hours` before the start (i.e. inside the closing window, or after start). */
    public function isWithinHoursBefore(DateTimeImmutable $moment, int $hours): bool
    {
        return $moment > $this->value->sub(new DateInterval(sprintf('PT%dH', $hours)));
    }

    public function toAtom(): string
    {
        return $this->value->format(DATE_ATOM);
    }
}
```

`src/Session/Domain/SessionDay.php`:
```php
<?php

declare(strict_types=1);

namespace App\Session\Domain;

use App\Shared\Domain\InvalidValue;

/** Calendar day (Y-m-d) in the platform time zone; used for the one-session-per-day rule. */
final readonly class SessionDay
{
    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || false === strtotime($value)) {
            throw new InvalidValue(sprintf('<%s> is not a valid day.', $value));
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
```

`src/Session/Domain/Capacity.php`:
```php
<?php

declare(strict_types=1);

namespace App\Session\Domain;

use App\Shared\Domain\InvalidValue;

final readonly class Capacity
{
    private function __construct(public int $value)
    {
    }

    public static function fromInt(int $value): self
    {
        if ($value < 1) {
            throw new InvalidValue('Capacity must be at least 1.');
        }

        return new self($value);
    }
}
```

Excepciones (`src/Session/Domain/Exception/`), todas con el mismo patrón; cada una `final class X extends DomainException` (o `NotFoundException`) con constructor estático y `errorCode()`:

```php
// SessionNotFound extends NotFoundException
public static function withId(SessionId $id): self { return new self(sprintf('Session <%s> not found.', $id->value)); }
errorCode: 'session-not-found'

// SessionInThePast extends DomainException
public static function at(StartsAt $startsAt): self { return new self(sprintf('Cannot schedule a session at %s: it is in the past.', $startsAt->toAtom())); }
errorCode: 'session-in-the-past'

// SessionAlreadyStarted extends DomainException
public static function withId(SessionId $id): self { return new self(sprintf('Session <%s> has already started.', $id->value)); }
errorCode: 'session-already-started'

// SessionAlreadyScheduledForDay extends DomainException
public static function on(ExperienceId $experienceId, SessionDay $day): self { return new self(sprintf('Experience <%s> already has a session on %s.', $experienceId->value, $day->value)); }
errorCode: 'session-already-scheduled-for-day'

// NotEnoughSeatsAvailable extends DomainException
public static function for(SessionId $id, int $requested, int $available): self { return new self(sprintf('Session <%s> has %d seats available, %d requested.', $id->value, $available, $requested)); }
errorCode: 'not-enough-seats-available'

// CancellationWindowClosed extends DomainException
public static function for(BookingReference $reference, StartsAt $startsAt): self { return new self(sprintf('Booking <%s> cannot be cancelled: less than 24 hours before the session start (%s).', $reference->value, $startsAt->toAtom())); }
errorCode: 'cancellation-window-closed'

// BookingDoesNotBelongToSession extends DomainException
public static function for(BookingReference $reference, SessionId $sessionId): self { return new self(sprintf('Booking <%s> does not belong to session <%s>.', $reference->value, $sessionId->value)); }
errorCode: 'booking-does-not-belong-to-session'
```
Escribe cada fichero completo (namespace `App\Session\Domain\Exception`, `declare(strict_types=1)`, imports de `SessionId`, `StartsAt`, `SessionDay`, `ExperienceId`, `BookingReference`, `DomainException`/`NotFoundException` según corresponda).

`src/Session/Domain/Event/SessionScheduled.php`:
```php
<?php

declare(strict_types=1);

namespace App\Session\Domain\Event;

use App\Shared\Domain\DomainEvent;
use DateTimeImmutable;

final readonly class SessionScheduled implements DomainEvent
{
    public function __construct(
        public string $sessionId,
        public string $experienceId,
        public string $startsAt,
        public int $capacity,
        private DateTimeImmutable $occurredOn,
    ) {
    }

    public function aggregateId(): string
    {
        return $this->sessionId;
    }

    public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public static function eventName(): string
    {
        return 'session.scheduled';
    }
}
```

`src/Session/Domain/Session.php`:
```php
<?php

declare(strict_types=1);

namespace App\Session\Domain;

use App\Booking\Domain\Booking;
use App\Booking\Domain\BookingId;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\Exception\BookingAlreadyCancelled;
use App\Booking\Domain\Seats;
use App\Booking\Domain\UserId;
use App\Experience\Domain\ExperienceId;
use App\Session\Domain\Event\SessionScheduled;
use App\Session\Domain\Exception\BookingDoesNotBelongToSession;
use App\Session\Domain\Exception\CancellationWindowClosed;
use App\Session\Domain\Exception\NotEnoughSeatsAvailable;
use App\Session\Domain\Exception\SessionAlreadyStarted;
use App\Session\Domain\Exception\SessionInThePast;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Money;

/**
 * Guards the seat count. Bookings are created and cancelled through the session so that
 * capacity and the time-window rules live next to the data they depend on.
 */
final class Session extends AggregateRoot
{
    public const int CANCELLATION_WINDOW_HOURS = 24;

    private int $bookedSeats = 0;

    private function __construct(
        private readonly SessionId $id,
        private readonly ExperienceId $experienceId,
        private readonly StartsAt $startsAt,
        private readonly SessionDay $day,
        private readonly Capacity $capacity,
        private readonly Money $price,
    ) {
    }

    public static function schedule(
        SessionId $id,
        ExperienceId $experienceId,
        StartsAt $startsAt,
        Capacity $capacity,
        Money $price,
        Clock $clock,
    ): self {
        if ($startsAt->isAtOrBefore($clock->now())) {
            throw SessionInThePast::at($startsAt);
        }

        $session = new self($id, $experienceId, $startsAt, $startsAt->dayIn($clock->timeZone()), $capacity, $price);
        $session->record(new SessionScheduled($id->value, $experienceId->value, $startsAt->toAtom(), $capacity->value, $clock->now()));

        return $session;
    }

    public function book(BookingId $bookingId, BookingReference $reference, UserId $userId, Seats $seats, Clock $clock): Booking
    {
        $now = $clock->now();
        if ($this->startsAt->isAtOrBefore($now)) {
            throw SessionAlreadyStarted::withId($this->id);
        }
        if ($seats->value > $this->availableSeats()) {
            throw NotEnoughSeatsAvailable::for($this->id, $seats->value, $this->availableSeats());
        }

        $this->bookedSeats += $seats->value;

        return Booking::confirm($bookingId, $reference, $this->id, $userId, $seats, $this->price->multiply($seats->value), $now);
    }

    /**
     * @throws BookingAlreadyCancelled
     * @throws CancellationWindowClosed
     * @throws BookingDoesNotBelongToSession
     */
    public function cancelBooking(Booking $booking, Clock $clock): void
    {
        if (!$booking->sessionId()->equals($this->id)) {
            throw BookingDoesNotBelongToSession::for($booking->reference(), $this->id);
        }
        if ($booking->isCancelled()) {
            throw BookingAlreadyCancelled::withReference($booking->reference());
        }
        $now = $clock->now();
        if ($this->startsAt->isWithinHoursBefore($now, self::CANCELLATION_WINDOW_HOURS)) {
            throw CancellationWindowClosed::for($booking->reference(), $this->startsAt);
        }

        $booking->cancel($now);
        $this->bookedSeats -= $booking->seats()->value;
    }

    public function availableSeats(): int
    {
        return $this->capacity->value - $this->bookedSeats;
    }

    public function id(): SessionId
    {
        return $this->id;
    }

    public function experienceId(): ExperienceId
    {
        return $this->experienceId;
    }

    public function startsAt(): StartsAt
    {
        return $this->startsAt;
    }

    public function day(): SessionDay
    {
        return $this->day;
    }

    public function capacity(): Capacity
    {
        return $this->capacity;
    }

    public function price(): Money
    {
        return $this->price;
    }

    public function bookedSeats(): int
    {
        return $this->bookedSeats;
    }
}
```

`src/Session/Domain/SessionRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Session\Domain;

use App\Experience\Domain\ExperienceId;

interface SessionRepository
{
    public function save(Session $session): void;

    public function find(SessionId $id): ?Session;

    /** Same as find() but takes a row-level write lock; must be called inside a transaction. */
    public function findForUpdate(SessionId $id): ?Session;

    public function existsForExperienceOn(ExperienceId $experienceId, SessionDay $day): bool;
}
```

`tests/Doubles/Session/InMemorySessionRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Doubles\Session;

use App\Experience\Domain\ExperienceId;
use App\Session\Domain\Session;
use App\Session\Domain\SessionDay;
use App\Session\Domain\SessionId;
use App\Session\Domain\SessionRepository;

final class InMemorySessionRepository implements SessionRepository
{
    /** @var array<string, Session> */
    private array $items = [];

    public int $lockedReads = 0;

    public function save(Session $session): void
    {
        $this->items[$session->id()->value] = $session;
    }

    public function find(SessionId $id): ?Session
    {
        return $this->items[$id->value] ?? null;
    }

    public function findForUpdate(SessionId $id): ?Session
    {
        ++$this->lockedReads;

        return $this->find($id);
    }

    public function existsForExperienceOn(ExperienceId $experienceId, SessionDay $day): bool
    {
        foreach ($this->items as $session) {
            if ($session->experienceId()->equals($experienceId) && $session->day()->equals($day)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Ejecutar → pasan** (`make test-unit`; `make stan` limpio).

- [ ] **Step 5: Commit**

```bash
git add src/Session/Domain tests/Unit/Session tests/Doubles/Session
git commit -m "feat(session): session aggregate with capacity and time-window rules

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```
