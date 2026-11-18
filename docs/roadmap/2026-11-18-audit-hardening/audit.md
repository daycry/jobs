# Auditoría de código — daycry/jobs v3 (2026-11-18)

Alcance: todo `src/` (~6.900 líneas). Estado base: suite en verde (360 tests, 8 omitidos).
Leyenda de evidencia: **[R]** reproducido con test, **[C]** deducido por lectura de código, **[V]** pendiente de verificar contra fuente externa.

El test de reproducción usado en la auditoría definía un handler `flaky` (falla en la primera llamada) y ejecutaba
`Jobs::define(...)->queue('default')->dispatch('database')` seguido de `(new QueueWorker(new DatabaseBackend()))->processOnce('default')`.

## Críticos

| ID | Hallazgo | Evidencia | Ubicación | Arreglo propuesto |
|----|----------|-----------|-----------|-------------------|
| A-01 | Los handlers `event` y `url` no funcionan desde colas persistentes: los backends hacen `json_decode()` sin `assoc`, el payload llega como `stdClass` y ambos exigen `is_array`. Resultado: `dead-lettered: "EventHandler payload must be an array…"`. `ShellHandler` tampoco acepta `stdClass`. | R | `src/Queues/Backends/DatabaseBackend.php:77`, `RedisBackend.php:119`, `BeanstalkBackend.php:138`, `ServiceBusBackend.php:118`, `src/Handlers/EventHandler.php:34`, `UrlHandler.php:52` | Normalizar el payload a array en un único punto (worker) o decodificar con `assoc: true`; cuidar que la firma siga verificando. |
| A-02 | Idempotencia + reintentos pierde el job: la clave se registra antes de ejecutar y no se libera al fallar. Primer intento `requeued`, segundo `skipped-idempotent` + ack; el handler solo se ejecutó una vez y falló. | R | `src/Worker/QueueWorker.php:99-108`, `src/Execution/IdempotencyGuard.php` | Estado en dos fases (en curso / completado) confirmado solo tras éxito; como mínimo `forget()` al fallar. |
| A-03 | La firma HMAC se rompe en MySQL: la columna `payload` es `json` y MySQL reordena las claves de los objetos (verificado en MySQL 8: `{"name","data"}` → `{"data","name"}`); `canonicalJson()` firma en el orden original. Con clave de firma, esos mensajes se rechazan como manipulados. La reordenación está verificada; el rechazo de extremo a extremo en MySQL no se ha ejecutado. | R (reordenación) / C (rechazo) | `src/Queues/EnvelopeFactory.php:65`, `src/Database/Migrations/2025-10-03-152426_QueueTables.php` | Canonicalizar con `ksort` recursivo antes de firmar y verificar (compatible con mensajes ya encolados solo si se versiona la firma), o cambiar la columna a `TEXT`. |
| A-04 | `timeout`, `singleInstance` y `meta` se pierden al encolar: no viajan en el wire y `definitionFromWire()` no los reconstruye. | R | `src/Queues/EnvelopeFactory.php:39-58`, `src/Worker/QueueWorker.php:141-151` | Añadirlos al wire y a los campos firmados. |
| A-05 | Scheduler: un job `closure` sin `->named()` lanza `Serialization of 'Closure' is not allowed` y tumba todo el cron; los nombres duplicados (explícitos o derivados) se pisan en silencio (4 registrados → 2 ejecutados). | R | `src/Cron/Scheduler.php:77`, `:134` | Nombre de respaldo que no serialice closures; lanzar excepción ante nombres duplicados. |

## Altos

| ID | Hallazgo | Evidencia | Ubicación |
|----|----------|-----------|-----------|
| B-01 | No hay dead-letter real. `Libraries\DeadLetterQueue` no se invoca en ningún sitio aunque tres comentarios afirman que "el runtime reenvía al DLQ". En Service Bus `abandon()` hace `DELETE` (completa el mensaje, no lo dead-letteriza); en Redis se borra. `Config\Jobs::$deadLetterQueue` no tiene efecto. | C | `src/Worker/QueueWorker.php:133-138`, `ServiceBusBackend.php:195-207`, `RedisBackend.php:187-198` |
| B-02 | El `ownerToken` se genera y se guarda pero nunca se verifica: `ack`/`nack`/`markStatus` filtran solo por `id` (BD) o por valor (Redis). Un worker cuyo lease fue reclamado puede confirmar el job de otro. | C | `src/Models/QueueModel.php:237-266`, `RedisBackend.php:141-185` |
| B-03 | No hay renovación de lease: `JobLease::renew()` y `RedisBackend::renewLease()` existen pero nadie los llama y no están en la interfaz. Jobs más largos que el visibility timeout se reentregan mientras siguen ejecutándose. | C | `src/Queues/QueueBackend.php`, `src/Queues/JobLease.php` |
| B-04 | El reaper no incrementa `attempts`: un job que mata al worker (OOM, segfault) se reintenta indefinidamente en BD y Redis. | C | `src/Models/QueueModel.php:216-230`, `RedisBackend.php:200-231` |
| B-05 | Redis: `rpoplpush` + `hSet` no son atómicos; si el worker muere entre ambos el mensaje queda en `processing` sin metadatos y el reaper (que recorre solo el hash) no lo recupera nunca. El reaper también es check-then-act y puede resucitar un job ya confirmado. | C | `RedisBackend.php:110-117`, `:200-231` |
| B-06 | Redis: el constructor traga el fallo de conexión y `fetch()` devuelve `null`; el worker cree que la cola está vacía y el circuit breaker no salta. | C | `RedisBackend.php:53-69`, `:101-106` |
| B-07 | Redis reutiliza la conexión/BD de la caché de CodeIgniter: `cache:clear` ejecuta `flushDB` y borra las colas. | C | `RedisBackend.php:59-64`, `src/Libraries/RedisHandler.php` |
| B-08 | Beanstalk: `nack` hace `delete` y luego `put`; un corte entre ambos pierde el mensaje. Además `nack` reinserta con TTR 3600 en vez del TTR original y la prioridad de la definición se ignora. | C | `BeanstalkBackend.php:201-203` |
| B-09 | `ShellHandler`: lee stdout hasta EOF sin vaciar stderr (bloqueo si el proceso escribe más de ~64 KB en stderr), ignora el código de salida (un comando que falla cuenta como éxito) y no mata el proceso hijo ante timeout. | C | `src/Handlers/ShellHandler.php:41-55` |
| B-10 | Los fallos se descartan sin rastro: `SyncBackend::enqueue()` y `CronRunner::run()` ignoran el `ExecutionResult` (un handler inexistente con `sync` devuelve un id normal — reproducido). La tabla de historial `jobs` se crea en migración pero nunca se escribe. Una excepción al encolar en el cron aborta el resto de jobs del minuto. | R / C | `src/Queues/Backends/SyncBackend.php:48`, `src/Cron/CronRunner.php:71-84` |

## Seguridad

| ID | Hallazgo | Ubicación |
|----|----------|-----------|
| S-01 | `queueHandlers` vacío = sin restricción; `CommandHandler` ejecuta cualquier comando spark recibido por la cola. | `src/Config/Jobs.php:63`, `src/Handlers/HandlerRegistry.php:56-64` |
| S-02 | Sin clave de firma el worker acepta todo sin avisar; el docblock de `EnvelopeSigner` promete un log `critical` que no existe. | `src/Worker/QueueWorker.php:76-86`, `src/Queues/Signing/EnvelopeSigner.php` |
| S-03 | Sin protección contra replay ni cruce de colas: `attempts` no está firmado y el worker no comprueba que `wire->queue` coincida con la cola leída. | `src/Worker/QueueWorker.php` |
| S-04 | `UrlHandler` pasa `options` tal cual a curlrequest: permite `proxy` y `debug` (escribe en una ruta arbitraria). Sin timeout por defecto ni límite de tamaño de respuesta. DNS rebinding abierto (reconocido en el docblock; mitigable fijando la resolución con `CURLOPT_RESOLVE`). | `src/Handlers/UrlHandler.php:41-47` |
| S-05 | `ShellHandler` valida solo el binario: permitir `php`, `find` o `env` equivale a ejecución arbitraria. | `src/Handlers/ShellHandler.php:84-109` |
| S-06 | La clave de firma reutiliza `Encryption->key` sin derivación (HKDF). | `src/Queues/Signing/EnvelopeSigner.php:82-99` |

## Robustez y rendimiento

| ID | Hallazgo | Ubicación |
|----|----------|-----------|
| R-01 | La contención de `singleInstance` cuenta como fallo (consume un reintento y puede acabar en dead-letter); el lock no es atómico y su TTL es de 120 s cuando no hay timeout. | `src/Execution/JobRuntime.php:69-78`, `SingleInstanceLock.php` |
| R-02 | Timeout sin `pcntl` (Windows): el job termina bien pero se marca fallido y se reintenta (ejecución duplicada). | `src/Execution/Timeout.php:48-53` |
| R-03 | `SKIP LOCKED` se desactiva para siempre ante cualquier excepción, incluido un deadlock transitorio. | `src/Models/QueueModel.php:142-150` |
| R-04 | El rate limiter se consume antes del `fetch`: cuenta sondeos, no jobs. | `src/Commands/QueueWorkCommand.php:94-99` |
| R-05 | Service Bus: `?timeout=` en peek-lock parece ser la espera del long-poll, no la duración del lock; de ser así `serviceBusLockTimeout` está mal nombrado y la expiración del lease es incorrecta. **[V]** verificar contra la documentación de Azure. | `ServiceBusBackend.php:97-102` |
| R-06 | `scheduledAt` se formatea sin convertir a `appTimezone` al guardar en BD. | `DatabaseBackend.php:48-57`, `EnvelopeFactory.php:53-55` |
| R-07 | Worker de larga duración: faltan `--memory` y `--max-time`, reconexión de BD, e invocar el reaper desde el bucle. El comando `jobs:queue:reap` elige el timeout de BD para cualquier backend que no sea Redis. | `src/Commands/QueueWorkCommand.php`, `QueueReapCommand.php:50-52` |
| R-08 | Se instancia `QueueModel`, `RateLimiter` y `CircuitBreaker` en cada ciclo. | `DatabaseBackend.php`, `QueueWorkCommand.php:92-106` |
| R-09 | Redis usa el payload completo como clave de hash y `LREM` O(N); mejor ids en las listas y payload en un hash. La prioridad se ignora en Redis y Beanstalk. | `RedisBackend.php` |
| R-10 | Idempotencia y lock no atómicos (get + save); usar `SET NX` cuando la caché lo permita. | `IdempotencyGuard.php`, `SingleInstanceLock.php` |

## Extensibilidad y calidad

| ID | Hallazgo |
|----|----------|
| Q-01 | Sin inyección de dependencias: `BackendFactory` y `HandlerRegistry` hacen `new $class()`; un handler no puede recibir servicios ni un backend su configuración. |
| Q-02 | Sin eventos ni middleware (antes / después / fallo) ni métricas de duración: `MetricsCollectorInterface::observe()` nunca se llama. |
| Q-03 | Código muerto: `DeadLetterQueue` (hasta B-01), `ConfigCache`, `DelayResult`, `JsonPayloadSerializer` + `PayloadSerializerInterface`, `Priority`, `Utils`, `Config\Validation`, `JobEnvelope::fromBackend/fromDecoded`, `DateTimeHelper`, `QueueModel::getJob`, excepciones sin uso, dependencia `michalsn/codeigniter4-uuid`. |
| Q-04 | Configuración fantasma: `blockingFetch`, `blockingFetchTimeout`, `deadLetterQueue` sin efecto; `jobTimeout` solo lo lee Beanstalk; `databaseGroup`/`tableName` duplican `database[...]`. |
| Q-05 | `lang('Job.invalidWorker')` / `Job.invalidQueue` no tienen fichero de idioma: se muestra la clave literal. |
| Q-06 | Documentación incoherente: "Higher = sooner" frente a `ORDER BY priority ASC`; "Empty array allows all" frente a deny-by-default; referencias a clases v1 eliminadas; mezcla de español e inglés en docblocks. |
| Q-07 | Los tests no cubren el recorrido real encolar → worker por handler y backend (habría detectado A-01 a A-04). No hay test de integración contra MySQL para la firma. |
| Q-08 | PHPStan da 168 errores en local con PHP 8.5.12, con aspecto de falso positivo ("return statement is missing" en métodos que sí devuelven). Sin investigar; comprobar el job `analyze` del CI en 8.5. |

## Orden recomendado

1. Críticos A-01…A-05 con un test de integración por handler y backend.
2. Fiabilidad de entrega: B-01…B-08.
3. Seguridad: S-01…S-06 y B-09.
4. Robustez y operación: B-10, R-01…R-10.
5. Limpieza: Q-03…Q-06, Q-08.
6. Extensibilidad: Q-01, Q-02.
