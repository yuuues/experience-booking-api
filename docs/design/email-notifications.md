# Notificaciones por correo: cómo están resueltas

El enunciado pide que al crear o cancelar una reserva se envíe un correo al email de contacto,
y aclara que "no hace falta que el envío sea real, basta con el planteamiento del código".
Este documento explica qué está implementado de verdad, qué está simulado y por qué.

## Resumen

| Pieza | Estado | Dónde |
|---|---|---|
| Disparo del correo | Real: eventos de dominio publicados en la misma transacción que la reserva | `Session::book()` / `cancelBooking()` → `BookingConfirmed` / `BookingCancelled` |
| Transporte de eventos | Real: Symfony Messenger con transporte Doctrine (patrón outbox) | `MessengerDomainEventPublisher`, `config/packages/messenger.yaml` |
| Handlers de correo | Reales y asíncronos, idempotentes | `SendBookingConfirmationEmailOnBookingConfirmed`, `SendBookingCancellationEmailOnBookingCancelled` |
| Resolución del destinatario | **Simulada** (puerto con adaptador fake) | `UserContactProvider` → `FakeUserContactProvider` |
| Composición del correo | Real: `Symfony\Component\Mime\Email` con asunto y cuerpo | `SymfonyMailerAdapter` |
| Envío SMTP | **Simulado por configuración**: `MAILER_DSN=null://null` | `.env` |
| Idempotencia | Real: tabla `sent_notifications` | `SentNotificationRegistry` → `DbalSentNotificationRegistry` |

Solo hay dos puntos simulados, y los dos son adaptadores detrás de un puerto: cambiarlos no
toca ni el dominio ni la capa de aplicación.

## Flujo completo de una reserva

```
POST /api/sessions/{id}/bookings
  └─ BookSeatsController → BookSeatsCommand
       └─ BookSeatsHandler (dentro de TransactionalRunner::run)
            ├─ SessionRepository::findForUpdate(sessionId)      SELECT … FOR UPDATE
            ├─ Session::book(...) → Booking                     reglas de aforo y de fecha
            │     └─ Booking registra BookingConfirmed
            ├─ save(session), save(booking)
            └─ DomainEventPublisher::publish(BookingConfirmed)
                  └─ Messenger → transporte Doctrine             INSERT en messenger_messages
       └─ COMMIT  (reserva + mensaje, atómicos)

worker (php bin/console messenger:consume async)
  └─ SendBookingConfirmationEmailOnBookingConfirmed(BookingConfirmed)
       ├─ SentNotificationRegistry::wasSent(reference, 'booking-confirmation')?  → si sí, fin
       ├─ SessionRepository::find(sessionId)                    fecha de inicio para el correo
       ├─ UserContactProvider::emailFor(userId)                 ← FAKE: user-{uuid}@example.test
       ├─ MailerPort::send(BookingEmail)                        ← SymfonyMailerAdapter
       │     └─ MailerInterface::send(Email)                    ← DSN null:// → se loguea, no sale
       └─ SentNotificationRegistry::markSent(reference, type)
```

La cancelación es simétrica con `BookingCancelled` y `SendBookingCancellationEmailOnBookingCancelled`.

## Por qué así

**Outbox en lugar de enviar en la petición.** El correo no puede bloquear ni fallar la
reserva: en una sesión con mucha demanda la petición debe ser lo más corta posible (mantiene
el bloqueo de fila menos tiempo) y un SMTP caído no puede provocar que se pierdan reservas.
Con el transporte Doctrine el mensaje se inserta en `messenger_messages` con la misma
conexión y dentro de la misma transacción que la reserva: si el commit falla no hay correo;
si el commit va bien, el correo no se pierde aunque el worker esté caído en ese momento.

**Handlers idempotentes.** Messenger garantiza entrega *al menos una vez*: si el worker
muere después de enviar y antes de confirmar el mensaje, lo reintentará. Cada handler consulta
`sent_notifications` (clave `(booking_reference, type)`) antes de enviar y la marca después;
un `INSERT` duplicado por carrera se ignora.

**Puertos para lo que no es nuestro.** El usuario vive en otro contexto (no se modela), así
que el dominio declara `UserContactProvider` y la infraestructura decide cómo resolverlo. El
mailer real es un detalle de infraestructura, así que el dominio declara `MailerPort` y
recibe un DTO (`BookingEmail`) con escalares, no el agregado.

**DTO entre aplicación y mailer.** `BookingEmail` lleva destinatario, tipo, referencia,
plazas, total y fecha de sesión. El adaptador de Symfony Mailer construye asunto y cuerpo a
partir de él; ninguna clase de dominio cruza esa frontera.

## Qué haría falta para que fuera real

| Cambio | Coste |
|---|---|
| Envío real por SMTP/API | Cambiar `MAILER_DSN` (`smtp://…`, `ses+api://…`, `sendgrid+api://…`). Cero código |
| Ver los correos en desarrollo | Añadir Mailpit al `compose.yaml` y `MAILER_DSN=smtp://mailpit:1025` |
| Email real del usuario | Nuevo adaptador de `UserContactProvider` (HTTP al servicio de usuarios, o lectura de una réplica) y cambiar el alias en `services.yaml` |
| Plantillas HTML | Twig + `TemplatedEmail` dentro de `SymfonyMailerAdapter` |
| Reintentos y dead-letter | Ya configurados: 3 reintentos con backoff y transporte `failed` |

## Cómo se prueba

- **Unitario**: los handlers con `InMemoryMailer`, `InMemorySentNotificationRegistry` y
  `FakeUserContactProvider`; se comprueba destinatario, contenido e idempotencia al reentregar.
- **Funcional**: con `MESSENGER_TRANSPORT_DSN=sync://` el handler corre dentro de la propia
  petición y `MailerPort` está sustituido por `InMemoryMailer`; se afirma que reservar y
  cancelar generan exactamente un correo cada uno, y que una reserva fallida no genera ninguno.
- **Manual en dev**: `make up`, reservar con `curl`, `make logs` muestra al worker consumiendo
  el evento y al mailer registrando el envío con `null://`.
