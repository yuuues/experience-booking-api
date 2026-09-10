# Diseño: API de gestión de reservas de experiencias

Fecha: 2026-09-10
Estado: aprobado en conversación, pendiente de revisión escrita

## 1. Objetivo y alcance

API REST en PHP (DDD + arquitectura hexagonal) para una plataforma de experiencias:
proveedores publican experiencias con sesiones (fecha, aforo, precio) y usuarios reservan
plazas. Debe funcionar de verdad, ser mantenible, tener tests y ser robusta cuando muchos
usuarios reservan la misma sesión a la vez.

**Dentro del alcance**

- Registrar experiencia, editarla (mientras no tenga reservas confirmadas), consultarla.
- Crear sesiones para una experiencia, consultarlas.
- Reservar plazas, cancelar reserva, consultar reserva.
- Envío de correo (simulado) al confirmar y al cancelar una reserva.
- Garantía de no sobreventa bajo concurrencia.

**Fuera del alcance** (se documenta en README, sección "Lo que haría en producción")

- Autenticación/autorización. `providerId` y `userId` llegan en el body y son inventados.
  En producción es imprescindible: la referencia de reserva es corta y enumerable, y una
  reserva solo debe poder cancelarla su dueño.
- Rate limiting (429). Iría en API gateway o Symfony RateLimiter por `userId`/IP en
  `POST /sessions/{id}/bookings` y `POST /bookings/{reference}/cancellation`, como segunda
  capa contra fuerza bruta de referencias y contra bots en sesiones muy demandadas.
- Modelar proveedor y usuario: solo referencias por id.
- Listados/paginación, borrado de recursos, cambios de aforo o precio de una sesión.
- Envío real de correo: `MailerPort` con adaptador Symfony Mailer con DSN `null://`.

## 2. Decisiones tomadas

| Tema | Decisión | Motivo |
|---|---|---|
| Framework | Symfony 8.1, PHP 8.5 | Estándar de facto para DDD/hexagonal en PHP; Doctrine es Data Mapper; Messenger para eventos y correo asíncrono |
| Base de datos | PostgreSQL 18 (Docker) | Problema transaccional: `FOR UPDATE`, `CHECK`, índices únicos. NoSQL solo tendría sentido para read models (no pedidos) |
| Entorno | `docker compose` (php-fpm, nginx, postgres) + `Makefile` | Reproducible para el evaluador |
| Concurrencia | Bloqueo pesimista `SELECT … FOR UPDATE` sobre la fila de la sesión + `CHECK` en BD | Correcto bajo alta contención, sin reintentos; la regla sigue en el agregado |
| Email de contacto | Puerto `UserContactProvider` (resuelve email a partir de `UserId`), adaptador fake determinista | El usuario vive en otro contexto; no se modela |
| "Mismo día" | Zona única de plataforma `APP_TIMEZONE` (por defecto `Europe/Madrid`); fechas se guardan en UTC | Simple, sin campos extra; documentado |
| Ventana de cancelación | No se puede cancelar si faltan **menos de 24 h** para el inicio (`now > startsAt - 24h`) | Lectura razonable del enunciado |
| Precio | Por plaza; `total = precio × plazas`; `Money` en céntimos + divisa ISO 4217 | Evita flotantes |
| Límites de reserva | Solo los del enunciado: `seats ≥ 1` y `seats ≤ disponibles`; un usuario puede reservar varias veces la misma sesión | YAGNI |
| Editar experiencia | `PUT` permitido solo si ninguna sesión tiene reservas **confirmadas** (canceladas no cuentan) | Regla de dominio real; se implementa al final, tras el núcleo |
| Referencia de reserva | `BookingReference` pública y legible (`BK-` + 8 chars Crockford base32, única). La API y el correo usan la referencia; el `BookingId` (UUID) es interno (FKs, eventos) | Es lo habitual en reservas; un UUID no es usable por una persona |
| DTOs | En toda frontera (HTTP↔App, App↔Mailer, eventos). Dentro del dominio circulan agregados y VOs | Evita CRUD anémico |
| Ids | UUID v7 generados en servidor (en el controlador, antes del Command) | `201 + Location` sin ida extra a BD |
| Mapping ORM | XML en `config/doctrine/`, custom types para VOs | Dominio sin referencia al ORM |

## 3. Modelo de dominio

Un único bounded context, tres módulos (`Experience`, `Session`, `Booking`) más `Shared`.
Cada módulo se divide en `Domain`, `Application`, `Infrastructure`.

### 3.1 Agregados

**Experience**

- Estado: `ExperienceId`, `Title` (1–150 chars), `Description` (1–2000 chars), `ProviderId`.
- `static register(id, title, description, providerId): self` → `ExperienceRegistered`.
- `update(Title, Description, ExperienceEditability)`: lanza `ExperienceHasBookings` si no
  es editable → `ExperienceUpdated`.
- `ExperienceEditability` es un VO (`editable()` / `locked()`) construido por el caso de
  uso a partir de `BookingRepository::existsConfirmedForExperience()`. Así la regla vive en
  el agregado aunque el dato venga de otro módulo.

**Session** (protege el aforo y es factoría de `Booking`)

- Estado: `SessionId`, `ExperienceId`, `StartsAt` (UTC), `SessionDay` (fecha local en zona
  de plataforma, derivada de `StartsAt`), `Capacity` (> 0), `Price` (`Money`),
  `bookedSeats` (int ≥ 0).
- `static schedule(id, experienceId, startsAt, capacity, price, Clock): self`: lanza
  `SessionInThePast` si `startsAt <= now` → `SessionScheduled`.
- `book(BookingId, BookingReference, UserId, Seats, Clock): Booking`:
  - `SessionAlreadyStarted` si `now >= startsAt`.
  - `NotEnoughSeatsAvailable` si `seats > capacity - bookedSeats`.
  - Incrementa `bookedSeats`, calcula `total = price × seats`, devuelve
    `Booking::confirm(...)` (que registra `BookingConfirmed`).
- `cancelBooking(Booking, Clock)`:
  - Verifica `booking->sessionId == id`.
  - `CancellationWindowClosed` si `now > startsAt - 24h`.
  - `booking->cancel(now)` (lanza `BookingAlreadyCancelled` si procede).
  - Decrementa `bookedSeats`.
- `availableSeats(): int`.

**Booking**

- Estado: `BookingId` (interno), `BookingReference` (pública), `SessionId`, `UserId`,
  `Seats`, `TotalPrice` (`Money`), `BookingStatus` (enum `confirmed` | `cancelled`),
  `bookedAt`, `cancelledAt` (nullable).
- `static confirm(...)` (solo invocable desde `Session::book`) → `BookingConfirmed`.
- `BookingReference`: formato `BK-XXXXXXXX`, 8 caracteres Crockford base32 (sin `I L O U`).
  Se obtiene del puerto `BookingReferenceGenerator::next()`; adaptador real con
  `random_int`, adaptador determinista en tests. El caso de uso pide referencias al
  generador hasta obtener una que no exista (`existsByReference`); el índice único es la red
  de seguridad final.
- `cancel(now)`: `BookingAlreadyCancelled` si ya está cancelada → `BookingCancelled`.

Reservar y cancelar modifican `Session` y `Booking` en una misma transacción. Es una
concesión consciente (dos agregados por transacción) a cambio de consistencia fuerte en el
aforo; se documenta en README.

### 3.2 Invariante entre agregados: una sesión por experiencia y día

No cabe en un agregado. Lo resuelve el caso de uso con
`SessionRepository::existsForExperienceOn(ExperienceId, SessionDay)` y lo garantiza el
índice único `(experience_id, day)`. La violación del índice se traduce a
`SessionAlreadyScheduledForDay`.

### 3.3 Value Objects

`ExperienceId`, `SessionId`, `BookingId`, `ProviderId`, `UserId` (UUID), `BookingReference`, `Title`,
`Description`, `StartsAt`, `SessionDay`, `Capacity`, `Seats`, `Money`, `Email`,
`ExperienceEditability`, `BookingStatus` (enum). Todos validan en el constructor: no puede
existir un VO inválido.

### 3.4 Shared/Domain

- `AggregateRoot`: `record(DomainEvent)`, `pullDomainEvents(): array`.
- `DomainEvent`: `aggregateId()`, `occurredOn()`, `eventName()`, `toPrimitives()`.
- `Clock` (puerto): `now(): DateTimeImmutable`. Implementaciones `SystemClock`, `FixedClock`.
- `DomainException` base, `NotFoundException` base.

### 3.5 Excepciones de dominio → HTTP

| Excepción | HTTP |
|---|---|
| `ExperienceNotFound`, `SessionNotFound`, `BookingNotFound` | 404 |
| `SessionAlreadyScheduledForDay`, `ExperienceHasBookings` | 409 |
| `SessionInThePast`, `SessionAlreadyStarted`, `NotEnoughSeatsAvailable`, `BookingAlreadyCancelled`, `CancellationWindowClosed` | 422 |
| Validación de forma del request, `InvalidValue` (VO mal formado que pasó la validación de forma) | 400 |
| Lock timeout en BD | 503 + `Retry-After` |

Todos los errores en `application/problem+json` (RFC 7807): `type`, `title`, `status`,
`detail`, y `errors[]` en validaciones.

## 4. Capa de aplicación

Un caso de uso = `Command` (readonly) + `Handler` + `Response` DTO. Los handlers no
devuelven agregados.

| Caso de uso | Flujo |
|---|---|
| `RegisterExperience` | VOs → `Experience::register()` → `save` → `ExperienceResponse` |
| `UpdateExperience` | carga (404) → `existsConfirmedForExperience` → `update(..., editability)` → `save` |
| `FindExperience` | carga (404) → `ExperienceResponse` |
| `ScheduleSession` | carga experiencia (404) → `existsForExperienceOn` (409) → `Session::schedule(clock)` → `save` → `SessionResponse` |
| `FindSession` | carga (404) → `SessionResponse` (con `availableSeats`) |
| `BookSeats` | **transacción**: `findForUpdate(sessionId)` (404) → `reference = generator->next()` hasta que `!existsByReference` → `session->book(...)` → `save(session)`, `save(booking)` → publicar eventos → commit → `BookingResponse` |
| `CancelBooking` | **transacción**: `findByReference` (404) → `findForUpdate(booking.sessionId)` → `session->cancelBooking(booking, clock)` → guarda ambos → publicar eventos → commit → `BookingResponse` |
| `FindBooking` | `findByReference` (404) → `BookingResponse` |

Puertos en `Shared/Application`:

- `TransactionalRunner::run(callable): mixed`.
- `DomainEventPublisher::publish(DomainEvent ...)`.

Puertos de repositorio (en `Domain` de cada módulo): `ExperienceRepository`,
`SessionRepository` (con `findForUpdate`), `BookingRepository` (con `findByReference` y
`existsConfirmedForExperience`). Implementaciones Doctrine e in-memory (tests).
Puerto `BookingReferenceGenerator` en `Booking/Domain`.

## 5. Eventos y correo (outbox)

1. Los agregados registran `BookingConfirmed` y `BookingCancelled`.
2. `DoctrineTransactionalRunner` ejecuta el callable, hace `flush`, y `MessengerEventPublisher`
   despacha los eventos al transporte **Doctrine** de Messenger dentro de la misma transacción
   (misma conexión). Si falla el commit, no sale correo; si se confirma, no se pierde.
3. Handlers asíncronos en `Booking/Application/Notify`:
   `SendBookingConfirmationEmailOnBookingConfirmed`, `SendBookingCancellationEmailOnBookingCancelled`.
   Resuelven el email con `UserContactProvider`, montan `BookingEmail` (DTO: to, subject,
   bookingReference, seats, total, sessionStartsAt) y llaman a `MailerPort::send(BookingEmail)`.
4. Adaptadores de `MailerPort`: `SymfonyMailerAdapter` (DSN `null://` en dev) y
   `InMemoryMailer` (tests). `UserContactProvider`: `FakeUserContactProvider` →
   `user-{uuid}@example.test`.
5. Idempotencia: los handlers de correo consultan/marcan `SentNotificationRegistry`
   (tabla `sent_notifications`, clave `(booking_reference, type)`) por si Messenger reintenta.

## 6. Persistencia

Tablas:

- `experiences(id uuid pk, title varchar(150), description text, provider_id uuid)`
- `sessions(id uuid pk, experience_id uuid fk, starts_at timestamptz, day date, capacity int, price_amount bigint, price_currency char(3), booked_seats int default 0)`
  - `UNIQUE (experience_id, day)`
  - `CHECK (capacity > 0)`, `CHECK (booked_seats >= 0 AND booked_seats <= capacity)`
- `bookings(id uuid pk, reference char(11) unique, session_id uuid fk, user_id uuid, seats int, total_amount bigint, total_currency char(3), status varchar(16), booked_at timestamptz, cancelled_at timestamptz null)`
  - índice `(session_id, status)`; `CHECK (seats > 0)`
- `sent_notifications(booking_reference char(11), type varchar(32), sent_at timestamptz, pk(booking_reference, type))` — idempotencia del correo.
- `messenger_messages` (transporte Doctrine de Messenger, creada con `messenger:setup-transports`).

Concurrencia: `SessionRepository::findForUpdate` hace `SELECT … FOR UPDATE` sobre `sessions`.
Distintas sesiones no se bloquean entre sí. `lock_timeout` corto (p. ej. 2 s) configurado en
la conexión; su expiración se traduce a 503.

Migraciones con Doctrine Migrations (`make migrate`).

## 7. Contrato REST

Prefijo `/api`. JSON. Ids UUID v7.

| Método | Ruta | Body | Respuesta |
|---|---|---|---|
| `POST` | `/experiences` | `{title, description, providerId}` | `201`, `Location`, `ExperienceResponse` |
| `GET` | `/experiences/{id}` | — | `200` |
| `PUT` | `/experiences/{id}` | `{title, description}` | `200` / `409` si tiene reservas confirmadas |
| `POST` | `/experiences/{id}/sessions` | `{startsAt: ISO 8601 con offset, capacity, price: {amount, currency}}` | `201`, `Location`, `SessionResponse` |
| `GET` | `/sessions/{id}` | — | `200` |
| `POST` | `/sessions/{id}/bookings` | `{userId, seats}` | `201`, `Location`, `BookingResponse` |
| `GET` | `/bookings/{reference}` | — | `200` |
| `POST` | `/bookings/{reference}/cancellation` | — | `200`, `BookingResponse` con `status: cancelled` |

Las reservas se identifican públicamente por su referencia (`BK-…`); el UUID interno no se
expone. `Location: /api/bookings/BK-7F3A2C9K`.

Cancelación como `POST …/cancellation` (no `DELETE`): la reserva no se borra, cambia de
estado.

DTOs de respuesta:

- `ExperienceResponse {id, title, description, providerId}`
- `SessionResponse {id, experienceId, startsAt, capacity, bookedSeats, availableSeats, price: {amount, currency}}`
- `BookingResponse {reference, sessionId, userId, seats, total: {amount, currency}, status, bookedAt, cancelledAt}`

Request DTOs validados con Symfony Validator (forma y tipos); las reglas de negocio se
validan en el dominio.

## 8. Estructura de carpetas

```
src/
├── Shared/
│   ├── Domain/          AggregateRoot, DomainEvent, Clock, Uuid, Money, DomainException, NotFoundException
│   ├── Application/     TransactionalRunner, DomainEventPublisher
│   └── Infrastructure/  Doctrine/ (custom types, runner), Messenger/ (publisher), Symfony/ (ExceptionListener), SystemClock
├── Experience/
│   ├── Domain/          Experience, Title, Description, ProviderId, ExperienceEditability, ExperienceRepository, events/, exceptions/
│   ├── Application/     Register/, Update/, Find/
│   └── Infrastructure/  Http/ (controllers + request DTOs), Persistence/Doctrine/
├── Session/
│   ├── Domain/          Session, StartsAt, SessionDay, Capacity, Seats, SessionRepository, events/, exceptions/
│   ├── Application/     Schedule/, Find/
│   └── Infrastructure/  Http/, Persistence/Doctrine/
└── Booking/
    ├── Domain/          Booking, BookingReference, BookingReferenceGenerator, BookingStatus, UserId, BookingRepository, UserContactProvider, MailerPort, BookingEmail, events/, exceptions/
    ├── Application/     Book/, Cancel/, Find/, Notify/
    └── Infrastructure/  Http/, Persistence/Doctrine/, Reference/ (RandomBookingReferenceGenerator), Contact/ (FakeUserContactProvider), Mailer/ (SymfonyMailerAdapter, InMemoryMailer)

config/doctrine/*.orm.xml
tests/ (mismo árbol: Unit/, Integration/, Functional/, Concurrency/)
docker/ (php, nginx), compose.yaml, Makefile
```

## 9. Tests

- **Unit** (sin BD): todas las reglas de negocio de §3 sobre agregados y VOs; handlers con
  repositorios in-memory, `FixedClock`, `InMemoryMailer`.
- **Integration**: repositorios Doctrine contra Postgres; índice único →
  `SessionAlreadyScheduledForDay`; `findForUpdate` bloquea de verdad.
- **Functional** (`WebTestCase`): cada endpoint con casos de éxito y cada código de error,
  formato problem+json, cabecera `Location`.
- **Concurrencia**: N procesos en paralelo (p. ej. 50 peticiones, aforo 10) contra una
  sesión; se verifica `booked_seats == capacity`, exactamente `capacity` reservas
  `confirmed`, y que el resto reciben 422. Ejecutable con `make test-concurrency`.

## 10. Herramientas y calidad

PHP 8.5, Symfony 8.1, Doctrine ORM 3.7 + Migrations, Messenger, Symfony Mailer, PHPUnit 13,
PHPStan 2.2 nivel max, PHP-CS-Fixer (PSR-12), `declare(strict_types=1)` en todo. `Makefile`:
`up`, `down`, `migrate`, `test`, `test-concurrency`, `stan`, `cs`.

## 11. README (esquema)

1. Cómo arrancar (`make up && make migrate`) y probar (colección de ejemplos `curl`).
2. Arquitectura: hexagonal, módulos, DTOs en fronteras, flujo de una reserva.
3. Decisiones (tabla de §2) y supuestos sobre el enunciado.
4. Concurrencia: por qué `FOR UPDATE` + `CHECK`, y el test que lo demuestra.
5. **Lo que haría en producción**: autenticación/autorización (la referencia `BK-…` es
   enumerable; solo el dueño debe cancelar), rate limiting (429) en reservar y cancelar
   como segunda capa contra fuerza bruta y bots, read models/CQRS para catálogo, envío real
   de correo con reintentos y dead-letter, observabilidad, límite de plazas por reserva si
   negocio lo pide.
