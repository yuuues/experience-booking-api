# Fase 8 — Editar experiencia (`PUT`)

Requiere Fase 6 (`BookingRepository::existsConfirmedForExperience`). El agregado ya tiene `update()` y `ExperienceEditability` (Fase 3); aquí se añade el caso de uso y el endpoint.

---

### Task 17: UpdateExperience + `PUT /api/experiences/{id}`

**Files:**
- Create: `src/Experience/Application/Update/UpdateExperienceCommand.php`, `UpdateExperienceHandler.php`
- Create: `src/Experience/Infrastructure/Http/UpdateExperienceRequest.php`, `UpdateExperienceController.php`
- Test: `tests/Unit/Experience/Application/UpdateExperienceHandlerTest.php`; añade casos a `tests/Functional/Experience/ExperienceApiTest.php`

**Interfaces:**
- Consumes: `Experience::update`, `ExperienceEditability::fromHasConfirmedBookings(bool)`, `BookingRepository::existsConfirmedForExperience`.
- Produces: `UpdateExperienceCommand(string $id, string $title, string $description)`; `UpdateExperienceHandler::__invoke(...): ExperienceResponse`; `PUT /api/experiences/{id}` → 200 / 404 / 409 / 400.

- [ ] **Step 1: Test unitario**

`tests/Unit/Experience/Application/UpdateExperienceHandlerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Experience\Application;

use App\Booking\Domain\BookingId;
use App\Booking\Domain\BookingReference;
use App\Booking\Domain\Seats;
use App\Booking\Domain\UserId;
use App\Experience\Application\Update\UpdateExperienceCommand;
use App\Experience\Application\Update\UpdateExperienceHandler;
use App\Experience\Domain\Event\ExperienceUpdated;
use App\Experience\Domain\Exception\ExperienceHasBookings;
use App\Experience\Domain\Exception\ExperienceNotFound;
use App\Experience\Domain\Experience;
use App\Session\Domain\Capacity;
use App\Session\Domain\Session;
use App\Session\Domain\SessionId;
use App\Session\Domain\StartsAt;
use App\Shared\Domain\Money;
use App\Tests\Doubles\Booking\InMemoryBookingRepository;
use App\Tests\Doubles\Experience\InMemoryExperienceRepository;
use App\Tests\Doubles\Session\InMemorySessionRepository;
use App\Tests\Doubles\Shared\FixedClock;
use App\Tests\Doubles\Shared\InMemoryDomainEventPublisher;
use App\Tests\Unit\Experience\Domain\ExperienceTest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UpdateExperienceHandlerTest extends TestCase
{
    private FixedClock $clock;
    private InMemoryExperienceRepository $experiences;
    private InMemorySessionRepository $sessions;
    private InMemoryBookingRepository $bookings;
    private InMemoryDomainEventPublisher $events;
    private UpdateExperienceHandler $handler;
    private Experience $experience;

    protected function setUp(): void
    {
        $this->clock = new FixedClock();
        $this->experiences = new InMemoryExperienceRepository();
        $this->sessions = new InMemorySessionRepository();
        $this->bookings = new InMemoryBookingRepository($this->sessions);
        $this->events = new InMemoryDomainEventPublisher();
        $this->handler = new UpdateExperienceHandler($this->experiences, $this->bookings, $this->events);

        $this->experience = ExperienceTest::anExperience();
        $this->experiences->save($this->experience);
    }

    #[Test]
    public function it_updates_when_no_confirmed_bookings(): void
    {
        $response = ($this->handler)(new UpdateExperienceCommand($this->experience->id()->value, 'Kayak at sunset', 'Evening paddle'));

        self::assertSame('Kayak at sunset', $response->title);
        self::assertSame('Evening paddle', $this->experiences->find($this->experience->id())?->description()->value);
        self::assertCount(1, $this->events->publishedOf(ExperienceUpdated::class));
    }

    #[Test]
    public function it_still_updates_when_only_cancelled_bookings_exist(): void
    {
        $session = $this->aSession();
        $booking = $session->book(BookingId::generate(), BookingReference::fromString('BK-00000001'), UserId::generate(), Seats::fromInt(1), $this->clock);
        $session->cancelBooking($booking, $this->clock);
        $this->sessions->save($session);
        $this->bookings->save($booking);

        $response = ($this->handler)(new UpdateExperienceCommand($this->experience->id()->value, 'Renamed', 'Desc'));

        self::assertSame('Renamed', $response->title);
    }

    #[Test]
    public function it_refuses_when_a_confirmed_booking_exists(): void
    {
        $session = $this->aSession();
        $booking = $session->book(BookingId::generate(), BookingReference::fromString('BK-00000001'), UserId::generate(), Seats::fromInt(1), $this->clock);
        $this->sessions->save($session);
        $this->bookings->save($booking);

        $this->expectException(ExperienceHasBookings::class);

        ($this->handler)(new UpdateExperienceCommand($this->experience->id()->value, 'Renamed', 'Desc'));
    }

    #[Test]
    public function it_fails_for_unknown_experience(): void
    {
        $this->expectException(ExperienceNotFound::class);

        ($this->handler)(new UpdateExperienceCommand('0192b3a4-1234-7abc-8def-0123456789ff', 'x', 'y'));
    }

    private function aSession(): Session
    {
        return Session::schedule(SessionId::generate(), $this->experience->id(), StartsAt::fromString('2026-10-05T10:00:00+00:00'), Capacity::fromInt(5), Money::fromPrimitives(1000, 'EUR'), $this->clock);
    }
}
```

- [ ] **Step 2: Ejecutar → falla** (`make test-unit`).

- [ ] **Step 3: Implementación**

`src/Experience/Application/Update/UpdateExperienceCommand.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Application\Update;

final readonly class UpdateExperienceCommand
{
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
    ) {
    }
}
```

`src/Experience/Application/Update/UpdateExperienceHandler.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Application\Update;

use App\Booking\Domain\BookingRepository;
use App\Experience\Application\ExperienceResponse;
use App\Experience\Domain\Description;
use App\Experience\Domain\Exception\ExperienceNotFound;
use App\Experience\Domain\ExperienceEditability;
use App\Experience\Domain\ExperienceId;
use App\Experience\Domain\ExperienceRepository;
use App\Experience\Domain\Title;
use App\Shared\Application\DomainEventPublisher;

final readonly class UpdateExperienceHandler
{
    public function __construct(
        private ExperienceRepository $experiences,
        private BookingRepository $bookings,
        private DomainEventPublisher $events,
    ) {
    }

    public function __invoke(UpdateExperienceCommand $command): ExperienceResponse
    {
        $id = ExperienceId::fromString($command->id);
        $experience = $this->experiences->find($id) ?? throw ExperienceNotFound::withId($id);

        // The fact comes from the Booking module; the rule is enforced by the aggregate.
        $editability = ExperienceEditability::fromHasConfirmedBookings($this->bookings->existsConfirmedForExperience($id));
        $experience->update(Title::fromString($command->title), Description::fromString($command->description), $editability);

        $this->experiences->save($experience);
        $this->events->publish(...$experience->pullDomainEvents());

        return ExperienceResponse::fromExperience($experience);
    }
}
```

`src/Experience/Infrastructure/Http/UpdateExperienceRequest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Infrastructure\Http;

use App\Experience\Domain\Description;
use App\Experience\Domain\Title;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateExperienceRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: Title::MAX_LENGTH)]
        public string $title,
        #[Assert\NotBlank]
        #[Assert\Length(max: Description::MAX_LENGTH)]
        public string $description,
    ) {
    }
}
```

`src/Experience/Infrastructure/Http/UpdateExperienceController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Experience\Infrastructure\Http;

use App\Experience\Application\Update\UpdateExperienceCommand;
use App\Experience\Application\Update\UpdateExperienceHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class UpdateExperienceController
{
    public function __construct(private UpdateExperienceHandler $handler)
    {
    }

    #[Route('/api/experiences/{id}', name: 'api_experiences_update', methods: ['PUT'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function __invoke(
        string $id,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        UpdateExperienceRequest $request,
    ): JsonResponse {
        return new JsonResponse(($this->handler)(new UpdateExperienceCommand($id, $request->title, $request->description)));
    }
}
```

- [ ] **Step 4: Tests funcionales** — añade a `tests/Functional/Experience/ExperienceApiTest.php`:

```php
    #[Test]
    public function it_updates_an_experience_without_bookings(): void
    {
        $this->client->jsonRequest('POST', '/api/experiences', ['title' => 'Kayak', 'description' => 'At dawn', 'providerId' => '0192b3a4-1234-7abc-8def-0123456789ac']);
        $id = $this->json()['id'];

        $this->client->jsonRequest('PUT', "/api/experiences/{$id}", ['title' => 'Kayak at sunset', 'description' => 'Evening paddle']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Kayak at sunset', $this->json()['title']);
        self::assertSame('0192b3a4-1234-7abc-8def-0123456789ac', $this->json()['providerId']);
    }

    #[Test]
    public function it_refuses_update_once_a_booking_is_confirmed(): void
    {
        $this->client->jsonRequest('POST', '/api/experiences', ['title' => 'Kayak', 'description' => 'At dawn', 'providerId' => '0192b3a4-1234-7abc-8def-0123456789ac']);
        $id = $this->json()['id'];
        $this->client->jsonRequest('POST', "/api/experiences/{$id}/sessions", [
            'startsAt' => (new \DateTimeImmutable('+3 days'))->format(DATE_ATOM), 'capacity' => 5, 'price' => ['amount' => 1500, 'currency' => 'EUR'],
        ]);
        $sessionId = $this->json()['id'];
        $this->client->jsonRequest('POST', "/api/sessions/{$sessionId}/bookings", ['userId' => '0192b3a4-1234-7abc-8def-0123456789ad', 'seats' => 1]);
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('PUT', "/api/experiences/{$id}", ['title' => 'Renamed', 'description' => 'Desc']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/experience-has-bookings', $this->json()['type']);
    }
```

- [ ] **Step 5: Ejecutar → pasan** (`make test`, `make stan`, `make cs`).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(experience): PUT endpoint locked once confirmed bookings exist

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```
