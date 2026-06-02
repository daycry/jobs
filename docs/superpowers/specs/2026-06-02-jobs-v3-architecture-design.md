# daycry/jobs v3.0 — Diseño de arquitectura

- **Fecha**: 2026-06-02
- **Estado**: Aprobado (brainstorming) — pendiente de plan de implementación
- **Autor**: Daycry (con asistencia de Claude)
- **Versión objetivo**: 3.0.0 (cambio mayor, se permiten breaking changes)
- **PHP**: `^8.2` (sin cambios)

## 1. Objetivo y contexto

El paquete `daycry/jobs` (scheduler + colas para CodeIgniter 4) arrastra hoy **dos arquitecturas
coexistentes**: la V1 funcional (clase mutable `Job` + 7 traits, `QueueInterface`+`WorkerInterface`,
`JobLifecycleCoordinator`) y una V2 inerte (`JobDefinition`, `QueueBackend`, `JobLease`,
`LegacyWorkerAdapter`, `TypedJobHandler`) que **no está cableada a ningún punto de ejecución real**
y cuya documentación referencia clases inexistentes (`RedisBackend`).

Una auditoría multi-agente (83 agentes, verificación adversarial) confirmó **68 hallazgos**, entre
ellos 1 crítico, 7 altos y ~20 medios. Los más graves comparten dos causas raíz:

1. El **modelo de reintentos/finalización está partido** entre `JobLifecycleCoordinator` (bucle
   interno bloqueante) y `RequeueHelper` (re-encolado), que no se conocen entre sí → doble consumo
   de `maxRetries`, worker bloqueado en `sleep()`.
2. El **contenido de la cola se trata como plenamente confiable** → RCE por mensaje no firmado que
   elige el handler (`shell`/`command`).

**Decisión de producto** (confirmada con el maintainer):
- Completar V2 como la **única arquitectura limpia**; V1 queda como **fachada fina `@deprecated`**
  (eliminación en v4.0).
- Se permiten **cambios que rompen compatibilidad** (release mayor v3.0).
- **Alcance completo**: todas las correcciones del informe (crítico → menor), con tests y análisis
  estático en verde.

## 2. Principios de diseño

- **Un solo contrato de backend**: desaparecen `QueueInterface`, `WorkerInterface`, `watch()`,
  `removeJob()` y `LegacyWorkerAdapter`. Queda `QueueBackend` con semántica de *lease*.
- **Handlers desacoplados**: los handlers **dejan de extender `Job`** (fin del god-object);
  implementan `JobHandlerInterface` y reciben un `JobContext`, no el builder completo.
- **Un intento por fetch**: el runtime ejecuta el job una vez; los reintentos son responsabilidad de
  la cola (re-encolado con *delay* de backoff), nunca un `sleep()` bloqueante en el worker.
- **Seguro por defecto**: envelope firmado, allowlist de handlers por cola, ShellHandler
  deny-by-default, EventHandler con allowlist.
- **Sin lógica duplicada**: V1 público delega en V2; no hay dos implementaciones del mismo
  comportamiento.
- **YAGNI**: se retira el config muerto (`batchSize`) y el código no integrado.

## 3. Arquitectura por capas

Namespace raíz `Daycry\Jobs\`. Estructura objetivo de `src/`:

```
Definition/
  JobDefinition.php        # value object inmutable (existe; se amplía: enabled, idempotencyKey, callbacks)
  JobBuilder.php           # API fluida → produce JobDefinition (helpers de frecuencia portados)
Handlers/
  JobHandlerInterface.php  # handle(JobContext): mixed
  AbstractJobHandler.php   # beforeRun/afterRun opcionales
  HandlerRegistry.php      # key => clase, resuelve y valida is_a(JobHandlerInterface)
  ClosureHandler.php  CommandHandler.php  ShellHandler.php  UrlHandler.php  EventHandler.php
Execution/
  JobRuntime.php           # ejecuta UN intento; orquesta timeout, idempotencia, single-instance, notify
  JobContext.php           # payload + meta + attempt + lease info (inmutable)
  ExecutionResult.php      # (existe) DTO de resultado
  RetryPolicy.php          # interfaz (existe) + RetryPolicyFixed (jitter también en 'fixed')
  IdempotencyGuard.php     # SET NX EX sobre cache
  SingleInstanceLock.php   # lock atómico con token de propiedad
  Timeout.php              # SIGALRM que LANZA; fallback soft documentado
Queues/
  QueueBackend.php         # contrato único (enqueue/fetch/ack/nack/abandon/reapExpired)
  JobEnvelope.php          # (existe) mensaje de cola normalizado + firma
  JobLease.php             # (existe) job en vuelo + token de propiedad + expiry
  AbstractQueueBackend.php # utilidades comunes (serializer, id, firma)
  DatabaseBackend.php  RedisBackend.php  BeanstalkBackend.php  ServiceBusBackend.php  SyncBackend.php
Worker/
  QueueWorker.php          # bucle del worker (fetch → run → ack/nack con backoff)
Cron/
  CronRunner.php           # ejecución por tick; honra enabled; sin sleep global; orden topológico
Support/
  Signing/EnvelopeSigner.php   # HMAC-SHA256 + hash_equals
  Metrics/ ...                 # (existe) colector + facade
  DeadLetterQueue.php          # (existe; integrado con política fail-keep)
  CircuitBreaker.php           # (existe; transiciones atómicas)
  RateLimiter.php              # (existe)
  Notifications/ ...           # (existe; envuelto en try/catch desde el runtime)
  Loggers/ ...                 # (existe; masking configurable)
Commands/                      # comandos CLI v3 (queue:work, cronjob:run, queue:reap, etc.)
Config/  Models/  Entities/  Database/Migrations/  Exceptions/  Generators/  Interfaces/
Compat/                        # @deprecated, fachada fina (borra en v4.0)
  Job.php (+ traits)  Scheduler.php  Queues viejos  handlers viejos  comandos viejos
```

> Nota de implementación: para minimizar churn de rutas y `use`, los archivos pueden permanecer en
> sus carpetas actuales y solo cambiar de contrato/responsabilidad; lo importante es la **separación
> conceptual de capas**, no mover ficheros por mover. El plan decidirá el detalle de ubicación.

## 4. API pública y DX

### 4.1 Definición de jobs

```php
use Daycry\Jobs\Jobs; // facade/servicio de entrada

Jobs::define('command', 'app:report')
    ->named('daily-report')
    ->dailyAt('02:00')->timezone('Europe/Madrid')   // helpers de frecuencia (portados de FrequenciesTrait)
    ->queue('reports')->priority(5)
    ->maxRetries(3)->backoff('exponential')          // estrategia de reintento de cola
    ->timeout(120)                                   // timeout real (un único concepto)
    ->idempotencyKey('report-2026-06-02')            // opt-in; default off
    ->dispatch();                                    // encola; ->schedule() registra como cron
```

- `JobBuilder` es mutable y fluido; **produce** un `JobDefinition` inmutable al finalizar
  (`dispatch()`/`schedule()`/`toDefinition()`). Esto preserva la ergonomía sin el god-object.
- Firmas **explícitas y tipadas** (se eliminan los `...$params` variádicos frágiles y los
  `event()/url()` que solo funcionaban con argumentos nombrados).

### 4.2 Handlers

```php
interface JobHandlerInterface {
    public function handle(JobContext $ctx): mixed;
}
abstract class AbstractJobHandler implements JobHandlerInterface {
    public function beforeRun(JobContext $ctx): void {}
    public function afterRun(JobContext $ctx, ExecutionResult $r): void {}
}
```

- Los handlers **no** conocen scheduling/cola; reciben `JobContext` (payload + meta + attempt).
- `HandlerRegistry` resuelve `Config\Jobs::$handlers['command' => CommandHandler::class]` y valida
  `is_a($class, JobHandlerInterface::class, true)` (se reemplaza el `is_subclass_of(..., Job::class)`).

### 4.3 Compatibilidad V1 (`@deprecated`)

- `Job` (builder mutable + 7 traits), `Scheduler`, `->push()`, `->everyMinute()`, los handlers
  `ClosureJob`/`CommandJob`/`ShellJob`/`UrlJob`/`EventJob` (que hoy `extends Job`) y los comandos
  viejos se conservan como **fachada delgada** que internamente arma `JobBuilder`/`JobDefinition` y
  delega en V2.
- Emiten `@trigger_error('... is deprecated since 3.0, use ... ; will be removed in 4.0', E_USER_DEPRECATED)`
  una vez por símbolo.
- Sin lógica de negocio propia: solo traducción V1→V2.

## 5. Runtime de ejecución y resiliencia

`JobRuntime::run(JobDefinition $def, JobContext $ctx): ExecutionResult` — **un solo intento**.

| Correctivo | Diseño |
|---|---|
| Doble bucle de reintentos | El worker ejecuta 1 intento. En fallo con reintentos restantes → `nack(lease, delay = RetryPolicy::computeDelay(attempt+1))`. El runtime **no** itera ni hace `sleep()`. |
| Backoff sin efecto al re-encolar | `nack(delay)` aplica el delay al backend (ZSET delayed / `available_at`). |
| Timeout que no interrumpe | `Timeout` instala handler `SIGALRM` que **lanza** `JobTimeoutException` (con `pcntl_async_signals(true)`); el runtime la captura → fallo. Sin `pcntl`: soft + warning documentado. |
| `jobTimeout` ignorado vs `defaultTimeout` | **Un único** concepto: `Config\Jobs::$defaultTimeout` (global, null=ilimitado) + `JobDefinition::$timeout` (por job). Se elimina `jobTimeout`. |
| Sin idempotencia | `IdempotencyGuard` opt-in: `cache->save(key, 1, ttl)` solo si no existía (SET NX); si ya procesado → `ack` sin ejecutar. Default off; *at-least-once* documentado. |
| Single-instance con carrera | `SingleInstanceLock` atómico con token de propiedad; libera solo el dueño; best-effort documentado en cache no atómica. |
| Fallo de notificación aborta el job | `notify()` envuelto en `try/catch` en el runtime; nunca altera el resultado ni re-encola. |
| `handleException` re-encola siempre | El path de excepción del worker pasa por la **misma** lógica de finalización (respeta `maxRetries`/DLQ); `singleInstance` contendido no re-encola desde el worker que no es dueño. |
| Callbacks/middleware perdidos al encolar | Solo aplican inline/sync. Si se encola a backend no-sync con callback/middleware → **aviso/validación en `dispatch()`** (no pérdida silenciosa). |

## 6. Backends y concurrencia

```php
interface QueueBackend {
    public function enqueue(JobEnvelope $env, ?int $delaySeconds = null): string;
    public function fetch(string $queue): ?JobLease;                 // reserva atómica
    public function ack(JobLease $lease): bool;                       // completado
    public function nack(JobLease $lease, ?int $delaySeconds = null): bool; // reintento con backoff
    public function abandon(JobLease $lease): bool;                   // a DLQ / descartar
    public function reapExpired(string $queue, int $visibilityTimeout): int; // recupera leases muertos
}
```

- **DatabaseBackend**: conserva `FOR UPDATE SKIP LOCKED` + fallback optimista (correcto hoy).
  - **`reapExpired()`**: `UPDATE ... SET status='pending' WHERE status='in_progress' AND reserved_at < now-VT` → fin del *stranding* permanente.
  - **Requeue por `UPDATE` in-place** (mismo `id`/`identifier`): `status='pending'`, `attempts++`, `available_at = now+delay` → sin fila huérfana 'failed' ni pérdida de trazabilidad.
  - **Migración**: añadir columnas `reserved_at`, `available_at`; índice compuesto `(queue, status, priority, schedule)`; retirar índices monocolumna redundantes.
- **RedisBackend**: conserva RPOPLPUSH + promoción Lua.
  - **Renovación de lease** (heartbeat re-`HSET` del timestamp desde el worker durante la ejecución) + **token de propiedad** para que el reaper no reclame jobs vivos.
  - `nack(delay)` → ZSET delayed; validación en arranque `visibilityTimeout > timeout máximo`.
- **BeanstalkBackend / ServiceBusBackend**: `nack(delay)` nativo (release-with-delay / scheduled enqueue); alinear lock/visibility con el timeout de ejecución.
- **SyncBackend**: ejecución inmediata in-proceso; conserva el job intacto (callbacks/middleware funcionan).
- **Semántica uniforme** *at-least-once* + `reapExpired()` en todos los backends persistentes.
- **`batchSize`**: **retirado** del config y la doc (era no-op). Reservar por lote puede volver como feature real en una versión futura.

## 7. Seguridad

- **Firma del envelope (anti-tamper / anti-RCE)**: `EnvelopeSigner` firma el JSON canónico del
  `JobEnvelope` con **HMAC-SHA256**.
  - Clave: `env('JOBS_SIGNING_KEY')` ?? *Encryption key* de CI4 (`Config\Encryption::$key`).
  - `fetch()` verifica con `hash_equals()` y **rechaza** mensajes con firma inválida.
  - **Sin clave configurada**: se opera en modo sin firma con **aviso fuerte** (`log_message('critical', ...)` una vez) y bandera de config explícita; **no** fail-closed (para no romper zero-config en dev). Documentado como inseguro en producción.
- **Allowlist de handlers por cola**: `Config\Jobs::$queueHandlers = ['reports' => ['command']]`.
  Una cola solo invoca handlers permitidos; `shell`/`command` **no permitidos por defecto** en colas
  sin allowlist explícita.
- **ShellHandler deny-by-default**: whitelist vacía = **rechazar**. Se elimina el legacy basename
  matching. Ejecución con `proc_open` y `argv` por array (sin `/bin/sh -c`).
- **EventHandler allowlist**: `Config\Jobs::$allowedEvents`; sin allowlist no dispara eventos.
- **UrlHandler**: mantiene anti-SSRF (sólido); añade `allow_redirects=false` explícito y allowlist de
  puertos. El riesgo residual de DNS rebinding queda documentado.
- **Logging**: masking genérico (`{40,}`) **configurable** (flag) y comentario corregido; excepciones
  logueadas con **tipo + stacktrace**, no solo `getMessage()`.

## 8. Configuración (`Config\Jobs`) — cambios

- **Añade**: `handlers` (antes `jobs`), `queueHandlers` (allowlist por cola), `allowedEvents`,
  `signingKey`/`requireSignedEnvelopes`, `databaseVisibilityTimeout`, `logMaskGenericTokens` (bool).
- **Elimina**: `jobTimeout` (unificado en `defaultTimeout`), `batchSize`.
- **Renombra/clarifica**: `allowedShellCommands` (ahora deny-by-default), comentarios actualizados.
- Compat: `Config\Jobs` mantiene los nombres viejos como alias `@deprecated` mientras exista la fachada.

## 9. Comandos CLI

- Nuevos (V2): `jobs:queue:work`, `jobs:cronjob:run`, `jobs:queue:reap` (todos los backends),
  `jobs:queue:purge`, `jobs:health`, etc.
- Mejoras de operabilidad: `pcntl_async_signals(true)` al arrancar el worker; re-chequeo de
  `shouldStop` tras `fetch()`; apagado elegante acotado. Documentar que el job en curso no se aborta a
  mitad (solo se evita tomar el siguiente).
- Compat: los comandos viejos (`jobs:queue:run`, etc.) quedan `@deprecated` delegando en los nuevos.

## 10. Cron

- Ejecución única por tick; **sin** `sleep(defaultTimeout)` entre jobs (se elimina la confusión de
  conceptos). Si se quiere throttle: opción explícita `interJobDelay` (no aplicada tras el último).
- **Honra `enabled`** (`disable()` real): cortocircuito por estado antes de ejecutar.
- Orden topológico de dependencias conservado.
- Opcional (documentado como feature): detección de ejecuciones perdidas vía `getLastRunTime()` +
  `getPreviousRunDate()` → evento/contador `cronjob.missed`.

## 11. Migración, docs y versionado

- **v3.0.0**, `php ^8.2`.
- **Compat layer** V1 `@deprecated` (borra en v4.0). Tests V1 existentes siguen verdes.
- **Docs**: reescritura V2-only. Se **elimina** `docs/V2_MIGRATION.md` y toda referencia a
  `RedisBackend` inexistente. Nuevo `docs/MIGRATION-v2-to-v3.md`. `README`, `mkdocs.yml`, `docs/*`,
  `CHANGELOG` actualizados.
- **Análisis estático**: regenerar baseline (debe encoger al añadir tipos); atacar `missingType.*`
  (~43% del baseline). Subir objetivo PHPStan/Psalm de forma gradual.

## 12. Pruebas (huecos a cubrir)

- Conteo exacto de reintentos: `maxRetries=2 ⇒ exactamente 3 ejecuciones` (no 6).
- Timeout que **interrumpe** de verdad (CPU-bound).
- `reapExpired()` de DatabaseBackend y RedisBackend (lease muerto recuperado; lease vivo no reclamado).
- Idempotencia (segunda entrega no re-ejecuta).
- Firma de envelope (mensaje manipulado rechazado).
- Claim concurrente **multi-proceso real** (`proc_open`), no fakes.
- E2E: encolar → agotar reintentos → aterrizar en DLQ.
- Allowlist de handlers por cola; ShellHandler deny-by-default; EventHandler allowlist.

## 13. Mapa de correcciones del informe → diseño

| # | Hallazgo (sev. ajustada) | Resuelto en |
|---|---|---|
| 1 | RCE por mensaje sin firmar (crítico) | §7 firma + allowlist por cola |
| 2 | DatabaseQueue stranding sin reaper (alto) | §6 `reapExpired()` DB |
| 3 | Redis reaper doble-entrega (alto) | §6 renovación de lease + token |
| 4 | Doble bucle de reintentos (alto) | §5 un intento + nack(delay) |
| 5 | Sin idempotencia (alto) | §5 IdempotencyGuard |
| 6 | Pérdida por DLQ off/destructivos (alto) | §5/§6 política fail-keep + DLQ |
| 7 | ShellJob allow-all (alto) | §7 deny-by-default + proc_open |
| 8 | Timeout no interrumpe + jobTimeout muerto (alto) | §5 Timeout que lanza + unificar config |
| 9 | V2 no integrado (medio) | §3 V2 es el core |
| 10 | Handlers extends god-object (medio) | §4.2 JobHandlerInterface |
| 11 | toObject() descarta middleware/closures (medio) | §5 aviso/validación en dispatch |
| 12 | DB requeue fila huérfana (medio) | §6 UPDATE in-place |
| 13 | Backoff no aplicado al requeue (medio) | §6 nack(delay) |
| 14 | CircuitBreaker no atómico (medio) | §3 Support (INCR/SETNX/Lua) |
| 15 | Retry 'none' busy + 'fixed' sin jitter + 2 impl. (medio) | §5 RetryPolicyFixed unificado |
| 16 | addAttempt() +1h mágico (medio) | §5 separar contar de reprogramar |
| 17 | disable() ignorado (medio) | §10 honra enabled |
| 18 | Drift / sleep global cron (medio) | §10 sin sleep + missed-run opcional |
| 19 | singleInstance race (medio) | §5 SingleInstanceLock atómico |
| 20 | EventJob eventos arbitrarios (medio) | §7 allowlist de eventos |
| 21 | Índice sin `queue` (medio) | §6 índice (queue,status,priority,schedule) |
| 22 | batchSize sin implementar (medio) | §6 retirado |
| 23 | handleException requeue incondicional (medio) | §5 finalización unificada |
| 24 | Notificación aborta job (medio) | §5 notify en try/catch |
| 25 | SIGTERM en fetch bloqueante (medio) | §9 async signals + re-check |
| 26 | Constructores variádicos (medio) | §4 firmas explícitas |
| menores | masking, MESSAGEID/MOCK_TIME, alias, docblocks, static analysis | §7/§8/§11 |

## 14. Fases de implementación (alto nivel; el plan detalla)

1. **Andamiaje del core V2**: `JobHandlerInterface`/`HandlerRegistry`, `JobBuilder`→`JobDefinition`
   ampliado, `JobContext`. Handlers nuevos (Closure/Command/Shell/Url/Event) desacoplados.
2. **Contrato de backend único**: `QueueBackend` + refactor de los 5 backends; `reapExpired()`,
   `nack(delay)`, lease con token; migraciones DB.
3. **Runtime + resiliencia**: `JobRuntime`, `Timeout` (lanza), `RetryPolicy` unificado,
   `IdempotencyGuard`, `SingleInstanceLock`, notify seguro, finalización unificada.
4. **Seguridad**: `EnvelopeSigner`, allowlist por cola, ShellHandler/EventHandler/UrlHandler.
5. **Worker + Cron commands** sobre V2; señales; sin sleep global; honra enabled.
6. **Compat layer V1 `@deprecated`** delegando en V2; mantener tests V1 verdes.
7. **Docs + CHANGELOG + mkdocs**: V2-only; eliminar V2_MIGRATION y RedisBackend; MIGRATION-v2-to-v3.
8. **Tests nuevos** (huecos §12) + **análisis estático** (baseline, missingType) en verde.

## 15. Fuera de alcance (v3.0)

- Reescritura nativa de backends desde cero (se reutiliza la lógica probada).
- Reserva por lote (`batchSize`) — retirada, posible feature futura.
- Soporte de PHP 8.3+ exclusivo — se mantiene `^8.2`.
- Nuevos backends (p.ej. SQS/Kafka) — fuera de este ciclo.
