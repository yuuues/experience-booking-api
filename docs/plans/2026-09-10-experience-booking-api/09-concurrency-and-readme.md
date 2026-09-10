# Fase 9 — Prueba de concurrencia, README y cierre

Requiere Fases 1–8. Demuestra empíricamente que no hay sobreventa y deja el repositorio listo para entregar.

---

### Task 18: Script de concurrencia

**Files:**
- Create: `bin/concurrency-test`
- Modify: `README.md` (se escribe entero en la Task 19; aquí solo se ejecuta el script)

**Interfaces:**
- Consumes: API completa levantada (`make up`), `symfony/http-client` (dev).
- Produces: `make test-concurrency` → exit 0 si `bookedSeats == capacity` y exactamente `capacity` respuestas 201; exit 1 en caso contrario.

- [ ] **Step 1: Script**

`bin/concurrency-test` (sin extensión; `chmod +x`):
```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Fires N concurrent single-seat bookings at one session with capacity M (N > M)
 * and verifies that exactly M succeed and the session ends with 0 available seats.
 * Run against the dockerised API: `make test-concurrency`.
 */

require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Uid\Uuid;

$baseUrl = getenv('API_BASE_URL') ?: 'http://nginx';
$capacity = (int) (getenv('CAPACITY') ?: 10);
$attempts = (int) (getenv('ATTEMPTS') ?: 60);

$http = HttpClient::create(['base_uri' => $baseUrl, 'timeout' => 30]);

$experience = $http->request('POST', '/api/experiences', ['json' => [
    'title' => 'Concurrency probe '.date('H:i:s'),
    'description' => 'Flash-sale simulation.',
    'providerId' => Uuid::v7()->toRfc4122(),
]])->toArray();

$session = $http->request('POST', sprintf('/api/experiences/%s/sessions', $experience['id']), ['json' => [
    'startsAt' => (new DateTimeImmutable('+2 days'))->format(DATE_ATOM),
    'capacity' => $capacity,
    'price' => ['amount' => 1000, 'currency' => 'EUR'],
]])->toArray();

printf("Session %s with capacity %d — firing %d concurrent bookings…\n", $session['id'], $capacity, $attempts);

$responses = [];
for ($i = 0; $i < $attempts; ++$i) {
    // request() is lazy: all requests are in flight together, resolved in the stream() loop below.
    $responses[] = $http->request('POST', sprintf('/api/sessions/%s/bookings', $session['id']), ['json' => [
        'userId' => Uuid::v7()->toRfc4122(),
        'seats' => 1,
    ]]);
}

$statuses = [];
foreach ($http->stream($responses) as $response => $chunk) {
    if ($chunk->isLast()) {
        $code = $response->getStatusCode();
        $statuses[$code] = ($statuses[$code] ?? 0) + 1;
    }
}
ksort($statuses);

$final = $http->request('GET', sprintf('/api/sessions/%s', $session['id']))->toArray();

foreach ($statuses as $code => $count) {
    printf("  HTTP %d: %d\n", $code, $count);
}
printf("Booked seats: %d / %d (available: %d)\n", $final['bookedSeats'], $final['capacity'], $final['availableSeats']);

$ok = ($statuses[201] ?? 0) === $capacity
    && $final['bookedSeats'] === $capacity
    && $final['availableSeats'] === 0
    && ($statuses[201] ?? 0) + ($statuses[422] ?? 0) + ($statuses[503] ?? 0) === $attempts;

echo $ok ? "OK — no overbooking.\n" : "FAIL — overbooking or unexpected responses.\n";
exit($ok ? 0 : 1);
```

- [ ] **Step 2: Ejecutar**

```bash
chmod +x bin/concurrency-test
make up
make test-concurrency
```
Expected:
```
Session … with capacity 10 — firing 60 concurrent bookings…
  HTTP 201: 10
  HTTP 422: 50
Booked seats: 10 / 10 (available: 0)
OK — no overbooking.
```
Si aparecen `503`, es el `lock_timeout` (2 s) actuando bajo cola muy larga; el script lo acepta siempre que los 201 sean exactamente `capacity`. Con `pm.max_children = 32` y 60 peticiones no debería ocurrir; si ocurre repetidamente, sube `LOCK_TIMEOUT` en `DoctrineTransactionalRunner` a `5000ms` y documenta el valor.

Repite 3 veces para confirmar estabilidad; también prueba `CAPACITY=1 ATTEMPTS=100 make test-concurrency`.

- [ ] **Step 3: Commit**

```bash
git add bin/concurrency-test
git commit -m "test: concurrency probe proving no overbooking under parallel bookings

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 19: README y puerta de calidad final

**Files:**
- Create: `README.md`
- Verify: `make test`, `make stan`, `make cs`, `make test-concurrency`

- [ ] **Step 1: README.md** (en español; adapta los ejemplos reales que obtengas con `curl`)

Secciones obligatorias, en este orden:

1. **Título y resumen** (3 líneas): qué es, stack (PHP 8.5, Symfony 8.1, PostgreSQL 18, Doctrine, Messenger), enlace a `docs/design/` y `docs/plans/`.
2. **Arranque rápido**:
   ```bash
   make up          # build + composer install + migraciones + worker de correo
   make test        # unit + integration + functional
   make test-concurrency
   ```
   API en `http://localhost:8080`.
3. **API** — tabla de endpoints (copiar de la spec §7) y un flujo completo con `curl`: crear experiencia → sesión → reserva → consultar → cancelar. Formato de errores `application/problem+json` con un ejemplo real de 422 y de 400.
4. **Arquitectura** — hexagonal por módulo (`Domain` / `Application` / `Infrastructure`), lista de puertos y adaptadores, DTOs en fronteras, mapping XML para mantener el dominio libre de Doctrine, flujo de una reserva (diagrama en texto: controller → command → handler → transacción → `findForUpdate` → `Session::book` → repos → eventos → Messenger → handler de correo).
5. **Reglas de negocio y dónde viven** — tabla regla → clase/método → test.
6. **Concurrencia** — por qué `SELECT … FOR UPDATE` sobre la sesión + `CHECK` en BD + `lock_timeout` → 503 `Retry-After`; alternativas descartadas (optimista, `UPDATE` atómico) y por qué; cómo reproducirlo con `make test-concurrency` y una salida real.
7. **Correo** — patrón outbox: evento en la misma transacción (transporte Doctrine), handler asíncrono, idempotencia con `sent_notifications`, `MailerPort` con `null://` en dev; cómo se cambiaría a un envío real (solo DSN).
8. **Decisiones y supuestos sobre el enunciado** — tabla de la spec §2: email de contacto vía `UserContactProvider`, zona horaria de plataforma para "mismo día", ventana de 24 h interpretada como "menos de 24 h antes", precio por plaza en céntimos, referencia pública `BK-…`, `PUT` de experiencia bloqueado con reservas confirmadas, `POST …/cancellation` en vez de `DELETE`, dos agregados por transacción como concesión consciente.
9. **Lo que haría en producción** — autenticación/autorización (la referencia es enumerable; solo el dueño cancela), rate limiting (429) en reservar/cancelar como segunda capa contra fuerza bruta y bots, read models/CQRS para catálogo, envío real de correo con reintentos y dead-letter (ya configurado `failed`), observabilidad (logs estructurados, métricas de lock wait), límite de plazas por reserva si negocio lo pide, zona horaria por experiencia si hay proveedores internacionales.
10. **Calidad** — `make stan` (PHPStan max), `make cs`, cobertura de tests por capa, cómo están organizados (`tests/Unit`, `Integration`, `Functional`, `bin/concurrency-test`).

- [ ] **Step 2: Puerta de calidad**

```bash
make cs-fix
make stan
make test
make test-concurrency
```
Expected: todo verde; `git status` limpio salvo README.

Revisión final de coherencia con la spec: recorre `docs/design/…-design.md` §3–§7 y confirma que cada regla tiene test y cada endpoint existe (`make console c="debug:router"` debe listar 8 rutas `api_*`).

- [ ] **Step 3: Commit y push**

```bash
git add -A
git commit -m "docs: README with setup, architecture, decisions and production notes

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
git push
```
