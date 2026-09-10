# Fase 7 — Notificaciones por correo (outbox)

Requiere Fase 6. Los eventos `BookingConfirmed` / `BookingCancelled` ya se publican en Messenger (transporte Doctrine en dev, `sync://` en test). Aquí se añaden los handlers que envían el correo, los puertos y sus adaptadores, y la idempotencia.

---

### Task 16: Puertos de notificación, handlers y adaptadores

**Files:**
- Create: `src/Booking/Domain/Notification/Email.php`, `BookingEmail.php`, `MailerPort.php`, `UserContactProvider.php`, `SentNotificationRegistry.php`
- Create: `src/Booking/Application/Notify/SendBookingConfirmationEmailOnBookingConfirmed.php`, `SendBookingCancellationEmailOnBookingCancelled.php`
- Create: `src/Booking/Infrastructure/Contact/FakeUserContactProvider.php`
- Create: `src/Booking/Infrastructure/Mailer/SymfonyMailerAdapter.php`
- Create: `src/Booking/Infrastructure/Persistence/Doctrine/DbalSentNotificationRegistry.php`
- Create: `migrations/Version20260910120300.php`
- Create: `tests/Doubles/Booking/InMemoryMailer.php`, `InMemorySentNotificationRegistry.php`
- Create: `config/services_test.yaml`
- Modify: `config/services.yaml`
- Test: `tests/Unit/Booking/Application/SendBookingConfirmationEmailOnBookingConfirmedTest.php`, `tests/Unit/Booking/Application/SendBookingCancellationEmailOnBookingCancelledTest.php`, `tests/Functional/Booking/BookingEmailsTest.php`

**Interfaces:**
- Consumes: Task 8 eventos, `SessionRepository`, Messenger routing (Fase 1).
- Produces: `Email::fromString(string)` (`public string $value`); `BookingEmail` DTO readonly (`to, type, reference, seats, totalAmount, totalCurrency, sessionStartsAt`) con constantes `TYPE_CONFIRMATION = 'booking-confirmation'`, `TYPE_CANCELLATION = 'booking-cancellation'`; `MailerPort::send(BookingEmail): void`; `UserContactProvider::emailFor(UserId): Email`; `SentNotificationRegistry::wasSent(BookingReference, string): bool`, `markSent(BookingReference, string): void`.

- [ ] **Step 1: Tests unitarios de los handlers**

`tests/Unit/Booking/Application/SendBookingConfirmationEmailOnBookingConfirmedTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Booking\Application;

use App\Booking\Application\Notify\SendBookingConfirmationEmailOnBookingConfirmed;
use App\Booking\Domain\Event\BookingConfirmed;
use App\Booking\Domain\Notification\BookingEmail;
use App\Booking\Infrastructure\Contact\FakeUserContactProvider;
use App\Tests\Doubles\Booking\InMemoryMailer;
use App\Tests\Doubles\Booking\InMemorySentNotificationRegistry;
use App\Tests\Doubles\Session\InMemorySessionRepository;
use App\Tests\Doubles\Shared\FixedClock;
use App\Tests\Unit\Session\Domain\SessionTest;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SendBookingConfirmationEmailOnBookingConfirmedTest extends TestCase
{
    private InMemoryMailer $mailer;
    private SendBookingConfirmationEmailOnBookingConfirmed $handler;
    private BookingConfirmed $event;

    protected function setUp(): void
    {
        $sessions = new InMemorySessionRepository();
        $session = SessionTest::aSession(new FixedClock(), startsAt: '2026-10-05T10:00:00+00:00');
        $sessions->save($session);

        $this->mailer = new InMemoryMailer();
        $this->handler = new SendBookingConfirmationEmailOnBookingConfirmed(
            new FakeUserContactProvider(),
            $sessions,
            $this->mailer,
            new InMemorySentNotificationRegistry(),
        );
        $this->event = new BookingConfirmed(
            reference: 'BK-7F3A2C9K',
            bookingId: '0192b3a4-1234-7abc-8def-0123456789b1',
            sessionId: $session->id()->value,
            userId: '0192b3a4-1234-7abc-8def-0123456789ad',
            seats: 2,
            totalAmount: 3000,
            totalCurrency: 'EUR',
            occurredOn: new DateTimeImmutable('2026-10-01T10:00:00+00:00'),
        );
    }

    #[Test]
    public function it_sends_a_confirmation_email_to_the_user_contact(): void
    {
        ($this->handler)($this->event);

        $sent = $this->mailer->sent();
        self::assertCount(1, $sent);
        self::assertSame('user-0192b3a4-1234-7abc-8def-0123456789ad@example.test', $sent[0]->to);
        self::assertSame(BookingEmail::TYPE_CONFIRMATION, $sent[0]->type);
        self::assertSame('BK-7F3A2C9K', $sent[0]->reference);
        self::assertSame(2, $sent[0]->seats);
        self::assertSame(3000, $sent[0]->totalAmount);
        self::assertSame('2026-10-05T10:00:00+00:00', $sent[0]->sessionStartsAt);
    }

    #[Test]
    public function it_is_idempotent_on_redelivery(): void
    {
        ($this->handler)($this->event);
        ($this->handler)($this->event);

        self::assertCount(1, $this->mailer->sent());
    }
}
```

`tests/Unit/Booking/Application/SendBookingCancellationEmailOnBookingCancelledTest.php`: mismo esqueleto con `SendBookingCancellationEmailOnBookingCancelled`, evento `BookingCancelled(reference, bookingId, sessionId, userId, seats, occurredOn)`, y asserts `TYPE_CANCELLATION`, `seats === 2`, `totalAmount === 0`.

- [ ] **Step 2: Ejecutar → falla** (`make test-unit`).

- [ ] **Step 3: Implementación — dominio y aplicación**

`src/Booking/Domain/Notification/Email.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Domain\Notification;

use App\Shared\Domain\InvalidValue;

final readonly class Email
{
    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (false === filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidValue(sprintf('<%s> is not a valid email address.', $value));
        }

        return new self(strtolower($value));
    }
}
```

`src/Booking/Domain/Notification/BookingEmail.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Domain\Notification;

/** Everything a mailer needs to render a booking email; no domain objects cross this boundary. */
final readonly class BookingEmail
{
    public const string TYPE_CONFIRMATION = 'booking-confirmation';
    public const string TYPE_CANCELLATION = 'booking-cancellation';

    public function __construct(
        public string $to,
        public string $type,
        public string $reference,
        public int $seats,
        public int $totalAmount,
        public string $totalCurrency,
        public string $sessionStartsAt,
    ) {
    }
}
```

`src/Booking/Domain/Notification/MailerPort.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Domain\Notification;

interface MailerPort
{
    public function send(BookingEmail $email): void;
}
```

`src/Booking/Domain/Notification/UserContactProvider.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Domain\Notification;

use App\Booking\Domain\UserId;

/** Resolves the contact email of a user living in another bounded context. */
interface UserContactProvider
{
    public function emailFor(UserId $userId): Email;
}
```

`src/Booking/Domain/Notification/SentNotificationRegistry.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Domain\Notification;

use App\Booking\Domain\BookingReference;

/** Makes email handlers idempotent under at-least-once delivery. */
interface SentNotificationRegistry
{
    public function wasSent(BookingReference $reference, string $type): bool;

    public function markSent(BookingReference $reference, string $type): void;
}
```

`src/Booking/Application/Notify/SendBookingConfirmationEmailOnBookingConfirmed.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Application\Notify;

use App\Booking\Domain\BookingReference;
use App\Booking\Domain\Event\BookingConfirmed;
use App\Booking\Domain\Notification\BookingEmail;
use App\Booking\Domain\Notification\MailerPort;
use App\Booking\Domain\Notification\SentNotificationRegistry;
use App\Booking\Domain\Notification\UserContactProvider;
use App\Booking\Domain\UserId;
use App\Session\Domain\Exception\SessionNotFound;
use App\Session\Domain\SessionId;
use App\Session\Domain\SessionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendBookingConfirmationEmailOnBookingConfirmed
{
    public function __construct(
        private UserContactProvider $contacts,
        private SessionRepository $sessions,
        private MailerPort $mailer,
        private SentNotificationRegistry $sent,
    ) {
    }

    public function __invoke(BookingConfirmed $event): void
    {
        $reference = BookingReference::fromString($event->reference);
        if ($this->sent->wasSent($reference, BookingEmail::TYPE_CONFIRMATION)) {
            return;
        }

        $sessionId = SessionId::fromString($event->sessionId);
        $session = $this->sessions->find($sessionId) ?? throw SessionNotFound::withId($sessionId);

        $this->mailer->send(new BookingEmail(
            to: $this->contacts->emailFor(UserId::fromString($event->userId))->value,
            type: BookingEmail::TYPE_CONFIRMATION,
            reference: $reference->value,
            seats: $event->seats,
            totalAmount: $event->totalAmount,
            totalCurrency: $event->totalCurrency,
            sessionStartsAt: $session->startsAt()->toAtom(),
        ));

        $this->sent->markSent($reference, BookingEmail::TYPE_CONFIRMATION);
    }
}
```

`src/Booking/Application/Notify/SendBookingCancellationEmailOnBookingCancelled.php`: igual, escuchando `BookingCancelled`, `type: BookingEmail::TYPE_CANCELLATION`, `totalAmount: 0`, `totalCurrency: $session->price()->currency`.

Dobles:

`tests/Doubles/Booking/InMemoryMailer.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Doubles\Booking;

use App\Booking\Domain\Notification\BookingEmail;
use App\Booking\Domain\Notification\MailerPort;

final class InMemoryMailer implements MailerPort
{
    /** @var list<BookingEmail> */
    private array $sent = [];

    public function send(BookingEmail $email): void
    {
        $this->sent[] = $email;
    }

    /** @return list<BookingEmail> */
    public function sent(): array
    {
        return $this->sent;
    }

    public function reset(): void
    {
        $this->sent = [];
    }
}
```

`tests/Doubles/Booking/InMemorySentNotificationRegistry.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Doubles\Booking;

use App\Booking\Domain\BookingReference;
use App\Booking\Domain\Notification\SentNotificationRegistry;

final class InMemorySentNotificationRegistry implements SentNotificationRegistry
{
    /** @var array<string, true> */
    private array $sent = [];

    public function wasSent(BookingReference $reference, string $type): bool
    {
        return isset($this->sent[$reference->value.'|'.$type]);
    }

    public function markSent(BookingReference $reference, string $type): void
    {
        $this->sent[$reference->value.'|'.$type] = true;
    }
}
```

- [ ] **Step 4: Ejecutar unitarios → pasan** (`make test-unit`).

- [ ] **Step 5: Adaptadores de infraestructura**

`src/Booking/Infrastructure/Contact/FakeUserContactProvider.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Contact;

use App\Booking\Domain\Notification\Email;
use App\Booking\Domain\Notification\UserContactProvider;
use App\Booking\Domain\UserId;

/** Users are not modelled in this service; a real adapter would call the user/identity context. */
final class FakeUserContactProvider implements UserContactProvider
{
    public function emailFor(UserId $userId): Email
    {
        return Email::fromString(sprintf('user-%s@example.test', $userId->value));
    }
}
```

`src/Booking/Infrastructure/Mailer/SymfonyMailerAdapter.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Mailer;

use App\Booking\Domain\Notification\BookingEmail;
use App\Booking\Domain\Notification\MailerPort;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final readonly class SymfonyMailerAdapter implements MailerPort
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire(param: 'app.mailer_from')]
        private string $from,
    ) {
    }

    public function send(BookingEmail $email): void
    {
        $this->mailer->send((new Email())
            ->from($this->from)
            ->to($email->to)
            ->subject($this->subject($email))
            ->text($this->body($email)));
    }

    private function subject(BookingEmail $email): string
    {
        return match ($email->type) {
            BookingEmail::TYPE_CONFIRMATION => sprintf('Booking %s confirmed', $email->reference),
            BookingEmail::TYPE_CANCELLATION => sprintf('Booking %s cancelled', $email->reference),
            default => sprintf('Booking %s', $email->reference),
        };
    }

    private function body(BookingEmail $email): string
    {
        $amount = number_format($email->totalAmount / 100, 2, '.', '');

        return match ($email->type) {
            BookingEmail::TYPE_CONFIRMATION => sprintf(
                "Your booking %s is confirmed.\nSeats: %d\nTotal: %s %s\nSession starts at: %s\n",
                $email->reference, $email->seats, $amount, $email->totalCurrency, $email->sessionStartsAt,
            ),
            default => sprintf(
                "Your booking %s has been cancelled.\nSeats released: %d\nSession was starting at: %s\n",
                $email->reference, $email->seats, $email->sessionStartsAt,
            ),
        };
    }
}
```

`src/Booking/Infrastructure/Persistence/Doctrine/DbalSentNotificationRegistry.php`:
```php
<?php

declare(strict_types=1);

namespace App\Booking\Infrastructure\Persistence\Doctrine;

use App\Booking\Domain\BookingReference;
use App\Booking\Domain\Notification\SentNotificationRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class DbalSentNotificationRegistry implements SentNotificationRegistry
{
    public function __construct(private Connection $connection)
    {
    }

    public function wasSent(BookingReference $reference, string $type): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM sent_notifications WHERE booking_reference = :reference AND type = :type',
            ['reference' => $reference->value, 'type' => $type],
        );
    }

    public function markSent(BookingReference $reference, string $type): void
    {
        try {
            $this->connection->insert('sent_notifications', [
                'booking_reference' => $reference->value,
                'type' => $type,
                'sent_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sP'),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already marked by a concurrent delivery; nothing to do.
        }
    }
}
```

`migrations/Version20260910120300.php`:
```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910120300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sent_notifications table (email idempotency)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sent_notifications (
            booking_reference CHAR(11) NOT NULL,
            type VARCHAR(32) NOT NULL,
            sent_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (booking_reference, type)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sent_notifications');
    }
}
```

`config/services.yaml` → añade:
```yaml
  App\Booking\Domain\Notification\MailerPort: '@App\Booking\Infrastructure\Mailer\SymfonyMailerAdapter'
  App\Booking\Domain\Notification\UserContactProvider: '@App\Booking\Infrastructure\Contact\FakeUserContactProvider'
  App\Booking\Domain\Notification\SentNotificationRegistry: '@App\Booking\Infrastructure\Persistence\Doctrine\DbalSentNotificationRegistry'
```

`config/services_test.yaml` (en test el correo se captura en memoria y Messenger es `sync://`, así que el handler corre dentro de la petición):
```yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true

  App\Tests\Doubles\Booking\InMemoryMailer:
    public: true

  App\Booking\Domain\Notification\MailerPort: '@App\Tests\Doubles\Booking\InMemoryMailer'
```

- [ ] **Step 6: Test funcional de extremo a extremo**

`tests/Functional/Booking/BookingEmailsTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Booking;

use App\Booking\Domain\Notification\BookingEmail;
use App\Tests\Doubles\Booking\InMemoryMailer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BookingEmailsTest extends WebTestCase
{
    private KernelBrowser $client;
    private InMemoryMailer $mailer;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->mailer = self::getContainer()->get(InMemoryMailer::class);
    }

    #[Test]
    public function booking_and_cancelling_send_one_email_each(): void
    {
        $this->client->jsonRequest('POST', '/api/experiences', ['title' => 'Kayak', 'description' => 'At dawn', 'providerId' => '0192b3a4-1234-7abc-8def-0123456789ac']);
        $experienceId = $this->json()['id'];
        $this->client->jsonRequest('POST', "/api/experiences/{$experienceId}/sessions", [
            'startsAt' => (new \DateTimeImmutable('+3 days'))->format(DATE_ATOM), 'capacity' => 5, 'price' => ['amount' => 1500, 'currency' => 'EUR'],
        ]);
        $sessionId = $this->json()['id'];

        $this->client->jsonRequest('POST', "/api/sessions/{$sessionId}/bookings", ['userId' => '0192b3a4-1234-7abc-8def-0123456789ad', 'seats' => 2]);
        $reference = $this->json()['reference'];

        $sent = $this->mailer->sent();
        self::assertCount(1, $sent);
        self::assertSame(BookingEmail::TYPE_CONFIRMATION, $sent[0]->type);
        self::assertSame($reference, $sent[0]->reference);
        self::assertSame('user-0192b3a4-1234-7abc-8def-0123456789ad@example.test', $sent[0]->to);

        $this->client->request('POST', "/api/bookings/{$reference}/cancellation");

        $sent = $this->mailer->sent();
        self::assertCount(2, $sent);
        self::assertSame(BookingEmail::TYPE_CANCELLATION, $sent[1]->type);
    }

    #[Test]
    public function a_failed_booking_sends_nothing(): void
    {
        $this->client->jsonRequest('POST', '/api/sessions/0192b3a4-1234-7abc-8def-0123456789ff/bookings', ['userId' => '0192b3a4-1234-7abc-8def-0123456789ad', 'seats' => 1]);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->mailer->sent());
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
```

- [ ] **Step 7: Migrar, ejecutar y comprobar el worker en dev**

```bash
make migrate
make test
make stan
make cs
```
Expected: verde. En dev, comprueba la cola: reserva algo con `curl` y luego:
```bash
make console c="messenger:stats"
make logs
```
Expected: el worker consume `BookingConfirmed` y Symfony Mailer con `null://` registra el envío en el log (`mailer.INFO`).

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat(booking): email notifications via Messenger outbox with idempotent handlers

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```
