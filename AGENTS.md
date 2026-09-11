# AGENTS.md

API REST de reservas de experiencias (prueba técnica). PHP 8.5, Symfony 8.1,
PostgreSQL 18, Doctrine ORM 3 + Migrations, Messenger y Mailer. Arquitectura
hexagonal con DDD.

El contexto largo está en el [`README.md`](README.md) (decisiones, concurrencia,
calidad) y en [`docs/design/`](docs/design/) + [`docs/plans/`](docs/plans/). Este
fichero es el resumen operativo: lo que hay que saber antes de tocar código.

## Arquitectura

Un bounded context, tres módulos (`Experience`, `Session`, `Booking`) y un kernel
`Shared`. Cada módulo repite las mismas tres capas y la dependencia apunta siempre
hacia dentro:

```
src/<Módulo>/Domain          agregados, value objects, eventos, excepciones, puertos
src/<Módulo>/Application     casos de uso: Command/Query + Handler + Response DTO
src/<Módulo>/Infrastructure  controladores, DTOs de request, repositorios, adaptadores
```

**`Domain` no importa framework.** Hay una única excepción documentada:
`src/Shared/Domain/Uuid.php` usa `symfony/uid` para generar UUIDv7.
`grep -rn "use Symfony" src/*/Domain/` debe devolver exactamente esa línea y
ninguna más.

## Dónde va cada cosa

| Cosa | Sitio |
|---|---|
| Agregados y value objects | `src/<Módulo>/Domain/` |
| Caso de uso | `src/<Módulo>/Application/<Accion>/`: `XCommand`/`XQuery` readonly + `XHandler` invocable + `XResponse` DTO |
| Puertos (interfaces) | `Domain/` del módulo dueño del concepto; `TransactionalRunner` y `DomainEventPublisher` viven en `Shared/Application/` |
| Adaptadores | `src/<Módulo>/Infrastructure/`, conectados con `#[AsAlias(id: Puerto::class)]` |
| Mapping Doctrine | XML en `config/doctrine/<Módulo>/*.orm.xml`; los tipos custom se registran en `config/packages/doctrine.yaml` |
| DTOs de request HTTP | `src/<Módulo>/Infrastructure/Http/*Request.php`, con `#[Assert\...]` |
| Dobles de test | `tests/Doubles/<Módulo>/` (in-memory, no mocks) |

`config/services.yaml` excluye del contenedor `Domain/`, los `*Command`/`*Query`,
los `*Response` y los `*Request`: son datos, no servicios.

## Reglas que no se rompen

- **Cero atributos de ORM en `src/`.** El mapping es XML. Un `#[ORM\Entity]` en un
  agregado es un error, no una simplificación.
- **Ninguna regla de negocio fuera de un agregado.** Los handlers orquestan;
  `Session::book()`, `Session::cancelBooking()`, `Experience::update()` deciden.
  Si un handler necesita un dato de otro módulo, lo trae como value object
  (`ExperienceEditability`) y deja que el agregado lance.
- **Los handlers devuelven DTOs, nunca agregados.**
- **Reservar y cancelar ocurren dentro de un único `TransactionalRunner::run()`,
  y lo primero dentro es `SessionRepository::findForUpdate()`.** Sacar una
  escritura de esa transacción reintroduce la sobreventa en silencio, sin que
  ningún test unitario lo note. Ver §5 del README.
- **Cero supresiones de PHPStan en línea.** Nada de `@phpstan-ignore` ni
  `@phpstan-var` ni baseline. Si PHPStan (nivel `max`) se queja, el diseño es lo
  que hay que cambiar. La única entrada de `ignoreErrors` es `method.unused` sobre
  `src/Kernel.php`, del esqueleto de Symfony.
- **Métodos de test en `snake_case`** (`php_unit_method_casing`), describiendo el
  comportamiento: `it_refuses_to_overbook`, no `testBook`.
- **Los eventos de dominio siempre tienen handler.** Si se añade uno sin consumidor
  de negocio, `Shared/Infrastructure/Messenger/LogDomainEvent` ya lo recoge.

## Cómo trabajar

- **Todo pasa por `make`; la aplicación solo corre en Docker.** `make up`,
  `make test`, `make test-unit`, `make stan`, `make cs`, `make cs-fix`,
  `make test-concurrency`, `make console c="..."`, `make composer c="..."`.
  No hay `symfony serve` ni PHP en el host.
- **TDD rojo-verde.** Test que falla, implementación mínima, refactor. Una
  funcionalidad no está hecha hasta que hay un test que la ejercita como lo haría
  quien la llama: petición HTTP para un controlador, llamada al servicio para un
  servicio.
- **Migraciones escritas a mano** en `migrations/`, con nombres de índice y de
  constraint explícitos. Nunca `doctrine:migrations:diff` ni
  `doctrine:schema:update`: el esquema tiene a propósito más garantías que el
  mapping (`CHECK`, FKs, índices únicos), y la herramienta las borraría. El README
  §9.1 explica por qué `doctrine:schema:validate` reporta la base de datos "fuera
  de sincronía" y por qué está bien así.
- **Paquetes nuevos con `make composer c="require ..."`**, dejando que la receta de
  Flex registre el bundle y su configuración. No editar `config/bundles.php` a mano.

## Qué NO hacer

- **No añadir autenticación ni autorización.** El enunciado la excluye
  explícitamente; `providerId` y `userId` llegan en el body. Está documentado como
  el hueco número 1 en §8 del README.
- **No modelar usuarios ni proveedores.** Son ids de otro contexto. El email de
  contacto se resuelve por el puerto `UserContactProvider`.
- **No "arreglar" las decisiones deliberadas del README**: dos agregados en una
  misma transacción (§7.1), el `PUT /api/experiences/{id}` añadido (§7.2), la sonda
  de concurrencia fuera de PHPUnit (§9), la ventana de carrera de la referencia de
  reserva (§8). Están razonadas por escrito; cambiarlas requiere cambiar también el
  razonamiento.
- **No introducir un ORM lock optimista, un `UPDATE` condicional ni una cola por
  sesión** como sustituto del bloqueo de fila: las tres alternativas están
  evaluadas y descartadas en §5.3.

## Convenciones Symfony

Se sigue https://symfony.com/doc/current/best_practices.html, con estas
particularidades del proyecto:

- Metadatos del framework con atributos PHP: `#[Route]`, `#[MapRequestPayload]`,
  `#[AsEventListener]`, `#[AsMessageHandler]`, `#[AsAlias]`, `#[Autowire]`. Nada de
  routing en YAML o XML (el XML aquí es solo mapping de Doctrine).
- **Los controladores no extienden `AbstractController`**: son clases
  `final readonly` invocables (`__invoke`) que reciben el handler por constructor y
  devuelven `JsonResponse`. No tienen lógica; traducen HTTP a un Command y el
  Response DTO a JSON.
- El cuerpo de la petición se enlaza con `#[MapRequestPayload]` sobre un DTO con
  restricciones del Validator. Nunca `json_decode()` a mano.
- Promoción de propiedades en el constructor y `readonly` en DTOs, value objects,
  handlers y adaptadores.
- Autowiring y `#[AsAlias]` para conectar puerto y adaptador. No hay definiciones de
  servicio escritas a mano.
- Los errores salen como `application/problem+json` (RFC 7807) por
  `ProblemJsonExceptionListener`; una excepción de dominio nueva hereda de
  `NotFoundException` / `ConflictException` / `InvalidValue` / `DomainException` y ya
  queda mapeada a 404 / 409 / 400 / 422.
- Cada endpoint se documenta con atributos de Nelmio/OpenAPI. Hay un test funcional
  que compara la spec con el router real: un endpoint sin documentar rompe la suite.

## Descubrir, no adivinar

Las APIs del framework cambian entre versiones. Comprobar en el proyecto antes de
dar algo por hecho:

- `make console c="about"`, `c="debug:router"`, `c="debug:container"`,
  `c="debug:autowiring <nombre>"`, `c="doctrine:mapping:info"`.
- `make console c="lint:container"`, `c="lint:yaml config/"`.
- Leer el código instalado bajo `vendor/` (por ejemplo, los convertidores de
  excepciones de DBAL: qué SQLSTATE traduce cada driver y cuál no).
- `var/log/dev.log` y `make logs` antes de tocar código cuando algo falla.
