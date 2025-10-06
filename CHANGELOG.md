# Changelog

## Unreleased
- Added JobTestCommandTest to cover custom test CLI command and integration touchpoint in QueuePushTest.
- Renamed test CLI command to `jobs:test` (alias `job:test` retained) to align with existing invocation attempts and remove warning noise.
- BREAKING: Removed unused `WorkerInterface::getDataJob()` method and all queue-specific implementations (DatabaseQueue, RedisQueue, BeanstalkQueue, ServiceBusQueue). Payload hydration will be redesigned under a future JobEnvelope abstraction.
- Introduced and fully adopted `JobEnvelope` DTO across all queue backends (Database, Redis, Beanstalk, ServiceBus) as normalized worker output.
- Added factory helpers `JobEnvelope::fromDecoded()` and `JobEnvelope::fromJob()`; standardized attempts extraction across queues.
- Added `createdAt` field to `JobEnvelope` and populated across all queue backends (Database, Redis, Beanstalk, ServiceBus) to enable age and latency metrics.
- Introduced lightweight metrics subsystem (`MetricsCollectorInterface`, `InMemoryMetricsCollector`) with counters and basic histogram aggregation.
- Instrumented `QueueRunCommand` with fetch/success/fail/requeue counters plus execution and age latency observations.
- Added `RequeueHelper` centralizing success/failure finalization logic (removes duplicated enqueue/remove branches per outcome).
- Removed legacy mixed payload branch from `QueueRunCommand`; it now exclusively handles `JobEnvelope` instances (BREAKING for custom queues still returning raw payloads).
- Added watch tests for Database, Beanstalk, and ServiceBus queues ensuring `JobEnvelope` contract coverage.

### BREAKING: Esquema de logs extendido
- La tabla de logs (definida por `JobsTables` migration) ahora incluye columnas extendidas: `executionId`, `attempt`, `queue`, `source`, `retryStrategy`, `payloadHash`, `outputLength`, `status`, `data`.
- Se eliminó la migración de reconstrucción `RebuildJobsLogTable` por quedar redundante tras actualizar la migración base.
- Instalaciones nuevas: simplemente ejecutar migraciones y obtendrán el nuevo esquema directamente.
- Instalaciones existentes (antes de esta versión):
	1. Hacer copia de seguridad si desean conservar el histórico.
	2. Eliminar (DROP) manualmente la tabla de logs antigua (`<tableName>` del config `Jobs`).
	3. Ejecutar nuevamente las migraciones (re-creará la tabla con el nuevo layout).
	4. (Opcional) Si no necesitan los datos viejos, pueden omitir el backup.
- El campo `data` almacena el JSON completo original para trazabilidad y futuras expansiones sin alterar el resto de columnas indexadas.

### Observabilidad y Seguridad
- Se añaden y persisten hashes (`payloadHash`) y longitud de salida (`outputLength`) para diagnósticos.
- Enmascarado de claves sensibles configurable vía `$sensitiveKeys` en config `Jobs` (aplica tanto a file logger como a database logger).

### Acción Requerida
Si tenías automatizaciones que asumían el layout antiguo (sin columnas extendidas), actualiza tus consultas SELECT e índices externos.


### Removal Notice
The legacy `Daycry\Jobs\Result` shim has been physically removed. Migrate any remaining usages to the lifecycle-provided `ExecutionResult` (returned internally) and return raw values (scalars/arrays/DTOs) from handlers. Attempting to reference the class will now result in an autoload failure.
