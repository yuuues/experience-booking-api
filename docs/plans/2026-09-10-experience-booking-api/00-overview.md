# Experience Booking API — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. El plan está partido en fases (un fichero por fase); ejecútalas en orden.

**Goal:** API REST en PHP para registrar experiencias, programar sesiones, reservar plazas y cancelar reservas, sin sobreventa bajo concurrencia, con correo simulado.

**Architecture:** DDD + hexagonal. Tres módulos (`Experience`, `Session`, `Booking`) más `Shared`, cada uno con `Domain` / `Application` / `Infrastructure`. `Session` protege el aforo y fabrica `Booking`; reservar y cancelar bloquean la fila de la sesión con `SELECT … FOR UPDATE` en una transacción. Eventos de dominio → Messenger (transporte Doctrine, misma transacción) → handlers de correo asíncronos.

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine ORM 3.7 / DBAL 4.4 (mapping XML), Messenger, Mailer, PostgreSQL 18, PHPUnit 13, PHPStan 2.2, PHP-CS-Fixer, Docker Compose.

**Spec:** `docs/design/2026-09-10-experience-booking-api-design.md` — el plan argumenta desde la spec; léela antes de cada fase.

## Global Constraints

- PHP `>=8.5`; Symfony `8.1.*`; PostgreSQL `18`; Doctrine ORM `^3.7`; PHPUnit `^13.3`; PHPStan `^2.2` nivel `max`.
- Todo fichero PHP empieza con `<?php` + `declare(strict_types=1);`. Clases `final` salvo bases abstractas. Value Objects `final readonly`.
- Namespace raíz `App\` → `src/`; tests `App\Tests\` → `tests/`.
- El dominio (`src/*/Domain`) no importa nada de `Doctrine\`, `Symfony\` (salvo `Symfony\Component\Uid\Uuid` dentro de `Shared\Domain\Uuid`) ni de `Infrastructure`.
- DTOs en toda frontera: los handlers reciben `Command`/`Query` y devuelven `*Response`; nunca devuelven agregados.
- Mapping Doctrine en XML bajo `config/doctrine/<Módulo>/`. Sin asociaciones entre agregados: las referencias son VOs de id mapeados como columnas.
- Zona horaria de plataforma: `APP_TIMEZONE` (por defecto `Europe/Madrid`). Fechas guardadas en UTC.
- Errores HTTP en `application/problem+json`. Mapa: `NotFoundException`→404, `SessionAlreadyScheduledForDay`/`ExperienceHasBookings`→409, `InvalidValue`→400, resto de `DomainException`→422, validación de request→400, `LockWaitTimeoutException`→503.
- Todo comando se ejecuta dentro de Docker vía `make` (ver `01-scaffolding.md`). No se asume PHP local.
- Commit al final de cada tarea, mensaje en inglés, convención `feat:/test:/chore:/docs:`, firmado (GPG ya configurado). Añade al final de cada commit: `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
- Idioma: código, comentarios y commits en inglés; README en español.

## Fases

| Fase | Fichero | Tareas | Entregable |
|---|---|---|---|
| 1 | `01-scaffolding.md` | 1 | Proyecto arranca en Docker, `make test` verde con test de humo |
| 2 | `02-shared-kernel.md` | 2–3 | `Shared\Domain` (Uuid, Money, Clock, AggregateRoot…) y `Shared\Infrastructure` (types base, runner transaccional, publisher, listener problem+json) |
| 3 | `03-experience.md` | 4–7 | Módulo Experience completo: `POST/GET /api/experiences` |
| 4 | `04-booking-session-domain.md` | 8–9 | Dominio de Booking y de Session (todas las reglas de negocio, tests unitarios) |
| 5 | `05-session.md` | 10–12 | `POST /api/experiences/{id}/sessions`, `GET /api/sessions/{id}`, índice único por día |
| 6 | `06-booking.md` | 13–15 | `POST /api/sessions/{id}/bookings`, `GET/POST /api/bookings/{reference}[/cancellation]` con `FOR UPDATE` |
| 7 | `07-notifications.md` | 16 | Eventos → Messenger → correo (outbox, idempotente) |
| 8 | `08-update-experience.md` | 17 | `PUT /api/experiences/{id}` con `ExperienceEditability` |
| 9 | `09-concurrency-and-readme.md` | 18–19 | Script de concurrencia, README, PHPStan/CS limpios |

## Mapa de ficheros (resultado final)

```
compose.yaml, Makefile, composer.json, .env, .env.test, phpunit.dist.xml, phpstan.dist.neon, .php-cs-fixer.dist.php
docker/php/Dockerfile, docker/php/conf.d/app.ini, docker/php/php-fpm.d/zz-app.conf, docker/nginx/default.conf
bin/console, bin/concurrency-test, public/index.php
config/{bundles.php, services.yaml, services_test.yaml, routes.yaml}
config/packages/{framework,doctrine,doctrine_migrations,messenger,mailer,validator,dama_doctrine_test}.yaml
config/doctrine/Shared/Money.orm.xml
config/doctrine/Experience/Experience.orm.xml
config/doctrine/Session/Session.orm.xml
config/doctrine/Booking/Booking.orm.xml
migrations/Version20260910120000.php (experiences) … Version20260910120300.php (sent_notifications)

src/Kernel.php
src/Shared/Domain/{AggregateRoot,DomainEvent,Clock,Uuid,Money,DomainException,NotFoundException,InvalidValue}.php
src/Shared/Application/{TransactionalRunner,DomainEventPublisher}.php
src/Shared/Infrastructure/SystemClock.php
src/Shared/Infrastructure/Doctrine/DoctrineTransactionalRunner.php
src/Shared/Infrastructure/Doctrine/Type/{UuidType,StringValueObjectType,IntValueObjectType}.php
src/Shared/Infrastructure/Messenger/MessengerDomainEventPublisher.php
src/Shared/Infrastructure/Symfony/ProblemJsonExceptionListener.php

src/Experience/Domain/{Experience,ExperienceId,ProviderId,Title,Description,ExperienceEditability,ExperienceRepository}.php
src/Experience/Domain/Event/{ExperienceRegistered,ExperienceUpdated}.php
src/Experience/Domain/Exception/{ExperienceNotFound,ExperienceHasBookings}.php
src/Experience/Application/ExperienceResponse.php
src/Experience/Application/Register/{RegisterExperienceCommand,RegisterExperienceHandler}.php
src/Experience/Application/Find/{FindExperienceQuery,FindExperienceHandler}.php
src/Experience/Application/Update/{UpdateExperienceCommand,UpdateExperienceHandler}.php
src/Experience/Infrastructure/Http/{RegisterExperienceController,FindExperienceController,UpdateExperienceController,RegisterExperienceRequest,UpdateExperienceRequest}.php
src/Experience/Infrastructure/Persistence/Doctrine/DoctrineExperienceRepository.php
src/Experience/Infrastructure/Persistence/Doctrine/Type/{ExperienceIdType,ProviderIdType,TitleType,DescriptionType}.php

src/Session/Domain/{Session,SessionId,StartsAt,SessionDay,Capacity,SessionRepository}.php
src/Session/Domain/Event/SessionScheduled.php
src/Session/Domain/Exception/{SessionNotFound,SessionInThePast,SessionAlreadyStarted,SessionAlreadyScheduledForDay,NotEnoughSeatsAvailable,CancellationWindowClosed,BookingDoesNotBelongToSession}.php
src/Session/Application/{SessionResponse,MoneyResponse}.php
src/Session/Application/Schedule/{ScheduleSessionCommand,ScheduleSessionHandler}.php
src/Session/Application/Find/{FindSessionQuery,FindSessionHandler}.php
src/Session/Infrastructure/Http/{ScheduleSessionController,FindSessionController,ScheduleSessionRequest,PriceRequest}.php
src/Session/Infrastructure/Persistence/Doctrine/DoctrineSessionRepository.php
src/Session/Infrastructure/Persistence/Doctrine/Type/{SessionIdType,StartsAtType,SessionDayType,CapacityType}.php

src/Booking/Domain/{Booking,BookingId,BookingReference,BookingReferenceGenerator,BookingStatus,UserId,Seats,BookingRepository}.php
src/Booking/Domain/Event/{BookingConfirmed,BookingCancelled}.php
src/Booking/Domain/Exception/{BookingNotFound,BookingAlreadyCancelled}.php
src/Booking/Domain/Notification/{Email,BookingEmail,MailerPort,UserContactProvider,SentNotificationRegistry}.php
src/Booking/Application/BookingResponse.php
src/Booking/Application/Book/{BookSeatsCommand,BookSeatsHandler}.php
src/Booking/Application/Cancel/{CancelBookingCommand,CancelBookingHandler}.php
src/Booking/Application/Find/{FindBookingQuery,FindBookingHandler}.php
src/Booking/Application/Notify/{SendBookingConfirmationEmailOnBookingConfirmed,SendBookingCancellationEmailOnBookingCancelled}.php
src/Booking/Infrastructure/Http/{BookSeatsController,CancelBookingController,FindBookingController,BookSeatsRequest}.php
src/Booking/Infrastructure/Persistence/Doctrine/{DoctrineBookingRepository,DbalSentNotificationRegistry}.php
src/Booking/Infrastructure/Persistence/Doctrine/Type/{BookingIdType,BookingReferenceType,UserIdType,SeatsType}.php
src/Booking/Infrastructure/Reference/RandomBookingReferenceGenerator.php
src/Booking/Infrastructure/Contact/FakeUserContactProvider.php
src/Booking/Infrastructure/Mailer/SymfonyMailerAdapter.php

tests/bootstrap.php
tests/Doubles/Shared/{FixedClock,InMemoryTransactionalRunner,InMemoryDomainEventPublisher}.php
tests/Doubles/Experience/InMemoryExperienceRepository.php
tests/Doubles/Session/InMemorySessionRepository.php
tests/Doubles/Booking/{InMemoryBookingRepository,SequentialBookingReferenceGenerator,InMemoryMailer,InMemorySentNotificationRegistry}.php
tests/Unit/…  tests/Integration/…  tests/Functional/…  (detallados en cada fase)
```

## Interfaces compartidas (referencia rápida)

Firmas que cruzan fases. Cada fase las repite en su bloque **Interfaces**; esta tabla es para orientarse.

```php
// Shared\Domain
abstract class Uuid { public readonly string $value; static fromString(string): static; static generate(): static; equals(self): bool; __toString(): string }
final readonly class Money { int $amount; string $currency; static fromPrimitives(int, string): self; multiply(int): self; equals(self): bool }
interface Clock { now(): \DateTimeImmutable /* UTC */; timeZone(): \DateTimeZone }
interface DomainEvent { aggregateId(): string; occurredOn(): \DateTimeImmutable; static eventName(): string }
abstract class AggregateRoot { protected record(DomainEvent): void; pullDomainEvents(): DomainEvent[] }
abstract class DomainException extends \DomainException { abstract errorCode(): string }
abstract class NotFoundException extends DomainException
final class InvalidValue extends DomainException

// Shared\Application
interface TransactionalRunner { run(callable $operation): mixed }
interface DomainEventPublisher { publish(DomainEvent ...$events): void }

// Experience\Domain
final class Experience extends AggregateRoot { static register(ExperienceId, Title, Description, ProviderId): self; update(Title, Description, ExperienceEditability): void; id(); title(); description(); providerId() }
interface ExperienceRepository { save(Experience): void; find(ExperienceId): ?Experience }

// Session\Domain
final class Session extends AggregateRoot { static schedule(SessionId, ExperienceId, StartsAt, Capacity, Money, Clock): self; book(BookingId, BookingReference, UserId, Seats, Clock): Booking; cancelBooking(Booking, Clock): void; availableSeats(): int; id(); experienceId(); startsAt(); day(); capacity(); price(); bookedSeats() }
interface SessionRepository { save(Session): void; find(SessionId): ?Session; findForUpdate(SessionId): ?Session; existsForExperienceOn(ExperienceId, SessionDay): bool }

// Booking\Domain
final class Booking extends AggregateRoot { static confirm(BookingId, BookingReference, SessionId, UserId, Seats, Money, \DateTimeImmutable): self; cancel(\DateTimeImmutable): void; isCancelled(): bool; id(); reference(); sessionId(); userId(); seats(); totalPrice(); status(); bookedAt(); cancelledAt() }
interface BookingRepository { save(Booking): void; findByReference(BookingReference): ?Booking; existsByReference(BookingReference): bool; existsConfirmedForExperience(ExperienceId): bool }
interface BookingReferenceGenerator { next(): BookingReference }
interface MailerPort { send(BookingEmail): void }
interface UserContactProvider { emailFor(UserId): Email }
interface SentNotificationRegistry { wasSent(BookingReference, string $type): bool; markSent(BookingReference, string $type): void }
```
