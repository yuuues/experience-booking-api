# API de reservas de experiencias

API REST para una plataforma de experiencias: un proveedor publica experiencias con sesiones
(fecha, aforo, precio) y los usuarios reservan plazas, con la garantía de que no hay sobreventa
cuando muchos reservan la misma sesión a la vez.

Stack: PHP 8.5, Symfony 8.1, PostgreSQL 18, Doctrine ORM 3 + Migrations, Symfony Messenger y
Mailer. Arquitectura hexagonal con DDD, tres módulos (`Experience`, `Session`, `Booking`) más
`Shared`.

El razonamiento detrás de cada decisión está en [`docs/design/`](docs/design/) (la especificación
[`2026-09-10-experience-booking-api-design.md`](docs/design/2026-09-10-experience-booking-api-design.md)
y el diseño de correo [`email-notifications.md`](docs/design/email-notifications.md)); el plan de
ejecución, fase a fase, en [`docs/plans/2026-09-10-experience-booking-api/`](docs/plans/2026-09-10-experience-booking-api/).

---

## 1. Arranque rápido

Requisitos: Docker y `make`.

```bash
make up             # build + composer install + migraciones + worker de correo
make test           # unit + integration + functional
make test-concurrency
```

La API queda en `http://localhost:8080`. Otros objetivos útiles:

| Comando | Qué hace |
|---|---|
| `make down` / `make destroy` | Para los contenedores / los para y borra los volúmenes |
| `make migrate` | Migraciones + `messenger:setup-transports` |
| `make console c="debug:router"` | Ejecuta `bin/console` dentro del contenedor |
| `make logs` | Sigue los logs, incluido el worker de Messenger |
| `make stan` / `make cs` / `make cs-fix` | PHPStan, PHP-CS-Fixer |

`.env` está versionado y solo contiene valores por defecto de desarrollo
(`APP_TIMEZONE=Europe/Madrid`, `MAILER_DSN=null://null`). Lo real iría en `.env.local` o en el
vault de secretos.

---

## 2. API

Prefijo `/api`. JSON de entrada y salida. Los ids son UUID v7 generados en el servidor.

| Método | Ruta | Body | Respuesta |
|---|---|---|---|
| `POST` | `/api/experiences` | `{title, description, providerId}` | `201` + `Location` |
| `GET` | `/api/experiences/{id}` | — | `200` |
| `PUT` | `/api/experiences/{id}` | `{title, description}` | `200`, o `409` si ya tiene reservas confirmadas |
| `POST` | `/api/experiences/{id}/sessions` | `{startsAt, capacity, price:{amount, currency}}` | `201` + `Location` |
| `GET` | `/api/sessions/{id}` | — | `200` |
| `POST` | `/api/sessions/{id}/bookings` | `{userId, seats}` | `201` + `Location` |
| `GET` | `/api/bookings/{reference}` | — | `200` |
| `POST` | `/api/bookings/{reference}/cancellation` | — | `200`, reserva con `status: cancelled` |

Ocho rutas `api_*`. `make console c="debug:router"` lista once en total: esas ocho, las dos de
Nelmio (`app.swagger`, `app.swagger_ui`, la documentación interactiva) y la `_preview_error` que
Symfony registra solo en `dev` para previsualizar páginas de error. `startsAt` acepta cualquier
instante ISO 8601 con zona explícita (`Z` o un offset numérico como `+02:00`), con o sin segundos
fraccionarios; una hora local sin zona se rechaza por ambigua.

Documentación interactiva (Swagger UI) en [`/api/doc`](http://localhost:8080/api/doc), spec en
crudo en [`/api/doc.json`](http://localhost:8080/api/doc.json): cada endpoint con su cuerpo de
petición, todas las respuestas posibles (éxito y error, con su código y esquema) y ejemplos
tomados del flujo de abajo.

### 2.1 Flujo completo

Las respuestas de abajo son reales; se han partido en varias líneas para leerlas, `curl` las
devuelve en una sola.

Alternativa sin copiar/pegar: [`bruno/`](bruno/) es una colección de [Bruno](https://www.usebruno.com/)
con las mismas peticiones encadenadas y sus casos de error, lista para abrir y ejecutar (o correr
de un tirón con `npx @usebruno/cli run --env local` dentro de `bruno/`). La carpeta
[`bruno/concurrencia/`](bruno/concurrencia/) lanza diez reservas a la vez contra una sesión de
aforo 5 desde un único script con `Promise.all`, para ver la sobreventa (o su ausencia) con un
clic.

**Crear una experiencia**

```bash
curl -i -X POST http://localhost:8080/api/experiences \
  -H 'Content-Type: application/json' \
  -d '{"title":"Ruta en kayak por la Costa Brava",
       "description":"Salida guiada de tres horas desde Tamariu, con material incluido.",
       "providerId":"0199a1b2-c3d4-7000-8000-000000000001"}'
```

```http
HTTP/1.1 201 Created
Location: /api/experiences/01a08d39-1fef-7536-be5f-fedc3a90af56
```
```json
{"id":"01a08d39-1fef-7536-be5f-fedc3a90af56",
 "title":"Ruta en kayak por la Costa Brava",
 "description":"Salida guiada de tres horas desde Tamariu, con material incluido.",
 "providerId":"0199a1b2-c3d4-7000-8000-000000000001"}
```

**Programar una sesión**

```bash
curl -i -X POST http://localhost:8080/api/experiences/01a08d39-1fef-7536-be5f-fedc3a90af56/sessions \
  -H 'Content-Type: application/json' \
  -d '{"startsAt":"2026-11-14T10:00:00+01:00","capacity":12,
       "price":{"amount":4500,"currency":"EUR"}}'
```

```http
HTTP/1.1 201 Created
Location: /api/sessions/01a08d39-45ac-73e7-af33-ceed542a59b5
```
```json
{"id":"01a08d39-45ac-73e7-af33-ceed542a59b5",
 "experienceId":"01a08d39-1fef-7536-be5f-fedc3a90af56",
 "startsAt":"2026-11-14T09:00:00+00:00",
 "capacity":12,"bookedSeats":0,"availableSeats":12,
 "price":{"amount":4500,"currency":"EUR"}}
```

`startsAt` se acepta con offset y se devuelve normalizado a UTC. El precio es **por plaza**, en
la unidad mínima de la divisa (4500 = 45,00 EUR).

**Reservar tres plazas**

```bash
curl -i -X POST http://localhost:8080/api/sessions/01a08d39-45ac-73e7-af33-ceed542a59b5/bookings \
  -H 'Content-Type: application/json' \
  -d '{"userId":"0199a1b2-c3d4-7000-8000-000000000002","seats":3}'
```

```http
HTTP/1.1 201 Created
Location: /api/bookings/BK-86XY6308
```
```json
{"reference":"BK-86XY6308",
 "sessionId":"01a08d39-45ac-73e7-af33-ceed542a59b5",
 "userId":"0199a1b2-c3d4-7000-8000-000000000002",
 "seats":3,"total":{"amount":13500,"currency":"EUR"},
 "status":"confirmed","bookedAt":"2026-09-10T21:29:14+00:00","cancelledAt":null}
```

**La sesión ya refleja las plazas consumidas**

```bash
curl -s http://localhost:8080/api/sessions/01a08d39-45ac-73e7-af33-ceed542a59b5
```
```json
{"id":"01a08d39-45ac-73e7-af33-ceed542a59b5",
 "experienceId":"01a08d39-1fef-7536-be5f-fedc3a90af56",
 "startsAt":"2026-11-14T09:00:00+00:00",
 "capacity":12,"bookedSeats":3,"availableSeats":9,
 "price":{"amount":4500,"currency":"EUR"}}
```

**Consultar la reserva por su referencia pública**

```bash
curl -s http://localhost:8080/api/bookings/BK-86XY6308
```
```json
{"reference":"BK-86XY6308","sessionId":"01a08d39-45ac-73e7-af33-ceed542a59b5",
 "userId":"0199a1b2-c3d4-7000-8000-000000000002",
 "seats":3,"total":{"amount":13500,"currency":"EUR"},
 "status":"confirmed","bookedAt":"2026-09-10T21:29:14+00:00","cancelledAt":null}
```

**Cancelar**

```bash
curl -s -X POST http://localhost:8080/api/bookings/BK-86XY6308/cancellation
```
```json
{"reference":"BK-86XY6308","sessionId":"01a08d39-45ac-73e7-af33-ceed542a59b5",
 "userId":"0199a1b2-c3d4-7000-8000-000000000002",
 "seats":3,"total":{"amount":13500,"currency":"EUR"},
 "status":"cancelled","bookedAt":"2026-09-10T21:29:14+00:00",
 "cancelledAt":"2026-09-10T21:29:30+00:00"}
```

**Y las plazas vuelven al aforo**

```bash
curl -s http://localhost:8080/api/sessions/01a08d39-45ac-73e7-af33-ceed542a59b5
```
```json
{"id":"01a08d39-45ac-73e7-af33-ceed542a59b5",
 "experienceId":"01a08d39-1fef-7536-be5f-fedc3a90af56",
 "startsAt":"2026-11-14T09:00:00+00:00",
 "capacity":12,"bookedSeats":0,"availableSeats":12,
 "price":{"amount":4500,"currency":"EUR"}}
```

### 2.2 Errores

Todos los errores bajo `/api` se devuelven como `application/problem+json` (RFC 7807) con
`type`, `title`, `status`, `detail` y, en validación de forma, `errors[]`.

**422 — regla de negocio violada** (se piden más plazas de las disponibles):

```bash
curl -s -X POST http://localhost:8080/api/sessions/01a08d39-45ac-73e7-af33-ceed542a59b5/bookings \
  -H 'Content-Type: application/json' \
  -d '{"userId":"0199a1b2-c3d4-7000-8000-000000000002","seats":99}'
```
```json
{"type":"\/problems\/not-enough-seats-available",
 "title":"Not enough seats available","status":422,
 "detail":"Session <01a08d39-45ac-73e7-af33-ceed542a59b5> has 12 seats available, 99 requested."}
```

**400 — la petición no tiene la forma esperada** (el DTO de entrada no valida):

```bash
curl -s -X POST http://localhost:8080/api/sessions/01a08d39-45ac-73e7-af33-ceed542a59b5/bookings \
  -H 'Content-Type: application/json' \
  -d '{"userId":"no-es-un-uuid","seats":0}'
```
```json
{"type":"\/problems\/validation-failed","title":"Validation failed","status":400,
 "detail":"The request payload is invalid.",
 "errors":[{"field":"userId","message":"This is not a valid UUID."},
           {"field":"seats","message":"This value should be positive."}]}
```

La distinción es deliberada: **400 es forma** (tipos, formato, presencia; Symfony Validator sobre
el DTO de request) y **422 es dominio** (la petición es válida pero el agregado la rechaza).

**404** y **409** siguen el mismo formato:

```json
{"type":"\/problems\/booking-not-found","title":"Booking not found","status":404,
 "detail":"Booking <BK-00000000> not found."}
```
```json
{"type":"\/problems\/session-already-scheduled-for-day",
 "title":"Session already scheduled for day","status":409,
 "detail":"Experience <01a08d39-1fef-7536-be5f-fedc3a90af56> already has a session on 2026-11-14."}
```

Tabla completa:

| Situación | Código |
|---|---|
| `ExperienceNotFound`, `SessionNotFound`, `BookingNotFound` | 404 |
| `SessionAlreadyScheduledForDay`, `ExperienceHasBookings`, `BookingReferenceExhausted` | 409 |
| `SessionInThePast`, `SessionAlreadyStarted`, `NotEnoughSeatsAvailable`, `BookingAlreadyCancelled`, `CancellationWindowClosed`, `BookingDoesNotBelongToSession` | 422 |
| Validación de forma del request, `InvalidValue` | 400 |
| `lock_timeout` agotado sobre la fila de la sesión | 503 + `Retry-After` |

El mapeo vive en un único sitio, `Shared/Infrastructure/Symfony/ProblemJsonExceptionListener`,
por jerarquía de excepción (`NotFoundException` → 404, `ConflictException` → 409,
`InvalidValue` → 400, resto de `DomainException` → 422). Añadir una excepción de dominio no
obliga a tocar el listener.

---

## 3. Arquitectura

### 3.1 Módulos y capas

Un bounded context, tres módulos y un kernel compartido. Cada módulo repite las mismas tres
capas y la dependencia siempre apunta hacia dentro:

```
src/
├── Shared/       AggregateRoot, DomainEvent, Clock, Uuid, Money, jerarquía de excepciones,
│                 TransactionalRunner y DomainEventPublisher (puertos), adaptadores Doctrine/Messenger/Symfony
├── Experience/   Domain · Application · Infrastructure
├── Session/      Domain · Application · Infrastructure
└── Booking/      Domain · Application · Infrastructure
```

- **Domain**: agregados (`Experience`, `Session`, `Booking`), value objects, eventos,
  excepciones e **interfaces de puerto**. Cero Doctrine y cero framework, con una única
  excepción deliberada: `Shared/Domain/Uuid.php` usa `symfony/uid` para generar UUIDv7, porque
  escribir a mano un generador de UUIDv7 sería peor que depender de un componente bien probado.
  Está confinada a ese fichero: ninguna otra clase de dominio importa nada de Symfony
  (`grep -rn "use Symfony" src/*/Domain` devuelve solo esa línea).
- **Application**: un caso de uso = `Command`/`Query` readonly + `Handler` + `Response` DTO. Los
  handlers nunca devuelven agregados.
- **Infrastructure**: controladores HTTP y DTOs de request, repositorios Doctrine, tipos
  custom, adaptadores de correo y de contacto.

El dominio se mantiene libre de Doctrine con **mapping XML** en `config/doctrine/*.orm.xml` y
tipos custom (`SessionIdType`, `BookingReferenceType`, `CapacityType`…) que traducen los value
objects. No hay atributos de ORM en `src/`; los agregados no saben que existe una base de datos.

Los **DTOs marcan cada frontera**: `BookSeatsRequest` (HTTP → aplicación), `BookSeatsCommand`
(aplicación), `BookingResponse` (aplicación → HTTP), `BookingEmail` (aplicación → mailer),
`toPrimitives()` de los eventos (dominio → Messenger). Dentro del dominio solo circulan
agregados y value objects.

### 3.2 Puertos y adaptadores

| Puerto | Dónde se declara | Adaptador de producción | Adaptador de test |
|---|---|---|---|
| `ExperienceRepository` | `Experience/Domain` | `DoctrineExperienceRepository` | `InMemoryExperienceRepository` |
| `SessionRepository` (con `findForUpdate`) | `Session/Domain` | `DoctrineSessionRepository` | `InMemorySessionRepository` |
| `BookingRepository` (con `findByReference`, `existsConfirmedForExperience`) | `Booking/Domain` | `DoctrineBookingRepository` | `InMemoryBookingRepository` |
| `BookingReferenceGenerator` | `Booking/Domain` | `RandomBookingReferenceGenerator` | `Sequential…` / `AlwaysSame…` |
| `UserContactProvider` | `Booking/Domain` | `FakeUserContactProvider` (simulado) | el mismo |
| `MailerPort` | `Booking/Domain` | `SymfonyMailerAdapter` | `InMemoryMailer` |
| `SentNotificationRegistry` | `Booking/Domain` | `DbalSentNotificationRegistry` | `InMemory…` |
| `Clock` | `Shared/Domain` | `SystemClock` | `FixedClock` |
| `TransactionalRunner` | `Shared/Application` | `DoctrineTransactionalRunner` | `InMemoryTransactionalRunner` |
| `DomainEventPublisher` | `Shared/Application` | `MessengerDomainEventPublisher` | `InMemoryDomainEventPublisher` |

Todo se conecta por autowiring y `#[AsAlias]`; no hay definiciones de servicio a mano.

### 3.3 Flujo de una reserva

```
POST /api/sessions/{id}/bookings
  └─ BookSeatsController          valida BookSeatsRequest (#[MapRequestPayload]), genera el BookingId
       └─ BookSeatsCommand
            └─ BookSeatsHandler
                 ├─ referencia única: generator->next() + existsByReference   (fuera de la transacción)
                 └─ TransactionalRunner::run(…)                BEGIN; SET LOCAL lock_timeout = '2000ms'
                      ├─ SessionRepository::findForUpdate()    SELECT … FOR UPDATE  ← aquí encolan los rivales
                      ├─ Session::book(...) → Booking          reglas de aforo y de fecha
                      │     └─ Booking::confirm() registra BookingConfirmed
                      ├─ save(session), save(booking)
                      └─ DomainEventPublisher::publish(BookingConfirmed)
                            └─ Messenger, transporte Doctrine  INSERT en messenger_messages
                                                               COMMIT  ← reserva y correo, atómicos
       └─ 201 + Location: /api/bookings/BK-…

worker: php bin/console messenger:consume async
  └─ SendBookingConfirmationEmailOnBookingConfirmed
       ├─ SentNotificationRegistry::wasSent(reference, type)?  → si sí, termina
       ├─ UserContactProvider::emailFor(userId)                → FakeUserContactProvider
       ├─ MailerPort::send(BookingEmail)                       → SymfonyMailerAdapter (DSN null://)
       └─ SentNotificationRegistry::markSent(reference, type)
```

La generación de la referencia se hace **antes** de abrir la transacción a propósito: la
comprobación de unicidad es una lectura que no necesita correr con el bloqueo de fila cogido, y
el índice único de `bookings.reference` es la red de seguridad final. Cancelar es simétrico:
`findByReference` → `findForUpdate` de su sesión → `Session::cancelBooking` → `BookingCancelled`.

---

## 4. Reglas de negocio y dónde viven

Todas las reglas están en el dominio; ningún controlador ni handler decide nada de negocio.

| Regla | Dónde vive | Test |
|---|---|---|
| Una sesión no puede programarse en el pasado | `Session::schedule()` → `SessionInThePast` | `SessionTest::it_rejects_sessions_in_the_past_or_now` |
| Una experiencia no puede tener dos sesiones el mismo día | `ScheduleSessionHandler` + índice único `(experience_id, day)` | `ScheduleSessionHandlerTest`, `DoctrineSessionRepositoryTest::unique_index_is_translated_to_domain_exception` |
| No se puede reservar más de lo disponible | `Session::book()` → `NotEnoughSeatsAvailable` | `SessionTest::it_refuses_to_overbook`, `it_allows_booking_exactly_the_remaining_seats`, `overbooking_leaves_seat_counts_unchanged` |
| No se puede reservar una sesión ya empezada | `Session::book()` → `SessionAlreadyStarted` | `SessionTest::it_refuses_booking_once_started` |
| Reservar consume plazas y calcula el total (precio × plazas) | `Session::book()` + `Money::multiply()` | `SessionTest::it_books_seats_and_computes_total`, `MoneyTest` |
| Cancelar libera las plazas | `Session::cancelBooking()` | `SessionTest::cancelling_releases_seats` |
| No se puede cancelar si faltan menos de 24 h | `Session::cancelBooking()` → `CancellationWindowClosed` | `SessionTest::it_refuses_cancellation_within_24_hours`, `cancellation_within_the_window_leaves_the_booking_confirmed_and_seats_consumed` |
| No se puede cancelar dos veces | `Booking::cancel()` → `BookingAlreadyCancelled` | `SessionTest::it_refuses_double_cancellation_before_checking_the_window`, `BookingTest::it_cancels_once` |
| Una reserva solo se cancela desde su propia sesión | `Session::cancelBooking()` → `BookingDoesNotBelongToSession` | `SessionTest::it_refuses_bookings_of_other_sessions` |
| Una experiencia solo es editable sin reservas confirmadas | `Experience::update(…, ExperienceEditability)` → `ExperienceHasBookings` | `ExperienceTest::it_updates_when_editable`, `it_refuses_update_when_locked_by_bookings` |
| Los value objects no pueden existir inválidos | constructores de `Title`, `Seats`, `Capacity`, `Money`, `BookingReference`, `Uuid`… | `TitleTest`, `SeatsTest`, `MoneyTest`, `BookingReferenceTest`, `UuidTest`, `StartsAtTest`, `SessionDayTest` |

`ExperienceEditability` merece una nota: el dato ("¿tiene reservas confirmadas?") vive en el
módulo `Booking`, pero la **decisión** no puede vivir en el caso de uso sin vaciar el agregado.
El handler consulta `BookingRepository::existsConfirmedForExperience()`, construye el value
object `ExperienceEditability::editable()`/`locked()` y se lo pasa a `Experience::update()`, que
es quien lanza. La regla sigue siendo del agregado; solo el dato viene de fuera.

---

## 5. Concurrencia

Es el punto central del ejercicio, así que conviene ser explícito sobre qué garantiza qué.

### 5.1 La solución

`BookSeatsHandler` hace **todo** dentro de un único `TransactionalRunner::run()`, y lo primero
que hace dentro es cargar la sesión con `SELECT … FOR UPDATE`
(`DoctrineSessionRepository::findForUpdate`, `LockMode::PESSIMISTIC_WRITE`). Cualquier otra
petición sobre **esa misma** sesión se queda esperando en esa línea hasta que la primera hace
commit; las peticiones sobre otras sesiones no se ven afectadas, porque el bloqueo es de fila.

Se comprobó con dos conexiones simultáneas: la segunda se queda de verdad en espera —
`pg_stat_activity` la muestra bloqueada en `Lock`/`transactionid` — y se libera unos 40 ms
después de que la primera confirme.

Para no acumular peticiones sobre una fila muy caliente, el runner ejecuta
`SET LOCAL lock_timeout = '2000ms'`. Si la espera se agota, PostgreSQL devuelve `55P03`
(`lock_not_available`) y el listener lo traduce a `503` con `Retry-After: 1`: mejor decirle al
cliente que reintente que dejarle la conexión colgada.

### 5.2 Lo que el `CHECK` **no** hace

La tabla `sessions` tiene `CHECK (booked_seats >= 0 AND booked_seats <= capacity)`, y es
tentador presentarlo como la segunda red contra la sobreventa. **No lo es**, y merece la pena
decirlo porque es contraintuitivo: Doctrine escribe **valores absolutos**
(`UPDATE sessions SET booked_seats = 8`), no incrementos. Si dos transacciones leyeran 7 sin
bloqueo, ambas escribirían 8 y el `CHECK` estaría satisfecho: la actualización perdida pasaría
desapercibida. El `CHECK` solo detecta un fallo *dentro* del agregado (un cálculo que se salga
de rango), no una carrera entre transacciones.

**El bloqueo de fila es el único mecanismo que evita la sobreventa.** El `CHECK` es una
aserción sobre la integridad del dato, no un control de concurrencia.

### 5.3 Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| **Bloqueo optimista** (columna `version`) | Se comporta peor justo en el caso que describe el enunciado: en una sesión muy demandada casi todas las peticiones chocan y hay que reintentarlas, con lo que el trabajo útil por intento cae y la latencia se dispara. El pesimista serializa una vez y ya está |
| **`UPDATE` atómico condicional** (`SET booked_seats = booked_seats + :n WHERE booked_seats + :n <= capacity`) | Es lo más rápido, pero traslada la regla de aforo a una sentencia SQL. El agregado deja de ser quien decide si se puede reservar, y las reglas de negocio se parten entre PHP y la base de datos |
| **Cola serializada por sesión** | Complejidad de infraestructura desproporcionada para lo que resuelve una fila bloqueada durante milisegundos |

### 5.4 Cómo reproducirlo

`bin/concurrency-test` crea una experiencia y una sesión de aforo *M*, lanza *N* reservas de una
plaza contra la API con `N > M` y comprueba tres cosas: cuántas devolvieron `201`, cuántas `422`
y en qué quedó `bookedSeats`.

Que las peticiones salgan **de verdad a la vez** requiere un detalle fácil de pasar por alto:
`HttpClient::create()` limita por defecto a 6 conexiones por host, así que una sonda ingenua
que "lanza 60" en realidad las va encolando de seis en seis en el cliente, no en la base de
datos. La sonda sube `maxHostConnections` al número de intentos; muestreando
`pg_stat_activity` a mitad de una ejecución se ven **19 transacciones simultáneas esperando el
bloqueo de fila** (antes del ajuste eran 5).

```console
$ make test-concurrency
docker compose exec -T -e CAPACITY=10 -e ATTEMPTS=60 php php bin/concurrency-test
Session 01a08d4a-0a74-7e39-ab73-3f8a2e5d97bb with capacity 10 — firing 60 bookings (up to 60 in flight)…
  HTTP 201: 10
  HTTP 422: 50
Booked seats: 10 / 10 (available: 0)
OK — no overbooking.
```

El caso extremo, aforo 1 y 100 peticiones simultáneas:

```console
$ make test-concurrency CAPACITY=1 ATTEMPTS=100
docker compose exec -T -e CAPACITY=1 -e ATTEMPTS=100 php php bin/concurrency-test
Session 01a08d4a-8782-7c60-b5d6-2caf6523dba6 with capacity 1 — firing 100 bookings (up to 100 in flight)…
  HTTP 201: 1
  HTTP 422: 99
Booked seats: 1 / 1 (available: 0)
OK — no overbooking.
```

Exactamente una reserva gana; las otras 99 reciben un 422 correcto, no un error de servidor.

### 5.5 Control negativo: la sonda mide algo

Una sonda que pasa solo demuestra algo si también sabe fallar. Se comprobó a mano sustituyendo
`findForUpdate()` por un `find()` sin `LockMode::PESSIMISTIC_WRITE` y relanzando la sonda con
aforo 10 y 60 intentos; después se restauró el árbol de trabajo. Sin el bloqueo, la API devolvió
**`HTTP 201` sesenta veces** —50 plazas vendidas de más— y la sonda terminó con código distinto
de cero.

El detalle que importa: en esa ejecución rota `bookedSeats` seguía leyéndose **10/10, con 0
disponibles**. La actualización perdida se esconde dentro del propio contador, así que una
comprobación que solo mirase las plazas almacenadas —o el `CHECK` de la tabla, por lo dicho en
§5.2— habría dado el visto bueno igualmente. Lo único que detecta la sobreventa es la aserción
`recuento de 201 === aforo`, y por eso la sonda la hace.

### 5.6 Y si la base de datos fuera MySQL

Se eligió PostgreSQL porque el problema es puramente transaccional (`FOR UPDATE`, `CHECK`,
índices únicos) y porque `SET LOCAL lock_timeout` permite acotar la espera del bloqueo con
granularidad de milisegundos y solo para esa transacción. Nada del diseño depende de eso: el
dominio y la aplicación no saben qué motor hay debajo, y todo lo específico del motor está en
`Infrastructure` y en `migrations/`.

Sobre MySQL 8 / InnoDB el mecanismo central es el mismo: `SELECT … FOR UPDATE` bloquea la fila
de la sesión y serializa a los rivales igual que aquí. Los cambios serían de esquema y de
detalle. Las restricciones `CHECK` se aplican desde **MySQL 8.0.16** (antes se parseaban y se
ignoraban en silencio), así que en una versión anterior la aserción de §5.2 simplemente no
existiría. No hay tipo `uuid` nativo: los ids pasarían a `BINARY(16)` —compacto, pero ilegible
en consola— o `CHAR(36)`; el cambio se absorbe en los tipos custom de DBAL
(`UuidType` y compañía), sin tocar mapping ni dominio. `TIMESTAMP(0) WITH TIME ZONE` no existe:
se guardaría `DATETIME` en UTC. Y el `lock_timeout` de 2 s se convierte en
`innodb_lock_wait_timeout`, que es de sesión y se expresa en **segundos** (mínimo 1), con lo que
se pierde la granularidad fina; a cambio, MySQL devuelve el error `1205`, que DBAL sí traduce a
`LockWaitTimeoutException` —el listener ya contempla esa forma, precisamente porque el driver de
PostgreSQL no convierte su `55P03`—.

El índice único `(experience_id, day)` funcionaría tal cual, porque `day` es una columna `DATE`
real que escribe la aplicación al programar la sesión, no una expresión derivada de `starts_at`.
Si se hubiera derivado, en MySQL habría hecho falta una columna generada o un índice funcional
(8.0.13+) para poder indexarla.

---

## 6. Correo

Al confirmar y al cancelar una reserva se envía un correo al email de contacto del usuario,
siguiendo el **patrón outbox**: el evento de dominio (`BookingConfirmed` / `BookingCancelled`)
se despacha al transporte Doctrine de Messenger **con la misma conexión y dentro de la misma
transacción** que la reserva, de modo que se inserta en `messenger_messages` o no existe. Si el
commit falla no sale correo; si el commit va bien, el correo no se pierde aunque el worker esté
caído.

Eso no es solo una afirmación de diseño: `tests/Integration/Booking/OutboxAtomicityTest.php`
monta un transporte `doctrine://` real y comprueba una fila al confirmar y cero al hacer
rollback.

Los handlers son asíncronos (`messenger:consume async`, que `make up` arranca) e **idempotentes**:
consultan y marcan la tabla `sent_notifications` con clave `(booking_reference, type)`, porque
Messenger garantiza entrega *al menos una vez*. Solo hay **dos cosas simuladas**, y las dos son
adaptadores detrás de un puerto: el destinatario (`FakeUserContactProvider`) y el transporte SMTP
(`MAILER_DSN=null://null`). Cambiarlas no toca dominio ni aplicación.

El detalle completo — qué prueba exactamente cada test y qué no prueba — está en
[`docs/design/email-notifications.md`](docs/design/email-notifications.md).

Nota relacionada: **todo evento de dominio tiene handler**. Los que aún no tienen consumidor de
negocio (`ExperienceRegistered`, `ExperienceUpdated`, `SessionScheduled`) los recoge
`Shared/Infrastructure/Messenger/LogDomainEvent`, que los registra. Así ningún mensaje acaba
acumulándose en el transporte `failed` por falta de handler.

**Sobre el transporte.** Los eventos de dominio ya viajan por Symfony Messenger; el transporte
`doctrine://` se usa aquí porque es lo que hace atómico el outbox (misma conexión, misma
transacción) y porque no añade un contenedor más a la prueba. Pasar a RabbitMQ (AMQP) o a SQS es
cambiar `MESSENGER_TRANSPORT_DSN` e instalar el paquete correspondiente
(`symfony/amqp-messenger`, `symfony/amazon-sqs-messenger`): ni el dominio ni la capa de
aplicación cambian, porque publican contra el puerto `DomainEventPublisher` y no conocen el
transporte. Lo que sí habría que decidir entonces es cómo se mantiene la atomicidad, que es
justamente lo que el patrón outbox resuelve: seguir escribiendo en la tabla local dentro de la
transacción y que un relay la vuelque al broker.

---

## 7. Decisiones y supuestos sobre el enunciado

El enunciado deja varias cosas abiertas. Estas son las interpretaciones que se han tomado y por
qué; cambiar cualquiera de ellas es un cambio localizado.

| Tema | Decisión | Motivo |
|---|---|---|
| **Ventana de 24 h** | No se puede cancelar si faltan **menos de 24 h** para el inicio (`now > startsAt - 24h`) | Es la lectura razonable de "hasta 24 h antes". A exactamente 24 h todavía se puede cancelar |
| **"Mismo día"** | Se calcula en una zona horaria de plataforma configurable (`APP_TIMEZONE`, por defecto `Europe/Madrid`); las fechas se guardan en UTC | Sin una zona explícita, "mismo día" no significa nada. Se snapshotea al programar la sesión, porque el índice único `(experience_id, day)` depende de que ese valor no cambie |
| **Precio** | Por plaza; `total = precio × plazas`. `Money` en la unidad mínima (céntimos) + divisa ISO 4217 | El enunciado no dice si el precio es por plaza o por reserva; por plaza es lo habitual. Enteros para no meter flotantes en dinero |
| **Email de contacto** | Puerto `UserContactProvider` que resuelve el email a partir del `UserId`, con adaptador fake determinista | El usuario no se modela (vive en otro contexto). Guardar el email en la reserva sería duplicar el dato maestro de otro servicio |
| **Referencia de reserva** | Referencia pública `BK-` + 8 caracteres Crockford base32 (sin `I L O U`); el `BookingId` UUID es interno | Una persona tiene que poder leerla por teléfono y teclearla. Crockford evita las confusiones típicas. El UUID no se expone nunca |
| **Cancelación** | `POST /api/bookings/{ref}/cancellation`, no `DELETE` | La reserva no desaparece: cambia de estado y conserva `cancelledAt`. `DELETE` mentiría sobre lo que ocurre |
| **Límites de reserva** | Ninguno más allá de los del enunciado: `seats >= 1` y `seats <= disponibles`. Un usuario puede reservar varias veces la misma sesión | El enunciado no pone tope. Inventar uno sería inventar negocio |
| **Autenticación** | No implementada; `providerId` y `userId` llegan en el body | El enunciado la excluye explícitamente. Las consecuencias están en §8 |
| **Dos agregados por transacción** | Reservar y cancelar modifican `Session` y `Booking` en la misma transacción | Ver abajo |

### 7.1 La concesión consciente

La ortodoxia DDD dice una transacción, un agregado, y comunicar los agregados por consistencia
eventual. Aquí no: reservar toca `Session` (el contador de plazas) y `Booking` en el mismo
commit.

Se ha decidido así porque la alternativa purista abre exactamente la ventana que el enunciado
pide cerrar: existiría, aunque fuera durante milisegundos, una reserva confirmada cuya plaza
todavía no está descontada. Con suficiente concurrencia eso *es* sobreventa. Ambos agregados
viven en la misma base de datos y el enunciado exige consistencia fuerte en el aforo, así que la
transacción compartida es el coste correcto. Está aquí escrito para que se vea que es una
elección, no un descuido.

### 7.2 Un añadido deliberado: `PUT /api/experiences/{id}`

Editar una experiencia **no está en el enunciado**. Se ha incluido porque es la ocasión más
clara de enseñar un agregado defendiendo un invariante en lugar de un update CRUD: una
experiencia solo es editable mientras ninguna de sus sesiones tenga una reserva **confirmada**
(las canceladas no bloquean). No es "guardar los campos que vengan"; es una regla que el
agregado puede rechazar.

```bash
# Sin reservas confirmadas: se edita
curl -s -X PUT http://localhost:8080/api/experiences/01a08d39-1fef-7536-be5f-fedc3a90af56 \
  -H 'Content-Type: application/json' \
  -d '{"title":"Ruta en kayak por la Costa Brava (media jornada)",
       "description":"Salida guiada de tres horas desde Tamariu, con material incluido."}'
```
```json
{"id":"01a08d39-1fef-7536-be5f-fedc3a90af56",
 "title":"Ruta en kayak por la Costa Brava (media jornada)",
 "description":"Salida guiada de tres horas desde Tamariu, con material incluido.",
 "providerId":"0199a1b2-c3d4-7000-8000-000000000001"}
```

```bash
# Tras confirmar una reserva en una de sus sesiones: 409
curl -s -X PUT http://localhost:8080/api/experiences/01a08d39-1fef-7536-be5f-fedc3a90af56 \
  -H 'Content-Type: application/json' \
  -d '{"title":"Otro titulo","description":"Otra descripcion."}'
```
```json
{"type":"\/problems\/experience-has-bookings","title":"Experience has bookings","status":409,
 "detail":"Experience <01a08d39-1fef-7536-be5f-fedc3a90af56> cannot be edited because it already has confirmed bookings."}
```

---

## 8. Lo que haría en producción

Por orden de urgencia.

**1. Autenticación y autorización.** Es lo primero y con diferencia. La referencia `BK-…` es
corta y enumerable, y hoy **cualquiera que la adivine puede cancelar la reserva**: no hay
concepto de dueño. En producción haría falta autenticar al usuario y un voter que compruebe que
`booking.userId` es quien pide la cancelación, y lo mismo para el proveedor al editar sus
experiencias. Está fuera del alcance del enunciado, por eso no está implementado, pero no es un
detalle menor: es el hueco más grande que tiene esta API.

**2. Rate limiting (429).** Symfony RateLimiter (o el API gateway) por `userId`/IP sobre
`POST /sessions/{id}/bookings` y `POST /bookings/{ref}/cancellation`. Es la segunda capa contra
la fuerza bruta de referencias y contra bots acaparando plazas en sesiones muy demandadas. La
autenticación es la primera capa; esta limita el daño mientras tanto.

**3. Read models / CQRS para el catálogo.** Listar y buscar experiencias con filtros y
paginación no debería pasar por los agregados. Una proyección desnormalizada, alimentada por los
mismos eventos de dominio que ya se publican, separa la lectura (masiva, cacheable) de la
escritura (transaccional).

**4. Envío real de correo, con reintentos y dead-letter.** Cambiar `MAILER_DSN` a un SMTP o API
real es literalmente eso, cero código. Los reintentos (3, con backoff) y el transporte `failed`
ya están configurados; lo que faltaría es vigilarlo y tener un procedimiento para reprocesar.

**5. Observabilidad.** Logs estructurados con la referencia de reserva como campo correlacionable
de punta a punta, y métricas sobre lo que aquí importa: tiempo de espera en el bloqueo de fila,
tasa de 503 por `lock_timeout`, profundidad de la cola de Messenger, edad del mensaje más viejo
en `failed`. Sin eso, "la API va lenta" no se puede diagnosticar.

**6. Zona horaria por experiencia.** `APP_TIMEZONE` única es correcta para un operador nacional.
Con proveedores internacionales, "mismo día" tiene que calcularse en la zona del proveedor: el
cambio es un campo en `Experience` y pasarlo a `Session::schedule()`.

**7. Límite de plazas por reserva.** Hoy no hay tope, porque el enunciado no lo pide. Si negocio
quiere uno, es un value object más y una regla en `Session::book()`.

**8. Integración continua.** Un pipeline que ejecutase en cada push exactamente los mismos
objetivos que se ejecutan en local —`make test`, `make stan`, `make cs` y `make test-concurrency`
contra los contenedores— y bloquease el merge si alguno falla. No está montado aquí a propósito:
una prueba técnica no necesita pipeline, y los objetivos ya son el contrato; el fichero de CI
sería envoltorio.

**Una deuda concreta que no quiero que parezca un olvido.** La referencia de reserva se genera y
se comprueba contra `existsByReference` antes de abrir la transacción; entre esa comprobación y
el `INSERT` hay una ventana de carrera. Si dos peticiones generasen la misma referencia (con 8
caracteres base32, del orden de 1e-12 por par) la segunda violaría el índice único
`uniq_bookings_reference` y hoy eso sale como **500**, no como un estado reintentable. Es un
intercambio deliberado — capturar `UniqueConstraintViolationException` y reintentar es sencillo,
pero añade un camino que ningún test puede ejercitar de forma realista. El índice único garantiza
que **nunca hay dos reservas con la misma referencia**; lo único imperfecto es cómo se le cuenta
al cliente.

---

## 9. Calidad

```bash
make test              # 145 tests
make stan              # PHPStan nivel max
make cs                # PHP-CS-Fixer, @Symfony + @PER-CS2.0
make test-concurrency  # sonda de concurrencia contra la API real
```

**Tests: 145, 1599 aserciones.**

| Suite | Tests | Qué cubre |
|---|---|---|
| `tests/Unit` | 97 | Reglas de negocio sobre agregados y value objects; handlers con repositorios in-memory, `FixedClock` e `InMemoryMailer`. Sin base de datos, milisegundos |
| `tests/Integration` | 16 | Repositorios Doctrine contra PostgreSQL real: round-trip de value objects, `findForUpdate`, el índice único traducido a excepción de dominio, la idempotencia del registro DBAL y la atomicidad del outbox |
| `tests/Functional` | 32 | `WebTestCase` sobre los 8 endpoints de negocio (éxitos, cada código de error, formato `problem+json`, cabecera `Location`, correos generados) más la documentación OpenAPI, comprobada contra el router real para que un endpoint nuevo sin documentar rompa la suite |
| `bin/concurrency-test` | — | Sonda fuera de PHPUnit: procesos concurrentes reales contra la API dockerizada (`make test-concurrency`) |

La sonda de concurrencia está deliberadamente fuera de PHPUnit: lo que se quiere demostrar es
que **PostgreSQL** serializa peticiones HTTP simultáneas, y eso no se puede probar dentro de una
única transacción de test envuelta en rollback.

**Análisis estático: PHPStan nivel `max` sobre `src`, `tests` y `bin/concurrency-test`, sin una
sola supresión.** No hay `@phpstan-ignore` ni `@phpstan-var` en ninguno de los tres árboles, ni
baseline; la única entrada en `ignoreErrors` es `method.unused` sobre `src/Kernel.php`, que es un
método del propio esqueleto de Symfony. Se ejecuta con las extensiones de Symfony y de Doctrine,
así que también valida DQL y los tipos del contenedor.

**Estilo:** PHP-CS-Fixer con `@Symfony` + `@Symfony:risky` + `@PER-CS2.0`, más
`declare(strict_types=1)`, `strict_comparison` y `strict_param` obligatorios. `make cs` en seco,
`make cs-fix` para corregir.

### 9.1 `doctrine:schema:validate`: mapping limpio, base de datos deliberadamente por delante

`make console c="doctrine:schema:validate"` informa de dos cosas distintas:

```
Mapping  [OK] The mapping files are correct.
Database [ERROR] The database schema is not in sync with the current mapping file.
```

**El mapping está limpio.** Lo que la sección `Database` señala no es deriva accidental: es que
**las migraciones son la fuente de verdad del esquema** y contienen restricciones que el mapping
no modela a propósito. `doctrine:schema:update --dump-sql` enumera exactamente lo que borraría:

| Lo que Doctrine querría hacer | Por qué está así |
|---|---|
| `DROP TABLE sent_notifications` | Tabla de idempotencia de correo, no un agregado. La gestiona `DbalSentNotificationRegistry` con SQL directo; mapearla como entidad sería inventar un agregado que el dominio no tiene |
| `DROP CONSTRAINT fk_sessions_experience`, `fk_bookings_session` (y los `DROP INDEX IDX_…` que Doctrine asocia implícitamente a cada FK) | Integridad referencial en la base de datos. El mapping no declara asociaciones ORM porque los agregados se referencian **por id** (`ExperienceId`, `SessionId`), no por objeto: es la regla de agregados de DDD, no un olvido |
| `DROP INDEX uniq_sessions_experience_day` | Es el índice único que hace cumplir "una sesión por experiencia y día" bajo concurrencia (§5). Se traduce a `SessionAlreadyScheduledForDay` |
| `DROP INDEX idx_experiences_provider`, `idx_bookings_user`, `idx_bookings_session_status` | Índices de rendimiento para las consultas reales. Un índice es una decisión de la base de datos, no del modelo de dominio |
| `ALTER TABLE sessions ALTER booked_seats DROP DEFAULT` | `DEFAULT 0` protege inserciones que no pasen por el ORM |
| `CHAR(3)` → `VARCHAR(3)`, `CHAR(11)` → `VARCHAR(11)` | Las columnas de longitud fija (código ISO 4217, referencia `BK-XXXXXXXX`) se declaran `CHAR` en la migración; Doctrine solo sabe generar `VARCHAR` |
| `ALTER INDEX uniq_bookings_reference RENAME TO UNIQ_7A853C35AEA34913` | Nombre autogenerado por Doctrine frente al nombre explícito y legible de la migración |

Es decir: la base de datos tiene **más** garantías que el mapping, no menos. Sincronizarlas
significaría degradar el esquema para satisfacer a una herramienta, y `doctrine:schema:update`
no se ejecuta nunca en este proyecto (§11: *el esquema nunca se toca a mano*).

---

## 10. Cómo se ha construido

El orden de trabajo fue especificación → plan por fases → TDD → revisión independiente. En
[`docs/design/`](docs/design/) están la especificación y los supuestos que se tomaron sobre el
enunciado antes de escribir código; en
[`docs/plans/2026-09-10-experience-booking-api/`](docs/plans/2026-09-10-experience-booking-api/),
el plan de implementación fase a fase que el trabajo siguió realmente (los mensajes de commit
van en ese mismo orden). Los tests se escribieron antes que la implementación en todas las
fases de dominio y aplicación.

Se ha construido con asistencia de IA (Claude Code) dentro de ese flujo dirigido por
especificación, con [`AGENTS.md`](AGENTS.md) como fichero de convenciones del que parte el
asistente, y con cada tarea revisada de forma independiente contra su enunciado antes de darla
por buena.

Lo que esa revisión encontró es la parte interesante, así que conviene decirlo en concreto. La
rama que traduce el `lock_timeout` a `503` era **código muerto sobre PostgreSQL**: capturaba
solo `LockWaitTimeoutException`, y el driver de PostgreSQL de DBAL no convierte el SQLSTATE
`55P03` —no tiene un `case` para él—, así que devolvía un plain `DriverException` y la respuesta
habría sido un `500` (commit `6e822cd`, que añade la comprobación del SQLSTATE). Una primera
implementación de los tipos custom de DBAL necesitaba una decena de supresiones de PHPStan en
línea; se rediseñó con interfaces explícitas para no necesitar ninguna (mismo commit). Ningún
test fijaba que las escrituras ocurren **dentro** de la transacción con el bloqueo cogido —los
dobles in-memory pasaban igual con la escritura fuera—, hasta que se añadió uno que lo
comprueba (commit `6921150`). Y la API rechazaba `2026-09-14T10:00:00Z`, un instante ISO 8601
perfectamente válido, porque el DTO exigía el formato ATOM exacto; no se vio hasta que una
colección de Bruno intentó usar la salida de `toISOString()` (commit `7784cb2`).

---

## 11. Estructura del repositorio

```
src/                  código de la aplicación (ver §3)
config/doctrine/      mapping XML: mantiene el dominio libre de Doctrine
migrations/           Doctrine Migrations; el esquema nunca se toca a mano
tests/                Unit · Integration · Functional · Doubles
bin/concurrency-test  sonda de concurrencia
docker/, compose.yaml php-fpm, nginx, postgres, worker de Messenger
docs/design/          especificación y diseño del correo
docs/plans/           plan de ejecución por fases
```
