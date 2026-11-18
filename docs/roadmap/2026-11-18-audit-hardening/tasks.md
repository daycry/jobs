---
generacion:
  inicio: 2026-11-18T15:44:17Z
  fin: 2026-11-18T15:50:39Z
  fuente: medido
  fuente_id: src-ed46c253af5b178eee8db805396c00639d2a119bff8c5cabc2ad5efdc83a477d
  ventana_id: win-64618f1465f84be5ad5cab12f4393f6d
  revision_medicion: 1
  medicion_persistida: true
  tokens_reales: { entrada: 8, salida: 60623, cache_creacion: 66016, cache_lectura: 253910 }
  eur: null
  horas_ia: 0.42
  duracion: 25m
  ratio_usado: 300000
verificacion: obligatoria   # cada T-XX lleva `- **Verificación**:`; lo exige ledger-lint (exit 1 si falta)
---

# Checklist de Tareas — Endurecimiento tras la auditoría de código (daycry/jobs v3)

| | |
|---|---|
| **Estado** | borrador |
| **Fecha** | 2026-11-18 |
| **Plan** | [`improvement-plan.md`](./improvement-plan.md) |
| **Origen** | [`audit.md`](./audit.md) |
| **Diseño** | n/a |

> **⚠️ Ledger canónico de progreso.** Este fichero es la **fuente única de verdad** del avance del plan. **Cualquier** implementador —el agente `implementer`, el chat principal, o un orquestador SDD externo— **debe** marcar aquí cada tarea (checkbox + estado) al completarla y actualizar el resumen. Los ledgers propios de otras herramientas son **espejo**, no fuente.

> Cifras de tiempo y tokens **base** (sin margen de contingencia; el +20 % está en `improvement-plan.md`). Coste de fase = horas humanas base × 50 €/h; el coste de tokens queda `⚠️ verificar` (sin `rates.json` ni precio vigente).

---

## Resumen de progreso

| Fase | Completadas | Total | Progreso | H. humanas (real/est) | H. IA ejec. (real/est) | Supervisión (real/est) | Tokens (real/est) |
|------|------------|-------|----------|-----------------------|------------------------|------------------------|-------------------|
| Fase 1 — Críticos y red de seguridad | 0 | 7 | 0% | 0 / 20.5h | 0 / 2.34h | 0 / 0.58h | 0 / 701k |
| Fase 2 — Fiabilidad de entrega | 0 | 8 | 0% | 0 / 30.5h | 0 / 3.17h | 0 / 0.79h | 0 / 952k |
| Fase 3 — Seguridad | 0 | 7 | 0% | 0 / 23.5h | 0 / 2.29h | 0 / 0.57h | 0 / 686k |
| Fase 4 — Robustez y operación | 0 | 12 | 0% | 0 / 44.0h | 0 / 4.23h | 0 / 1.06h | 0 / 1.268k |
| Fase 5 — Limpieza | 0 | 5 | 0% | 0 / 15.0h | 0 / 1.43h | 0 / 0.36h | 0 / 430k |
| Fase 6 — Extensibilidad y cierre | 0 | 3 | 0% | 0 / 17.0h | 0 / 1.60h | 0 / 0.40h | 0 / 480k |
| **TOTAL** | **0** | **42** | **0%** | **0 / 150.5h** | **0 / 15.06h** | **0 / 3.76h** | **0 / 4.517k** |

> **Imputación externa opcional.** Sin integración en este proyecto: los datos permanecen locales.

> **Estados no iniciados.** Todas las tareas nacen en `borrador`: el usuario ha decidido no implementar el plan todavía.

---

## Fase 1 — Críticos y red de seguridad

**Estado**: borrador · **Estimado**: 20.5h · **Real**: — · **Coste est.**: 1025 € (base) · **Tokens est.**: 701k

### T-01 — Test de integración encolar → processOnce por handler (red de seguridad)

- **Descripción**: Crear `tests/Unit/V3/Integration/EnqueueToWorkerTest.php`: para cada handler (command, shell, event, url, typed) hace `Jobs::define(...)->queue('default')->dispatch('database')` y `(new QueueWorker(new DatabaseBackend()))->processOnce('default')`, asertando el resultado real. Es la red de seguridad del resto del plan.
- **Hallazgos que cierra**: Q-07 (parcial)
- **Estado**: borrador
- **Tiempo humano**: est. 4.0h · real —
- **Tiempo IA (ejec.)**: est. 0.50h · real —
- **Supervisión**: est. 0.12h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 30k out tok · tokens ⚠️ verificar precio
- **Dependencias**: ninguna
- **Tipo**: test
- **Archivos**: `tests/Unit/V3/Integration/EnqueueToWorkerTest.php (nuevo)`, `tests/_support/DatabaseTestCase.php`
- **Verificación**:
  - `composer test -- --filter EnqueueToWorkerTest` → fallan exactamente event y url (rojo esperado, ligado a T-02); command, shell y typed pasan
  - `composer test` → resto de la suite sin regresiones (360 tests; el nuevo, marcado como fallo conocido hasta T-02)

**Criterios de aceptación**

- [ ] Existe un caso por handler (command, shell, event, url, typed) sobre el backend `database`
- [ ] El test falla hoy para event y url con `EventHandler payload must be an array…` (evidencia pegada en el ledger)
- [ ] El test usa handlers de prueba deterministas (sin red ni procesos externos reales; url con curlrequest simulado)

**Subtareas**

- [ ] Definir handlers de prueba `typed` y `url` (sin red)
- [ ] Escribir los cinco casos y capturar la salida roja
- [ ] Dejar event/url marcados como esperados-rojo con referencia a T-02

**Notas**: Oráculo: capacidad nueva → test nuevo en rojo. Versión: 4.0.0 (solo tests). Patrón de test: `tests/Unit/V3/Worker/QueueWorkerTest.php`, `tests/_support/DatabaseTestCase.php`.

### T-02 — Normalizar el payload a array en un único punto

- **Descripción**: Los backends hacen `json_decode()` sin `assoc` y el payload llega como `stdClass`; event, url y shell lo rechazan. Normalizar el payload a array en `QueueWorker`, un único punto, antes de firma y handler.
- **Hallazgos que cierra**: A-01
- **Estado**: borrador
- **Tiempo humano**: est. 2.0h · real —
- **Tiempo IA (ejec.)**: est. 0.25h · real —
- **Supervisión**: est. 0.06h (≈25 % IA) · real —
- **Previsión IA**: 60k in / 15k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-01
- **Tipo**: backend
- **Archivos**: `src/Worker/QueueWorker.php`, `src/Handlers/ShellHandler.php`, `tests/Unit/V3/Worker/QueueWorkerTest.php`, `tests/Unit/V3/Integration/EnqueueToWorkerTest.php`
- **Verificación**:
  - `composer test -- --filter EnqueueToWorkerTest` → todos los casos en verde (event y url incluidos)
  - `composer test` → suite completa en verde

**Criterios de aceptación**

- [ ] Los cinco handlers se ejecutan desde la cola `database` (T-01 en verde)
- [ ] El payload se normaliza a array solo en `QueueWorker`; los backends no cambian su decodificación
- [ ] `ShellHandler` acepta el payload venido de cola
- [ ] La verificación de firma sigue pasando (test de firma existente en verde)

**Subtareas**

- [ ] Usar el test de T-01 como reproducción mínima
- [ ] Normalizar en `QueueWorker` antes de firma y handler
- [ ] Probar con dobles de Redis/Beanstalk/Service Bus que el worker normaliza

**Notas**: Decisión del usuario, 2026-11-18: normalizar en el worker (un único punto); sin `assoc` en cada backend. Oráculo: defecto → reproducción mínima del fallo. Versión: 4.0.0.

### T-03 — Idempotencia en dos fases (en curso / completado)

- **Descripción**: La clave se registra antes de ejecutar y no se libera al fallar: el reintento da `skipped-idempotent` + ack y el job se pierde. Pasar a estado de dos fases (en curso / completado) con TTL, confirmado solo tras éxito.
- **Hallazgos que cierra**: A-02
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.33h · real —
- **Supervisión**: est. 0.08h (≈25 % IA) · real —
- **Previsión IA**: 80k in / 20k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-01
- **Tipo**: backend
- **Archivos**: `src/Execution/IdempotencyGuard.php`, `src/Worker/QueueWorker.php`, `tests/Unit/V3/Execution/IdempotencyGuardTest.php`, `tests/Unit/V3/Worker/QueueWorkerTest.php`
- **Verificación**:
  - `composer test -- --filter 'IdempotencyGuardTest|QueueWorkerTest'` → incluye la reproducción (handler `flaky`: 1.º intento requeued, 2.º ejecuta y completa) en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] Un job que falla y se reintenta se vuelve a ejecutar
- [ ] Un job completado con éxito no se ejecuta dos veces (skipped-idempotent solo tras `completado`)
- [ ] La fase `en curso` tiene TTL: una clave en curso caducada (worker muerto) no bloquea indefinidamente

**Subtareas**

- [ ] Test de reproducción con handler `flaky` en rojo
- [ ] Implementar estados en curso (con TTL) y completado
- [ ] Documentar el TTL de la fase en curso

**Notas**: Decisión del usuario, 2026-11-18: idempotencia en dos fases con TTL (no solo `forget()` al fallar). Oráculo: defecto → reproducción mínima. Versión: 4.0.0.

### T-04 — Campo de versión en el wire de 4.0 y rechazo de mensajes sin versión

- **Descripción**: Añadir al sobre un campo de versión explícito (`v`) para detectar formatos futuros. Un mensaje sin `v` (encolado con 3.x) no se ejecuta: se rechaza con un error claro y sigue el camino de dead-letter del worker. No hay lector dual v1/v2; la actualización exige drenar las colas antes de desplegar 4.0.
- **Hallazgos que cierra**: A-03, A-04 (base del formato), mensajes 3.x ya encolados
- **Estado**: borrador
- **Tiempo humano**: est. 1.5h · real —
- **Tiempo IA (ejec.)**: est. 0.17h · real —
- **Supervisión**: est. 0.04h (≈25 % IA) · real —
- **Previsión IA**: 40k in / 10k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-02
- **Tipo**: backend
- **Archivos**: `src/Queues/EnvelopeFactory.php`, `src/Queues/Signing/EnvelopeSigner.php`, `src/Worker/QueueWorker.php`, `tests/Unit/V3/Queues/EnvelopeFactoryTest.php`, `tests/Unit/V3/Worker/QueueWorkerTest.php`
- **Verificación**:
  - `composer test -- --filter 'EnvelopeFactoryTest|QueueWorkerTest'` → un sobre con `v` válido se ejecuta; sin `v` o con `v` desconocido el resultado es `dead-lettered` con mensaje claro
  - `composer test` → suite en verde (fixtures de sobres actualizados al formato 4.0)

**Criterios de aceptación**

- [ ] Todo sobre emitido lleva `v` y `v` forma parte de los campos firmados
- [ ] Un mensaje sin `v` (3.x) se rechaza con un error que indica «drenar colas antes de actualizar» y no ejecuta el handler
- [ ] Un `v` desconocido se rechaza igual (detección de formatos futuros)

**Subtareas**

- [ ] Test en rojo: sobre sin `v` hoy se ejecuta
- [ ] Añadir `v` al wire y a la firma
- [ ] Rechazo con error claro por el camino dead-letter existente del worker

**Notas**: Sin lector dual: los mensajes 3.x en vuelo no se procesan en 4.0, por eso UPGRADE exige drenar antes (T-42). El destino final del dead-letter lo completa T-10. Oráculo: capacidad nueva → test nuevo en rojo. Versión: 4.0.0.

### T-05 — Firma estable en MySQL: canonicalización determinista (+ test MySQL en CI)

- **Descripción**: MySQL reordena las claves de la columna `json` y la firma de `canonicalJson()` deja de verificar. Aplicar canonicalización con `ksort` recursivo en firma y verificación y cambiar la columna `payload` a `TEXT` con migración; añadir un test de extremo a extremo contra MySQL 8 en CI.
- **Hallazgos que cierra**: A-03, Q-07 (MySQL)
- **Estado**: borrador
- **Tiempo humano**: est. 4.5h · real —
- **Tiempo IA (ejec.)**: est. 0.46h · real —
- **Supervisión**: est. 0.12h (≈25 % IA) · real —
- **Previsión IA**: 110k in / 28k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-04
- **Tipo**: backend
- **Archivos**: `src/Queues/EnvelopeFactory.php`, `src/Database/Migrations/ (nueva: `payload` a TEXT)`, `tests/Unit/V3/Queues/EnvelopeFactoryTest.php`, `tests/Unit/V3/Integration/MysqlSignatureTest.php (nuevo)`, `.github/workflows/php.yml`
- **Verificación**:
  - `composer test -- --filter EnvelopeFactoryTest` → un sobre con claves reordenadas verifica igual (reproducción de A-03 en verde)
  - `composer test -- --filter MysqlSignatureTest` → en CI (MySQL 8) pasa; en local con SQLite se omite con motivo
  - `php spark migrate` y `php spark migrate:rollback` sobre MySQL 8 en CI → la columna `payload` pasa a TEXT y vuelve a su tipo anterior sin error
  - `gh run list --workflow php.yml --limit 1` → job MySQL en verde tras el push (verificación manual en PR)

**Criterios de aceptación**

- [ ] La firma verifica tras un ciclo MySQL real (encolar → fetch → verificar)
- [ ] Firma y verificación usan `ksort` recursivo (independiente del motor)
- [ ] La migración cambia `payload` a `TEXT` y es reversible
- [ ] Una clave de sobre manipulada sigue siendo rechazada
- [ ] El job MySQL del CI ejecuta el test (no queda omitido)

**Subtareas**

- [ ] Test de reproducción: reordenar claves y verificar → rojo
- [ ] Implementar `ksort` recursivo en canonicalización
- [ ] Migración de la columna `payload` a `TEXT`
- [ ] Habilitar el test en la matriz de CI con MySQL 8

**Notas**: Decisión del usuario, 2026-11-18: ambas medidas: `ksort` recursivo y columna `payload` a `TEXT` (+0,5 h ya incluidos en la estimación). La migración requiere drenar colas, ya obligatorio en 4.0. Oráculo: defecto → reproducción mínima. Versión: 4.0.0.

### T-06 — timeout, singleInstance y meta viajan en el wire

- **Descripción**: Estos tres campos se pierden al encolar. Añadirlos al wire de 4.0 y a los campos firmados, y reconstruirlos en `definitionFromWire()`.
- **Hallazgos que cierra**: A-04
- **Estado**: borrador
- **Tiempo humano**: est. 2.5h · real —
- **Tiempo IA (ejec.)**: est. 0.29h · real —
- **Supervisión**: est. 0.07h (≈25 % IA) · real —
- **Previsión IA**: 70k in / 18k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-04
- **Tipo**: backend
- **Archivos**: `src/Queues/EnvelopeFactory.php`, `src/Worker/QueueWorker.php`, `tests/Unit/V3/Queues/EnvelopeFactoryTest.php`, `tests/Unit/V3/Worker/QueueWorkerTest.php`
- **Verificación**:
  - `composer test -- --filter 'EnvelopeFactoryTest|QueueWorkerTest'` → test de ida y vuelta (definición → wire → definición) conserva los tres campos
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] `timeout`, `singleInstance` y `meta` se conservan tras encolar y desencolar
- [ ] Los tres campos están dentro de los campos firmados (alterarlos invalida la firma)
- [ ] Sin compatibilidad con el formato 3.x (ver T-04)

**Subtareas**

- [ ] Reproducción: la ida y vuelta pierde los campos
- [ ] Añadir los campos al wire y a la firma
- [ ] Reconstruir en el worker y pasar timeout al runtime

**Notas**: Parte del formato de 4.0. Oráculo: defecto → reproducción mínima. Versión: 4.0.0.

### T-07 — Scheduler: nombre de respaldo sin serializar closures y rechazo de duplicados

- **Descripción**: Un job `closure` sin `->named()` lanza `Serialization of 'Closure' is not allowed` y los nombres duplicados se pisan en silencio.
- **Hallazgos que cierra**: A-05
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.33h · real —
- **Supervisión**: est. 0.08h (≈25 % IA) · real —
- **Previsión IA**: 80k in / 20k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-01
- **Tipo**: backend
- **Archivos**: `src/Cron/Scheduler.php`, `tests/Unit/V3/Cron/SchedulerTest.php`
- **Verificación**:
  - `composer test -- --filter SchedulerTest` → reproducción (closure sin nombre; 4 registrados con duplicados) en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] Un closure sin nombre se registra con un nombre de respaldo estable sin serializar la closure
- [ ] Un nombre duplicado (explícito o derivado) lanza una excepción clara
- [ ] Un job inválido no tumba el cron entero (coordinado con T-24)

**Subtareas**

- [ ] Reproducción mínima en rojo (closure sin nombre; 4 registrados → 2 ejecutados)
- [ ] Nombre de respaldo determinista (p. ej. hash de fichero:línea del closure)
- [ ] Excepción por duplicado

**Notas**: Decisión del usuario, 2026-11-18: excepción ante nombres duplicados (explícitos o derivados), sin alternativa de log y conservar el primero. Rompe configuraciones que hoy pisan sin saberlo. Oráculo: defecto → reproducción mínima. Versión: 4.0.0.

---

## Fase 2 — Fiabilidad de entrega

**Estado**: borrador · **Estimado**: 30.5h · **Real**: — · **Coste est.**: 1525 € (base) · **Tokens est.**: 952k

### T-08 — Verificar el ownerToken en ack/nack/markStatus

- **Descripción**: El `ownerToken` se guarda pero no se comprueba: un worker con el lease reclamado puede confirmar el job de otro.
- **Hallazgos que cierra**: B-02
- **Estado**: borrador
- **Tiempo humano**: est. 4.0h · real —
- **Tiempo IA (ejec.)**: est. 0.42h · real —
- **Supervisión**: est. 0.10h (≈25 % IA) · real —
- **Previsión IA**: 100k in / 25k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-02
- **Tipo**: backend
- **Archivos**: `src/Models/QueueModel.php`, `src/Queues/Backends/DatabaseBackend.php`, `src/Queues/Backends/RedisBackend.php`, `tests/Unit/V3/Queues/DatabaseBackendTest.php`, `tests/Unit/V3/Queues/RedisBackendTest.php`
- **Verificación**:
  - `composer test -- --filter 'DatabaseBackendTest|RedisBackendTest|CovQueueModelTest'` → reproducción (ack con token ajeno no afecta) en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] `ack`/`nack`/`markStatus` con un token que no es el del lease actual no modifican el job y lo informan
- [ ] Redis compara token y valor de forma atómica (script Lua o equivalente)
- [ ] El flujo normal (mismo token) no cambia

**Subtareas**

- [ ] Reproducción: worker A reclamado, worker B ejecuta, A confirma
- [ ] Filtrar por `id` + `owner_token` en BD
- [ ] Equivalente en Redis

**Notas**: Oráculo: defecto → reproducción mínima. Versión: 4.0.0.

### T-09 — El reaper incrementa attempts

- **Descripción**: Un job que mata al worker se reintenta indefinidamente porque el reaper no cuenta el intento.
- **Hallazgos que cierra**: B-04
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.33h · real —
- **Supervisión**: est. 0.08h (≈25 % IA) · real —
- **Previsión IA**: 80k in / 20k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-08
- **Tipo**: backend
- **Archivos**: `src/Models/QueueModel.php`, `src/Queues/Backends/RedisBackend.php`, `src/Commands/QueueReapCommand.php`, `tests/Unit/V3/Queues/DatabaseBackendTest.php`, `tests/Unit/V3/Queues/RedisBackendTest.php`
- **Verificación**:
  - `composer test -- --filter 'DatabaseBackendTest|RedisBackendTest|QueueV3CommandsTest'` → reproducción (reap repetido termina en fallo definitivo) en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] Cada recuperación del reaper suma un intento
- [ ] Al superar `maxRetries` el job pasa a estado final (dead-letter cuando exista T-10)
- [ ] Un job recuperado dentro del límite sigue siendo reentregable

**Subtareas**

- [ ] Reproducción: lease caducado repetido ⇒ reintento infinito
- [ ] Incrementar attempts en BD y Redis
- [ ] Cubrir el caso límite maxRetries

**Notas**: Oráculo: defecto → reproducción mínima. Versión: 4.0.0.

### T-10 — Dead-letter real por backend

- **Descripción**: Hoy no hay dead-letter: `DeadLetterQueue` nunca se invoca y en Service Bus/Redis el abandono borra el mensaje. Implementar el dead-letter real como estado en la misma tabla/lista (`bury` en Beanstalk, dead-letter nativo en Service Bus) y hacer que `Config\Jobs::$deadLetterQueue` surta efecto.
- **Hallazgos que cierra**: B-01, Q-03 (DeadLetterQueue), Q-04 (deadLetterQueue)
- **Estado**: borrador
- **Tiempo humano**: est. 6.0h · real —
- **Tiempo IA (ejec.)**: est. 0.63h · real —
- **Supervisión**: est. 0.16h (≈25 % IA) · real —
- **Previsión IA**: 150k in / 40k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-09
- **Tipo**: backend
- **Archivos**: `src/Worker/QueueWorker.php`, `src/Queues/Backends/ServiceBusBackend.php`, `src/Queues/Backends/RedisBackend.php`, `src/Queues/Backends/BeanstalkBackend.php`, `src/Queues/Backends/DatabaseBackend.php`, `src/Libraries/DeadLetterQueue.php`, `src/Config/Jobs.php`, `src/Database/Migrations/ (si se añade estado/tabla)`, `tests/Unit/V3/Worker/QueueWorkerTest.php`, `docs/QUEUES.md`
- **Verificación**:
  - `composer test -- --filter 'QueueWorkerTest|ServiceBusBackendTest|RedisBackendTest|DatabaseBackendTest'` → tests nuevos en rojo primero, luego verde: un job agotado termina en dead-letter y es inspeccionable
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] Un job que agota reintentos queda recuperable (no se borra) en cada backend: estado `dead` en BD, lista/estado en Redis, `bury` en Beanstalk
- [ ] En Service Bus se usa dead-letter nativo (no `DELETE`)
- [ ] `Config\Jobs::$deadLetterQueue` tiene efecto o se retira (decisión recogida en T-37)
- [ ] Los comentarios que afirman «el runtime reenvía al DLQ» son ciertos o se corrigen

**Subtareas**

- [ ] Test nuevo en rojo por backend
- [ ] Contrato único invocado por el worker y respaldado por cada backend
- [ ] Migración de BD: estado `dead` en la misma tabla
- [ ] Documentar en docs/QUEUES.md

**Notas**: Capacidad nueva → test nuevo en rojo. Decisión del usuario, 2026-11-18: dead-letter como estado en la misma tabla/lista, con `bury` o dead-letter nativo según backend; sin cola/tabla separada. Amplía `QueueBackend` junto con T-11. Versión: 4.0.0. Migración de BD incluida en 4.0.

### T-11 — Renovación de lease durante la ejecución

- **Descripción**: Los jobs más largos que el visibility timeout se reentregan mientras siguen ejecutándose. Ampliar directamente la interfaz `QueueBackend` con la renovación del lease y hacer que el worker la use.
- **Hallazgos que cierra**: B-03
- **Estado**: borrador
- **Tiempo humano**: est. 5.5h · real —
- **Tiempo IA (ejec.)**: est. 0.58h · real —
- **Supervisión**: est. 0.15h (≈25 % IA) · real —
- **Previsión IA**: 140k in / 35k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-08
- **Tipo**: backend
- **Archivos**: `src/Queues/QueueBackend.php`, `src/Queues/JobLease.php`, `src/Worker/QueueWorker.php`, `src/Queues/Backends/*.php`, `tests/Unit/V3/Queues/JobLeaseTest.php`, `tests/Unit/V3/Worker/QueueWorkerTest.php`
- **Verificación**:
  - `composer test -- --filter 'JobLeaseTest|QueueWorkerTest'` → test nuevo en rojo primero: un job de duración > timeout no se reentrega y el lease se renueva
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] `QueueBackend` declara la renovación de lease y los cinco backends la implementan (los que no pueden, con una implementación explícita documentada)
- [ ] Mientras el job se ejecuta el lease se renueva antes de caducar (BD, Redis; Service Bus/Beanstalk según soporte, documentado)
- [ ] Los backends propios de terceros deben implementar el método nuevo: queda en la guía de migración (T-42)

**Subtareas**

- [ ] Test nuevo en rojo (job largo con reloj simulado)
- [ ] Diseñar el mecanismo (`pcntl_alarm`, renovación entre fases o proceso auxiliar)
- [ ] Implementar por backend

**Notas**: Capacidad nueva → test nuevo en rojo. Decisión tomada (versión mayor): se amplía `QueueBackend`, sin interfaz opcional. Rompe a quien tenga backends propios. La renovación sin hilos en PHP es limitada: documentar. Versión: 4.0.0.

### T-12 — Redis: reserva atómica y reaper sin carreras

- **Descripción**: `rpoplpush` + `hSet` no son atómicos y el reaper es check-then-act. Reservar y registrar metadatos atómicamente (script Lua o `LMOVE` + estructura recorrible) y hacer el reaper atómico.
- **Hallazgos que cierra**: B-05
- **Estado**: borrador
- **Tiempo humano**: est. 5.0h · real —
- **Tiempo IA (ejec.)**: est. 0.50h · real —
- **Supervisión**: est. 0.12h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 30k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-08
- **Tipo**: backend
- **Archivos**: `src/Queues/Backends/RedisBackend.php`, `tests/Unit/V3/Queues/RedisBackendTest.php`, `tests/Unit/V3/Coverage/CovRedisBackendTest.php`
- **Verificación**:
  - `composer test -- --filter 'RedisBackendTest|CovRedisBackendTest'` → reproducción (muerte entre rpoplpush y hSet recuperable) en verde; con Redis real en CI (service redis:7)
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] Un mensaje nunca queda en `processing` sin metadatos recuperables
- [ ] El reaper no resucita un job ya confirmado
- [ ] Prueba contra Redis real en CI

**Subtareas**

- [ ] Reproducción simulada del corte entre ambos pasos
- [ ] Script Lua de fetch+registro y de reap
- [ ] Verificar contra Redis 7 del CI

**Notas**: Coordinar con T-33 (R-09), que reestructura las claves; hacer T-12 primero evita doble migración. Oráculo: defecto → reproducción mínima. Versión: 4.0.0. El formato de claves nuevo no tiene lector dual (drenar antes; ver T-33).

### T-13 — Redis: no ocultar el fallo de conexión

- **Descripción**: El constructor traga el error y `fetch()` devuelve `null`: el worker cree que la cola está vacía y el circuit breaker no salta. Propagar el fallo.
- **Hallazgos que cierra**: B-06
- **Estado**: borrador
- **Tiempo humano**: est. 2.0h · real —
- **Tiempo IA (ejec.)**: est. 0.21h · real —
- **Supervisión**: est. 0.05h (≈25 % IA) · real —
- **Previsión IA**: 50k in / 12k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-01
- **Tipo**: backend
- **Archivos**: `src/Queues/Backends/RedisBackend.php`, `src/Worker/QueueWorker.php`, `tests/Unit/V3/Queues/RedisBackendTest.php`
- **Verificación**:
  - `composer test -- --filter RedisBackendTest` → reproducción (conexión caída ⇒ excepción y circuit breaker abre) en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] Un fallo de conexión lanza una excepción tipada, no `null`
- [ ] El worker lo contabiliza en el circuit breaker
- [ ] Una cola vacía real sigue devolviendo `null`

**Subtareas**

- [ ] Reproducción con conexión simulada fallida
- [ ] Lanzar `QueueException` en conexión/fetch
- [ ] Probar la apertura del circuit breaker

**Notas**: Oráculo: defecto → reproducción mínima. Versión: 4.0.0.

### T-14 — Redis: conexión y BD propias, no las de la caché

- **Descripción**: Redis reutiliza la conexión/BD de la caché de CodeIgniter y `cache:clear` ejecuta `flushDB`, borrando las colas. En 4.0 el backend usa por defecto conexión/BD propias.
- **Hallazgos que cierra**: B-07
- **Estado**: borrador
- **Tiempo humano**: est. 2.0h · real —
- **Tiempo IA (ejec.)**: est. 0.21h · real —
- **Supervisión**: est. 0.05h (≈25 % IA) · real —
- **Previsión IA**: 50k in / 12k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-13
- **Tipo**: backend
- **Archivos**: `src/Queues/Backends/RedisBackend.php`, `src/Libraries/RedisHandler.php`, `src/Config/Jobs.php`, `docs/CONFIGURATION.md`, `tests/Unit/V3/Queues/RedisBackendTest.php`
- **Verificación**:
  - `composer test -- --filter RedisBackendTest` → test que verifica el uso de la BD configurada para colas y no la de caché, en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] La conexión/BD dedicada a colas es el valor por defecto y está documentada
- [ ] El backend no comparte BD con la caché salvo que se configure expresamente
- [ ] La guía de migración indica que las colas Redis 3.x deben drenarse (T-42)

**Subtareas**

- [ ] Reproducción: `cache:clear` vacía las colas
- [ ] Añadir opciones de conexión propias en `Config\Jobs`
- [ ] Documentar

**Notas**: Defecto nuevo directamente en 4.0, sin opt-in. Oráculo: defecto → reproducción mínima. Versión: 4.0.0.

### T-15 — Beanstalk: nack atómico, TTR y prioridad originales

- **Descripción**: `nack` hace `delete` + `put` (un corte pierde el mensaje), reinserta con TTR 3600 y la prioridad se ignora.
- **Hallazgos que cierra**: B-08
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.29h · real —
- **Supervisión**: est. 0.07h (≈25 % IA) · real —
- **Previsión IA**: 70k in / 18k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-08
- **Tipo**: backend
- **Archivos**: `src/Queues/Backends/BeanstalkBackend.php`, `tests/Unit/V3/Queues/BeanstalkBackendTest.php`, `tests/Unit/V3/Coverage/CovBeanstalkBackendTest.php`
- **Verificación**:
  - `composer test -- --filter 'BeanstalkBackendTest|CovBeanstalkBackendTest'` → reproducción en verde (se usa `release` con delay/prioridad en lugar de delete+put)
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] `nack` reencola con `release` (operación única) conservando prioridad y TTR originales
- [ ] La prioridad de la definición se envía en `put`
- [ ] No hay ventana de pérdida entre dos operaciones

**Subtareas**

- [ ] Reproducción con dobles de Pheanstalk
- [ ] Sustituir delete+put por release
- [ ] Pasar prioridad/TTR del sobre

**Notas**: Oráculo: defecto → reproducción mínima. Coordinar con T-33 (prioridad). Versión: 4.0.0.

---

## Fase 3 — Seguridad

**Estado**: borrador · **Estimado**: 23.5h · **Real**: — · **Coste est.**: 1175 € (base) · **Tokens est.**: 686k

### T-16 — queueHandlers: deny-by-default efectivo

- **Descripción**: `queueHandlers` vacío significa «sin restricción» y `CommandHandler` ejecuta cualquier comando spark recibido por la cola. Hacer que vacío = ninguno permitido (con lista explícita o `'*'` para abrir).
- **Hallazgos que cierra**: S-01
- **Estado**: borrador
- **Tiempo humano**: est. 2.5h · real —
- **Tiempo IA (ejec.)**: est. 0.25h · real —
- **Supervisión**: est. 0.06h (≈25 % IA) · real —
- **Previsión IA**: 60k in / 15k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-02
- **Tipo**: backend
- **Archivos**: `src/Config/Jobs.php`, `src/Handlers/HandlerRegistry.php`, `tests/Unit/V3/Handlers/HandlerRegistryTest.php`, `docs/security.md`, `docs/CONFIGURATION.md`
- **Verificación**:
  - `composer test -- --filter HandlerRegistryTest` → test existente modificado (vacío ⇒ rechaza) en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] Con `queueHandlers` vacío el worker rechaza todo handler e informa de la causa
- [ ] Existe forma explícita de permitir todo (`'*'`) documentada como no recomendada
- [ ] Un comando fuera de lista se rechaza en `CommandHandler`

**Subtareas**

- [ ] Modificar el test existente a la nueva semántica (rojo)
- [ ] Cambiar la regla en `HandlerRegistry`
- [ ] Actualizar docs (cierra parte de Q-06)

**Notas**: Oráculo: cambio de comportamiento que hoy funciona → test existente modificado. Defecto seguro directamente en 4.0, sin fase de aviso. ROMPE COMPATIBILIDAD de valores por defecto. Versión: 4.0.0.

### T-17 — Firma obligatoria o aviso crítico si falta la clave

- **Descripción**: Sin clave de firma el worker acepta todo sin avisar; el docblock promete un log `critical` que no existe. En 4.0 la firma es obligatoria por defecto.
- **Hallazgos que cierra**: S-02
- **Estado**: borrador
- **Tiempo humano**: est. 2.5h · real —
- **Tiempo IA (ejec.)**: est. 0.25h · real —
- **Supervisión**: est. 0.06h (≈25 % IA) · real —
- **Previsión IA**: 60k in / 15k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-04
- **Tipo**: backend
- **Archivos**: `src/Worker/QueueWorker.php`, `src/Queues/Signing/EnvelopeSigner.php`, `src/Config/Jobs.php`, `tests/Unit/V3/Queues/EnvelopeSignerTest.php`, `tests/Unit/V3/Worker/QueueWorkerTest.php`, `docs/security.md`
- **Verificación**:
  - `composer test -- --filter 'EnvelopeSignerTest|QueueWorkerTest'` → reproducción (sin clave ⇒ log critical o rechazo según modo) en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] Sin clave configurada el worker rechaza los mensajes con un `critical` visible (`requireSignature=true` por defecto)
- [ ] Existe forma explícita de desactivar la exigencia, documentada como no recomendada
- [ ] El docblock coincide con el comportamiento real; con clave el flujo no cambia

**Subtareas**

- [ ] Reproducción: sin clave no se emite ningún log
- [ ] Defecto `requireSignature=true` y log crítico
- [ ] Alinear el docblock

**Notas**: Oráculo: defecto → reproducción mínima. Rechazo por defecto en 4.0, sin fase de aviso. Versión: 4.0.0.

### T-18 — Anti-replay y verificación de cola en el sobre

- **Descripción**: `attempts` no está firmado y no se comprueba que `wire->queue` coincida con la cola leída: replay y cruce de colas.
- **Hallazgos que cierra**: S-03
- **Estado**: borrador
- **Tiempo humano**: est. 4.0h · real —
- **Tiempo IA (ejec.)**: est. 0.37h · real —
- **Supervisión**: est. 0.09h (≈25 % IA) · real —
- **Previsión IA**: 90k in / 22k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-04, T-17
- **Tipo**: backend
- **Archivos**: `src/Queues/EnvelopeFactory.php`, `src/Worker/QueueWorker.php`, `tests/Unit/V3/Worker/QueueWorkerTest.php`, `tests/Unit/V3/Queues/EnvelopeFactoryTest.php`
- **Verificación**:
  - `composer test -- --filter 'QueueWorkerTest|EnvelopeFactoryTest'` → reproducción (sobre de otra cola rechazado; sobre reutilizado rechazado) en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] El worker rechaza un sobre cuyo `queue` no coincide con la cola leída
- [ ] Existe protección frente a replay por caducidad firmada (`createdAt` incluido en los campos firmados) con ventana configurable; un sobre fuera de la ventana se rechaza
- [ ] `attempts` deja de ser fuente de verdad del backend; se documenta su estatus

**Subtareas**

- [ ] Reproducción de ambos ataques
- [ ] Incluir queue y `createdAt` en campos firmados; ventana de caducidad configurable
- [ ] Documentar límites (replay dentro de la ventana)

**Notas**: Parte del wire de 4.0 (cambio de firma). Decisión del usuario, 2026-11-18: caducidad por `createdAt` firmado (no requiere almacén compartido); se descarta el registro de ids vistos. Oráculo: defecto → reproducción mínima. Versión: 4.0.0.

### T-19 — UrlHandler: opciones permitidas, timeout y tope de respuesta, anti-rebinding

- **Descripción**: `UrlHandler` pasa `options` tal cual a curlrequest (proxy, debug a ruta arbitraria), sin timeout ni límite de respuesta; DNS rebinding abierto.
- **Hallazgos que cierra**: S-04
- **Estado**: borrador
- **Tiempo humano**: est. 5.0h · real —
- **Tiempo IA (ejec.)**: est. 0.50h · real —
- **Supervisión**: est. 0.12h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 30k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-02
- **Tipo**: backend
- **Archivos**: `src/Handlers/UrlHandler.php`, `src/Config/Jobs.php`, `tests/Unit/V3/Handlers/UrlHandlerTest.php`, `tests/Unit/V3/Coverage/CovUrlHandlerTest.php`, `docs/handlers.md`, `docs/security.md`
- **Verificación**:
  - `composer test -- --filter 'UrlHandlerTest|CovUrlHandlerTest'` → reproducciones (`proxy` y `debug` rechazados; sin timeout ⇒ valor por defecto; respuesta > tope rechazada) en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] Solo se admiten opciones de una lista blanca; `proxy` y `debug` se rechazan
- [ ] Hay timeout por defecto y tope de tamaño de respuesta configurables
- [ ] La resolución se fija con `CURLOPT_RESOLVE` tras validar la IP (cierra rebinding) o se documenta la limitación residual

**Subtareas**

- [ ] Reproducción de cada abuso
- [ ] Lista blanca de opciones
- [ ] Fijar resolución validada con curlrequest
- [ ] Actualizar documentación

**Notas**: Oráculo: defecto → reproducción mínima. Lista blanca estricta directamente en 4.0 (rechazar `proxy`/`debug` rompe a quien los use: guía de migración). Versión: 4.0.0.

### T-20 — ShellHandler: stderr, código de salida y timeout

- **Descripción**: Lee stdout hasta EOF sin vaciar stderr (bloqueo), ignora el código de salida y no mata el hijo ante timeout.
- **Hallazgos que cierra**: B-09
- **Estado**: borrador
- **Tiempo humano**: est. 4.0h · real —
- **Tiempo IA (ejec.)**: est. 0.37h · real —
- **Supervisión**: est. 0.09h (≈25 % IA) · real —
- **Previsión IA**: 90k in / 22k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-02
- **Tipo**: backend
- **Archivos**: `src/Handlers/ShellHandler.php`, `tests/Unit/V3/Handlers/ShellHandlerTest.php`, `tests/Unit/V3/Coverage/CovShellHandlerTest.php`
- **Verificación**:
  - `composer test -- --filter 'ShellHandlerTest|CovShellHandlerTest'` → reproducciones (stderr > 64 KB no bloquea; salida ≠ 0 ⇒ fallo; timeout mata el proceso) en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] Se leen stdout y stderr sin bloqueo (streams no bloqueantes y `stream_select`)
- [ ] Un código de salida distinto de 0 es un fallo con stderr en el mensaje
- [ ] Ante timeout se termina el proceso hijo

**Subtareas**

- [ ] Reproducciones (comandos de prueba portables, p. ej. `php -r`)
- [ ] Reescribir la lectura con `stream_select`
- [ ] Cerrar con `proc_terminate`/`proc_close`

**Notas**: Oráculo: defecto → reproducción mínima. Cambio visible: comandos con salida ≠ 0 que hoy cuentan como éxito pasarán a fallar; va a la sección de cambios incompatibles. Versión: 4.0.0.

### T-21 — ShellHandler: restricción más allá del binario

- **Descripción**: Validar solo el binario hace que permitir `php`, `find` o `env` equivalga a ejecución arbitraria. Permitir listas por comando+argumentos o plantillas con parámetros.
- **Hallazgos que cierra**: S-05
- **Estado**: borrador
- **Tiempo humano**: est. 3.5h · real —
- **Tiempo IA (ejec.)**: est. 0.33h · real —
- **Supervisión**: est. 0.08h (≈25 % IA) · real —
- **Previsión IA**: 80k in / 20k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-20, T-16
- **Tipo**: backend
- **Archivos**: `src/Handlers/ShellHandler.php`, `src/Config/Jobs.php`, `tests/Unit/V3/Handlers/ShellHandlerTest.php`, `docs/security.md`
- **Verificación**:
  - `composer test -- --filter ShellHandlerTest` → nuevos casos (binario permitido con argumentos no permitidos rechazado) en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] Se puede permitir un comando con patrón de argumentos explícito, no solo el binario
- [ ] Los binarios peligrosos conocidos (intérpretes, `env`, `find -exec`) se señalan en la documentación
- [ ] La validación solo-binario deja de ser el comportamiento por defecto

**Subtareas**

- [ ] Diseñar el formato de lista blanca (binario + patrón)
- [ ] Implementar la validación
- [ ] Documentar riesgos

**Notas**: Se trata como defecto de diseño: la reproducción mínima es que con `php` permitido se ejecuta código arbitrario. DECISIÓN de formato dentro de la tarea: lista blanca de plantillas (estricta). Defecto nuevo directamente en 4.0. Versión: 4.0.0.

### T-22 — Derivar la clave de firma con HKDF

- **Descripción**: La clave de firma reutiliza `Encryption->key` sin derivación. Derivar una subclave con HKDF y etiqueta de contexto.
- **Hallazgos que cierra**: S-06
- **Estado**: borrador
- **Tiempo humano**: est. 2.0h · real —
- **Tiempo IA (ejec.)**: est. 0.21h · real —
- **Supervisión**: est. 0.05h (≈25 % IA) · real —
- **Previsión IA**: 50k in / 12k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-04
- **Tipo**: backend
- **Archivos**: `src/Queues/Signing/EnvelopeSigner.php`, `tests/Unit/V3/Queues/EnvelopeSignerTest.php`, `docs/security.md`
- **Verificación**:
  - `composer test -- --filter EnvelopeSignerTest` → sobres firmados con la clave HKDF verifican; uno firmado con la clave sin derivar se rechaza
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] La clave de firma es `hash_hkdf` de la clave de cifrado con contexto propio
- [ ] No se mantiene verificador con la clave sin derivar (sin lector dual)
- [ ] Existe clave dedicada opcional para firmar

**Subtareas**

- [ ] Derivación incluida en el formato de 4.0 (T-04)
- [ ] Documentar rotación

**Notas**: Cambia la clave efectiva: cualquier mensaje 3.x queda inválido, cubierto por el drenado obligatorio. Oráculo: comportamiento que hoy funciona → test existente modificado más test nuevo de derivación. Versión: 4.0.0.

---

## Fase 4 — Robustez y operación

**Estado**: borrador · **Estimado**: 44.0h · **Real**: — · **Coste est.**: 2200 € (base) · **Tokens est.**: 1.268k

### T-23 — Investigar Service Bus: semántica de ?timeout= en peek-lock

- **Descripción**: Verificar contra la documentación oficial de Azure si `?timeout=` en peek-lock es la espera del long-poll o la duración del lock. Producir un informe; no tocar código hasta entonces.
- **Hallazgos que cierra**: R-05 (hipótesis [V])
- **Estado**: borrador
- **Tiempo humano**: est. 2.0h · real —
- **Tiempo IA (ejec.)**: est. 0.19h · real —
- **Supervisión**: est. 0.05h (≈25 % IA) · real —
- **Previsión IA**: 50k in / 8k out tok · tokens ⚠️ verificar precio
- **Dependencias**: ninguna
- **Tipo**: docs
- **Archivos**: `docs/roadmap/2026-11-18-audit-hardening/r05-investigacion.md (nuevo, informe)`
- **Verificación**:
  - lectura: el informe cita la documentación oficial de Azure Service Bus (REST peek-lock) con URL y fecha y concluye CONFIRMADO o REFUTADO
  - `git diff --stat src tests` → vacío (no se cambió código)

**Criterios de aceptación**

- [ ] Conclusión documentada con fuente oficial
- [ ] Si se confirma, se propone el arreglo y se activa T-31; si se refuta, T-31 se cancela con motivo
- [ ] Ningún cambio en `src/` ni `tests/`

**Subtareas**

- [ ] Leer la documentación de Azure
- [ ] Contrastar con el código de `ServiceBusBackend.php:97-102`
- [ ] Registrar el resultado en el ledger

**Notas**: Tarea de investigación previa a cualquier cambio. Oráculo: n/a (evidencia documental). Versión: 4.0.0.

### T-24 — Registrar los fallos: SyncBackend, CronRunner e historial

- **Descripción**: `SyncBackend::enqueue()` y `CronRunner::run()` ignoran el `ExecutionResult`; la tabla `jobs` nunca se escribe; una excepción al encolar en el cron aborta el resto de jobs del minuto.
- **Hallazgos que cierra**: B-10
- **Estado**: borrador
- **Tiempo humano**: est. 6.0h · real —
- **Tiempo IA (ejec.)**: est. 0.58h · real —
- **Supervisión**: est. 0.15h (≈25 % IA) · real —
- **Previsión IA**: 140k in / 35k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-07
- **Tipo**: backend
- **Archivos**: `src/Queues/Backends/SyncBackend.php`, `src/Cron/CronRunner.php`, `src/Models/ (historial)`, `src/Database/Migrations/2025-10-02-103415_JobsTables.php`, `tests/Unit/V3/Queues/SyncBackendTest.php`, `tests/Unit/V3/Cron/CronRunnerTest.php`
- **Verificación**:
  - `composer test -- --filter 'SyncBackendTest|CronRunnerTest'` → reproducciones (handler inexistente con `sync` no devuelve id normal; fallo en un job no aborta los demás; la ejecución queda registrada en la tabla `jobs`) en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] `SyncBackend` propaga o devuelve el fallo del `ExecutionResult`
- [ ] `CronRunner` aísla cada job (try/catch) y continúa; el error queda en log/historial
- [ ] Cada ejecución (sync, cron y worker) escribe una fila en la tabla de historial `jobs` con estado, error, duración y salida

**Subtareas**

- [ ] Reproducciones (handler inexistente con sync; excepción en un job del cron)
- [ ] Aislar errores por job
- [ ] Modelo de historial y escritura de cada ejecución; migración de ajuste del esquema si hace falta

**Notas**: Oráculo: defecto → reproducción mínima. Decisión del usuario, 2026-11-18: se escribe el historial en la tabla `jobs`; se descarta retirarla. Versión: 4.0.0. Migración de ajuste del esquema (si procede) en 4.0.

### T-25 — singleInstance: la contención no cuenta como fallo; TTL coherente

- **Descripción**: La contención de `singleInstance` consume un reintento (puede acabar en dead-letter); el lock no es atómico (se completa en T-34) y su TTL es de 120 s sin timeout.
- **Hallazgos que cierra**: R-01
- **Estado**: borrador
- **Tiempo humano**: est. 4.0h · real —
- **Tiempo IA (ejec.)**: est. 0.37h · real —
- **Supervisión**: est. 0.09h (≈25 % IA) · real —
- **Previsión IA**: 90k in / 22k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-06
- **Tipo**: backend
- **Archivos**: `src/Execution/JobRuntime.php`, `src/Execution/SingleInstanceLock.php`, `tests/Unit/V3/Execution/JobRuntimeTest.php`, `tests/Unit/V3/Execution/SingleInstanceLockTest.php`
- **Verificación**:
  - `composer test -- --filter 'JobRuntimeTest|SingleInstanceLockTest'` → reproducciones (contención no consume intento; TTL coherente) en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] Contención = reentrega diferida sin consumir intentos
- [ ] El TTL del lock no es un valor mágico de 120 s (deriva del timeout o es configurable)
- [ ] La adquisición atómica queda cubierta por T-34

**Subtareas**

- [ ] Reproducción: contención agota reintentos
- [ ] Resultado «diferido» en el runtime y manejo en el worker
- [ ] Configurar TTL

**Notas**: Oráculo: defecto → reproducción mínima. Versión: 4.0.0.

### T-26 — Timeout sin pcntl: no marcar fallido un job que terminó bien

- **Descripción**: Sin `pcntl` (Windows) el job termina bien pero se marca fallido y se reintenta (ejecución duplicada). Contarlo como éxito con aviso.
- **Hallazgos que cierra**: R-02
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.29h · real —
- **Supervisión**: est. 0.07h (≈25 % IA) · real —
- **Previsión IA**: 70k in / 18k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-06
- **Tipo**: backend
- **Archivos**: `src/Execution/Timeout.php`, `tests/Unit/V3/Execution/TimeoutTest.php`, `tests/Unit/V3/Coverage/CovTimeoutTest.php`, `docs/concurrency.md`
- **Verificación**:
  - `composer test -- --filter 'TimeoutTest|CovTimeoutTest'` → reproducción en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] Sin `pcntl` un job que termina OK se considera éxito (con aviso de que el timeout no es forzable)
- [ ] El límite no forzable queda documentado
- [ ] Con `pcntl` el comportamiento no cambia

**Subtareas**

- [ ] Reproducción simulando la ausencia de `pcntl`
- [ ] Ajustar la decisión de marcar fallo
- [ ] Documentar

**Notas**: Decisión del usuario, 2026-11-18: sin `pcntl`, un job que termina bien pero excede el timeout cuenta como éxito con aviso (el timeout no es forzable); sin la alternativa de mantener el fallo. Oráculo: defecto → reproducción mínima. Versión: 4.0.0.

### T-27 — SKIP LOCKED: no desactivarlo ante errores transitorios

- **Descripción**: Cualquier excepción desactiva `SKIP LOCKED` para siempre, incluido un deadlock transitorio.
- **Hallazgos que cierra**: R-03
- **Estado**: borrador
- **Tiempo humano**: est. 2.0h · real —
- **Tiempo IA (ejec.)**: est. 0.21h · real —
- **Supervisión**: est. 0.05h (≈25 % IA) · real —
- **Previsión IA**: 50k in / 12k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-01
- **Tipo**: backend
- **Archivos**: `src/Models/QueueModel.php`, `tests/Unit/V3/Coverage/CovQueueModelTest.php`
- **Verificación**:
  - `composer test -- --filter CovQueueModelTest` → reproducción (error transitorio no desactiva la capacidad) en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] Solo un error de sintaxis o de característica no soportada desactiva `SKIP LOCKED`
- [ ] Un deadlock transitorio se reintenta sin cambiar de modo
- [ ] El estado de capacidad no persiste para siempre sin motivo

**Subtareas**

- [ ] Reproducción con excepciones simuladas
- [ ] Distinguir errores por código
- [ ] Reintento acotado

**Notas**: Oráculo: defecto → reproducción mínima. Versión: 4.0.0.

### T-28 — Rate limiter: contar jobs, no sondeos

- **Descripción**: El rate limiter se consume antes del `fetch`, así que cuenta sondeos, no jobs. Consumir tras obtener un job.
- **Hallazgos que cierra**: R-04
- **Estado**: borrador
- **Tiempo humano**: est. 2.0h · real —
- **Tiempo IA (ejec.)**: est. 0.21h · real —
- **Supervisión**: est. 0.05h (≈25 % IA) · real —
- **Previsión IA**: 50k in / 12k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-01
- **Tipo**: backend
- **Archivos**: `src/Commands/QueueWorkCommand.php`, `tests/Unit/V3/Commands/QueueV3CommandsTest.php`, `tests/Unit/V3/Coverage/CovQueueWorkCommandTest.php`
- **Verificación**:
  - `composer test -- --filter 'QueueV3CommandsTest|CovQueueWorkCommandTest'` → test existente modificado en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] Un sondeo sin job no consume cupo
- [ ] El límite se aplica por job procesado
- [ ] La documentación describe la semántica nueva

**Subtareas**

- [ ] Modificar el test existente (rojo)
- [ ] Mover el consumo tras el fetch
- [ ] Actualizar docs

**Notas**: Oráculo: cambio de comportamiento que hoy funciona → test existente modificado. Versión: 4.0.0.

### T-29 — scheduledAt: convertir a la zona horaria al guardar

- **Descripción**: `scheduledAt` se formatea sin convertir a `appTimezone` al guardar en BD.
- **Hallazgos que cierra**: R-06
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.29h · real —
- **Supervisión**: est. 0.07h (≈25 % IA) · real —
- **Previsión IA**: 70k in / 18k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-01
- **Tipo**: backend
- **Archivos**: `src/Queues/Backends/DatabaseBackend.php`, `src/Queues/EnvelopeFactory.php`, `tests/Unit/V3/Queues/DatabaseBackendTest.php`
- **Verificación**:
  - `composer test -- --filter DatabaseBackendTest` → reproducción (zona ≠ UTC) en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] La hora guardada se interpreta en la zona de la aplicación al consultar
- [ ] Un job programado no se adelanta ni retrasa con `appTimezone` ≠ UTC
- [ ] Las filas existentes se documentan (posible desfase)

**Subtareas**

- [ ] Reproducción con `appTimezone` distinta de UTC
- [ ] Convertir al guardar
- [ ] Nota para filas existentes

**Notas**: Oráculo: defecto → reproducción mínima. Riesgo: filas ya guardadas con desfase. Versión: 4.0.0.

### T-30 — Worker de larga duración: --memory, --max-time, reconexión y reaper

- **Descripción**: Añadir las opciones `--memory` y `--max-time`, reconexión de BD, invocar el reaper desde el bucle y que `jobs:queue:reap` elija el timeout según el backend real.
- **Hallazgos que cierra**: R-07
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 0.75h · real —
- **Supervisión**: est. 0.19h (≈25 % IA) · real —
- **Previsión IA**: 180k in / 45k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-09, T-28
- **Tipo**: backend
- **Archivos**: `src/Commands/QueueWorkCommand.php`, `src/Commands/QueueReapCommand.php`, `tests/Unit/V3/Commands/QueueV3CommandsTest.php`, `tests/Unit/V3/Coverage/CovQueueWorkCommandTest.php`, `docs/COMMANDS.md`, `docs/operations.md`
- **Verificación**:
  - `composer test -- --filter 'QueueV3CommandsTest|CovQueueWorkCommandTest'` → tests nuevos en rojo primero (`--memory`, `--max-time` detienen el bucle; reaper invocado; reconexión tras pérdida de BD) y luego en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] `--memory` y `--max-time` terminan el worker limpiamente
- [ ] El bucle ejecuta el reaper con periodicidad configurable
- [ ] La BD se reconecta tras una desconexión
- [ ] `jobs:queue:reap` usa el timeout del backend correcto (no solo BD frente a Redis)

**Subtareas**

- [ ] Tests nuevos en rojo por opción
- [ ] Implementar opciones y reconexión
- [ ] Corregir la selección de timeout en el reaper
- [ ] Documentar en COMMANDS.md y operations.md

**Notas**: Capacidad nueva → test nuevo en rojo (`--memory`/`--max-time`); los defectos asociados (selección de timeout en reap, reconexión) con reproducción mínima. Versión: 4.0.0.

### T-31 — Service Bus: corregir el tiempo de lock (condicionada a T-23)

- **Descripción**: Solo si T-23 confirma la hipótesis: ajustar `ServiceBusBackend` y renombrar/clarificar `serviceBusLockTimeout`. Si T-23 la refuta, se cancela con motivo.
- **Hallazgos que cierra**: R-05
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.25h · real —
- **Supervisión**: est. 0.06h (≈25 % IA) · real —
- **Previsión IA**: 60k in / 15k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-23, T-11
- **Tipo**: backend
- **Archivos**: `src/Queues/Backends/ServiceBusBackend.php`, `src/Config/Jobs.php`, `tests/Unit/V3/Queues/ServiceBusBackendTest.php`, `docs/CONFIGURATION.md`
- **Verificación**:
  - `composer test -- --filter 'ServiceBusBackendTest|CovServiceBusBackendTest'` → reproducción derivada del informe de T-23 en verde (si aplica)
  - lectura: si T-23 refutó la hipótesis, el ledger marca esta tarea `cancelado` con el motivo

**Criterios de aceptación**

- [ ] La expiración del lease coincide con la semántica real documentada por Azure
- [ ] El parámetro de configuración tiene un nombre fiel a su efecto (renombrado directo, sin alias)
- [ ] O bien la tarea está cancelada con motivo explícito

**Subtareas**

- [ ] Releer el informe de T-23
- [ ] Reproducción mínima si procede
- [ ] Implementar y renombrar directamente

**Notas**: Condicional. Oráculo: defecto → reproducción mínima (solo si se confirma). Versión: 4.0.0.

### T-32 — Reutilizar QueueModel, RateLimiter y CircuitBreaker entre ciclos

- **Descripción**: Se instancian en cada ciclo del bucle. Crearlos una vez y reutilizarlos, sin cambiar el comportamiento.
- **Hallazgos que cierra**: R-08
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.28h · real —
- **Supervisión**: est. 0.07h (≈25 % IA) · real —
- **Previsión IA**: 70k in / 15k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-30
- **Tipo**: backend
- **Archivos**: `src/Queues/Backends/DatabaseBackend.php`, `src/Commands/QueueWorkCommand.php`, `tests/Unit/V3/Commands/QueueV3CommandsTest.php`
- **Verificación**:
  - `composer test` → misma suite verde antes y después del refactor (mismo recuento de tests)
  - `composer cs` → exit 0

**Criterios de aceptación**

- [ ] Las tres dependencias se instancian una vez por proceso
- [ ] Sin cambios de comportamiento observable (suite verde antes y después)
- [ ] Estado de tests aislado (sin fugas entre casos)

**Subtareas**

- [ ] Ejecutar la suite como línea base
- [ ] Extraer instancias
- [ ] Re-ejecutar la suite

**Notas**: Oráculo: refactor → misma suite verde antes y después. Versión: 4.0.0.

### T-33 — Redis: ids en listas, payload en hash y prioridad

- **Descripción**: Redis usa el payload completo como clave de hash y `LREM` O(N). Mover a ids en listas y payload en un hash; respetar la prioridad en Redis y Beanstalk (con T-15).
- **Hallazgos que cierra**: R-09
- **Estado**: borrador
- **Tiempo humano**: est. 5.0h · real —
- **Tiempo IA (ejec.)**: est. 0.50h · real —
- **Supervisión**: est. 0.12h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 30k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-12, T-15
- **Tipo**: backend
- **Archivos**: `src/Queues/Backends/RedisBackend.php`, `src/Queues/Backends/BeanstalkBackend.php`, `tests/Unit/V3/Queues/RedisBackendTest.php`, `tests/Unit/V3/Coverage/CovRedisBackendTest.php`, `docs/QUEUES.md`
- **Verificación**:
  - `composer test -- --filter 'RedisBackendTest|CovRedisBackendTest|BeanstalkBackendTest'` → misma suite verde antes y después, más tests de prioridad nuevos en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] Las listas contienen ids y el payload vive en un hash; `LREM` por id
- [ ] La prioridad de la definición afecta al orden en Redis y Beanstalk
- [ ] Sin lector dual: la guía de migración exige drenar las colas Redis (T-42)

**Subtareas**

- [ ] Línea base de la suite
- [ ] Nuevo esquema de claves como único formato
- [ ] Pruebas de prioridad (nuevas)

**Notas**: Refactor + capacidad nueva (prioridad) → suite verde y test nuevo. ROMPE el formato de datos en Redis, sin lector dual: drenar antes de actualizar. Versión: 4.0.0.

### T-34 — Idempotencia y lock atómicos (SET NX)

- **Descripción**: Idempotencia y lock hacen get + save (carrera). Usar `SET NX` cuando la caché lo permita.
- **Hallazgos que cierra**: R-10
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.29h · real —
- **Supervisión**: est. 0.07h (≈25 % IA) · real —
- **Previsión IA**: 70k in / 18k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-03, T-25
- **Tipo**: backend
- **Archivos**: `src/Execution/IdempotencyGuard.php`, `src/Execution/SingleInstanceLock.php`, `tests/Unit/V3/Execution/IdempotencyGuardTest.php`, `tests/Unit/V3/Execution/SingleInstanceLockTest.php`
- **Verificación**:
  - `composer test -- --filter 'IdempotencyGuardTest|SingleInstanceLockTest'` → misma suite verde antes y después, más reproducción de carrera en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] Con Redis el primer intento gana atómicamente (`SET NX`)
- [ ] Con cachés sin soporte se mantiene el comportamiento actual con aviso documentado
- [ ] Sin regresiones en T-03 ni T-25

**Subtareas**

- [ ] Línea base
- [ ] Primitiva `acquire` con `SET NX` y respaldo
- [ ] Prueba de carrera simulada

**Notas**: Oráculo: refactor/robustez → misma suite verde, más test de carrera. Versión: 4.0.0.

---

## Fase 5 — Limpieza

**Estado**: borrador · **Estimado**: 15.0h · **Real**: — · **Coste est.**: 750 € (base) · **Tokens est.**: 430k

### T-35 — Investigar los 168 errores de PHPStan en PHP 8.5.12

- **Descripción**: Determinar si los 168 errores locales («return statement is missing») son un falso positivo de PHPStan/PHP 8.5.12 o un problema real, comparando con el job `analyze` del CI (matriz 8.2–8.5). Solo informe y propuesta; sin cambios de código.
- **Hallazgos que cierra**: Q-08 (hipótesis [V])
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.30h · real —
- **Supervisión**: est. 0.07h (≈25 % IA) · real —
- **Previsión IA**: 80k in / 10k out tok · tokens ⚠️ verificar precio
- **Dependencias**: ninguna
- **Tipo**: docs
- **Archivos**: `docs/roadmap/2026-11-18-audit-hardening/q08-investigacion.md (nuevo, informe)`
- **Verificación**:
  - `composer analyze` en PHP 8.5.12 → recuento de errores anotado en el informe y comparado con el log del job `analyze` del CI en 8.2–8.5
  - `git diff --stat src tests` → vacío

**Criterios de aceptación**

- [ ] Informe con causa (versión de PHPStan, extensiones, falso positivo o real) y evidencia
- [ ] Acción propuesta: actualizar herramientas, ajustar `phpstan.neon` o corregir código
- [ ] Sin cambios en `src/` ni `tests/`

**Subtareas**

- [ ] Reproducir el recuento
- [ ] Revisar el CI en 8.5
- [ ] Probar una versión nueva de PHPStan
- [ ] Registrar la conclusión

**Notas**: Tarea de investigación previa a cualquier cambio. Versión: 4.0.0.

### T-36 — Eliminar código muerto y dependencias sin uso

- **Descripción**: Eliminar directamente `ConfigCache`, `DelayResult`, `JsonPayloadSerializer` + `PayloadSerializerInterface`, `Priority`, `Utils`, `Config\Validation`, `JobEnvelope::fromBackend/fromDecoded`, `DateTimeHelper`, `QueueModel::getJob`, excepciones sin uso y `michalsn/codeigniter4-uuid` (si se confirma sin uso). `DeadLetterQueue` depende de T-10. Sin `@deprecated` intermedio.
- **Hallazgos que cierra**: Q-03
- **Estado**: borrador
- **Tiempo humano**: est. 4.5h · real —
- **Tiempo IA (ejec.)**: est. 0.41h · real —
- **Supervisión**: est. 0.10h (≈25 % IA) · real —
- **Previsión IA**: 100k in / 22k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-10, T-32
- **Tipo**: backend
- **Archivos**: `src/Libraries/ConfigCache.php`, `src/Libraries/DelayResult.php`, `src/Libraries/JsonPayloadSerializer.php`, `src/Interfaces/PayloadSerializerInterface.php`, `src/Libraries/Priority.php`, `src/Libraries/Utils.php`, `src/Config/Validation.php`, `src/Queues/JobEnvelope.php`, `src/Libraries/DateTimeHelper.php`, `src/Models/QueueModel.php`, `src/Exceptions/*.php`, `composer.json`, `tests/Unit/V3/Coverage/*.php`
- **Verificación**:
  - `composer test` → misma suite verde (tests de cobertura de clases retiradas eliminados)
  - `composer analyze` → sin referencias rotas
  - `grep -rn "ConfigCache\|DelayResult\|JsonPayloadSerializer" src tests` → sin resultados

**Criterios de aceptación**

- [ ] Cada elemento eliminado se justifica: sin referencias dinámicas (cadenas, config, reflexión, plantillas) ni uso como API pública declarada
- [ ] `michalsn/codeigniter4-uuid` solo se retira si ninguna clase la usa
- [ ] Build y suite en verde tras el borrado

**Subtareas**

- [ ] Inventario con `grep` y revisión de referencias dinámicas
- [ ] Borrar clases, tests de cobertura asociados y dependencia
- [ ] Listar lo eliminado en la guía de migración (T-42)

**Notas**: Oráculo: refactor → misma suite verde antes y después. Decisión tomada: borrado directo en 4.0, sin deprecación; rompe a terceros que usen estas clases. Versión: 4.0.0.

### T-37 — Resolver la configuración fantasma

- **Descripción**: `blockingFetch`, `blockingFetchTimeout`, `deadLetterQueue` sin efecto; `jobTimeout` solo lo lee Beanstalk; `databaseGroup`/`tableName` duplican `database[...]`. Implementar o eliminar cada clave directamente.
- **Hallazgos que cierra**: Q-04
- **Estado**: borrador
- **Tiempo humano**: est. 3.5h · real —
- **Tiempo IA (ejec.)**: est. 0.33h · real —
- **Supervisión**: est. 0.08h (≈25 % IA) · real —
- **Previsión IA**: 80k in / 18k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-10, T-36
- **Tipo**: docs
- **Archivos**: `src/Config/Jobs.php`, `src/Queues/BackendFactory.php`, `docs/CONFIGURATION.md`, `tests/Unit/V3/Queues/BackendFactoryTest.php`
- **Verificación**:
  - `composer test -- --filter 'BackendFactoryTest|CovBackendFactoryTest'` → misma suite verde
  - `grep -n "blockingFetch\|databaseGroup" src docs` → coherente con la decisión tomada

**Criterios de aceptación**

- [ ] Cada clave tiene un efecto real o se ha eliminado
- [ ] `docs/CONFIGURATION.md` describe solo claves con efecto
- [ ] Las claves eliminadas figuran en la guía de migración (T-42)

**Subtareas**

- [ ] Tabla clave → decisión (implementar/eliminar)
- [ ] Aplicar y documentar

**Notas**: Oráculo: refactor → misma suite verde. DECISIÓN por clave: implementar o eliminar; sin fase de deprecación. Versión: 4.0.0.

### T-38 — Fichero de idioma para Job.invalidWorker / Job.invalidQueue

- **Descripción**: Faltan las entradas de idioma: se muestra la clave literal.
- **Hallazgos que cierra**: Q-05
- **Estado**: borrador
- **Tiempo humano**: est. 1.0h · real —
- **Tiempo IA (ejec.)**: est. 0.10h · real —
- **Supervisión**: est. 0.03h (≈25 % IA) · real —
- **Previsión IA**: 25k in / 5k out tok · tokens ⚠️ verificar precio
- **Dependencias**: ninguna
- **Tipo**: docs
- **Archivos**: `src/Language/en/Job.php (nuevo)`, `src/Language/es/Job.php (opcional)`, `tests/Unit/V3/Coverage/CovQueueWorkCommandTest.php`
- **Verificación**:
  - `composer test -- --filter 'QueueV3CommandsTest|CovQueueWorkCommandTest'` → mensaje traducido en lugar de la clave
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] `lang('Job.invalidWorker')` y `lang('Job.invalidQueue')` devuelven texto legible
- [ ] Hay una prueba que lo asegura
- [ ] Las claves `lang('Job.*')` usadas en `src/` existen en el idioma

**Subtareas**

- [ ] Buscar todas las claves `lang('Job.*')`
- [ ] Crear el fichero
- [ ] Test mínimo

**Notas**: Oráculo: defecto → reproducción mínima (el mensaje devuelve la clave literal). Versión: 4.0.0.

### T-39 — Corregir la documentación incoherente

- **Descripción**: Alinear «Higher = sooner» con `ORDER BY priority ASC`, «Empty array allows all» con deny-by-default, las referencias a clases v1 eliminadas y la mezcla de idiomas en docblocks.
- **Hallazgos que cierra**: Q-06
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.30h · real —
- **Supervisión**: est. 0.07h (≈25 % IA) · real —
- **Previsión IA**: 70k in / 20k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-16, T-37
- **Tipo**: docs
- **Archivos**: `src/Config/Jobs.php`, `src/**/*.php (docblocks)`, `docs/CONFIGURATION.md`, `docs/security.md`, `docs/QUEUES.md`, `docs/handlers.md`
- **Verificación**:
  - `grep -rn "Higher = sooner\|allows all" src docs` → sin resultados incoherentes
  - `composer cs` → exit 0

**Criterios de aceptación**

- [ ] La documentación describe el comportamiento real tras las fases 1-4
- [ ] Los docblocks de `src/` quedan en inglés (sin mezcla con español)
- [ ] No quedan referencias a clases v1 eliminadas

**Subtareas**

- [ ] Listar incoherencias con grep
- [ ] Corregir y traducir al inglés los docblocks en español
- [ ] Validar el sitio

**Notas**: Oráculo: refactor documental → lectura + suite verde. Decisión del usuario, 2026-11-18: docblocks en inglés. Versión: 4.0.0.

---

## Fase 6 — Extensibilidad y cierre

**Estado**: borrador · **Estimado**: 17.0h · **Real**: — · **Coste est.**: 850 € (base) · **Tokens est.**: 480k

### T-40 — Inyección de dependencias en BackendFactory y HandlerRegistry

- **Descripción**: `BackendFactory` y `HandlerRegistry` hacen `new $class()`: un handler no puede recibir servicios ni un backend su configuración. Resolver mediante el contenedor/`Services` con constructor inyectable.
- **Hallazgos que cierra**: Q-01
- **Estado**: borrador
- **Tiempo humano**: est. 6.0h · real —
- **Tiempo IA (ejec.)**: est. 0.58h · real —
- **Supervisión**: est. 0.15h (≈25 % IA) · real —
- **Previsión IA**: 140k in / 35k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-32, T-36
- **Tipo**: backend
- **Archivos**: `src/Queues/BackendFactory.php`, `src/Handlers/HandlerRegistry.php`, `src/Config/Services.php`, `src/Config/Jobs.php`, `tests/Unit/V3/Queues/BackendFactoryTest.php`, `tests/Unit/V3/Handlers/HandlerRegistryTest.php`, `docs/advanced.md`
- **Verificación**:
  - `composer test -- --filter 'BackendFactoryTest|HandlerRegistryTest'` → tests nuevos en rojo primero (handler con dependencia en constructor; backend con configuración) y luego en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] Un handler puede declarar dependencias de constructor resueltas por el contenedor
- [ ] Un backend puede recibir su configuración
- [ ] Los handlers/backends sin constructor siguen funcionando

**Subtareas**

- [ ] Tests nuevos en rojo
- [ ] Fábrica con resolución por `Services`/reflexión
- [ ] Documentar en docs/advanced.md

**Notas**: Capacidad nueva → test nuevo en rojo. Aditivo y compatible. Versión: 4.0.0.

### T-41 — Eventos, middleware y métricas de duración

- **Descripción**: Añadir eventos/middleware (antes, después, fallo) alrededor de la ejecución y llamar a `MetricsCollectorInterface::observe()` con la duración.
- **Hallazgos que cierra**: Q-02
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 0.75h · real —
- **Supervisión**: est. 0.19h (≈25 % IA) · real —
- **Previsión IA**: 180k in / 45k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-40
- **Tipo**: backend
- **Archivos**: `src/Execution/JobRuntime.php`, `src/Worker/QueueWorker.php`, `src/Metrics/*.php`, `src/Config/Jobs.php`, `tests/Unit/V3/Execution/JobRuntimeTest.php`, `docs/advanced.md`, `docs/operations.md`
- **Verificación**:
  - `composer test -- --filter 'JobRuntimeTest|QueueWorkerTest'` → tests nuevos en rojo primero (eventos before/after/fail en orden; `observe()` recibe la duración) y luego en verde
  - `composer test` → suite en verde

**Criterios de aceptación**

- [ ] Existen puntos de extensión antes/después/fallo documentados
- [ ] `observe()` se invoca con la duración de cada ejecución
- [ ] Sin listeners/middleware el comportamiento es idéntico al actual

**Subtareas**

- [ ] Tests nuevos en rojo
- [ ] Pipeline mínimo de middleware y eventos de CI4
- [ ] Instrumentar métricas
- [ ] Documentar

**Notas**: Capacidad nueva → test nuevo en rojo. Aditivo. Versión: 4.0.0.

### T-42 — Cierre: guía de migración 3.x → 4.0, CHANGELOG 4.0.0 y validación final

- **Descripción**: Redactar `docs/UPGRADE.md` como guía de migración 3.x → 4.0 (drenado de colas, defaults nuevos, interfaz `QueueBackend` ampliada, API y claves eliminadas, migraciones de BD, Redis dedicado), añadir la entrada 4.0.0 del CHANGELOG con sección de cambios incompatibles y ejecutar todas las puertas.
- **Hallazgos que cierra**: todos (documentación de compatibilidad)
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.27h · real —
- **Supervisión**: est. 0.07h (≈25 % IA) · real —
- **Previsión IA**: 60k in / 20k out tok · tokens ⚠️ verificar precio
- **Dependencias**: T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-10, T-11, T-12, T-13, T-14, T-15, T-16, T-17, T-18, T-19, T-20, T-21, T-22, T-24, T-25, T-26, T-27, T-28, T-29, T-30, T-32, T-33, T-34, T-36, T-37, T-38, T-39, T-40, T-41
- **Tipo**: docs
- **Archivos**: `docs/UPGRADE.md (nuevo)`, `CHANGELOG.md`, `mkdocs.yml`, `docs/home.md`
- **Verificación**:
  - `composer test` → 100 % verde (CI incluido: MySQL 8, Redis 7, PHP 8.2-8.5)
  - `composer cs` → exit 0 · `composer analyze` → resultado coherente con el informe de T-35
  - lectura: UPGRADE.md cubre drenado de colas, defaults nuevos, `QueueBackend` ampliada, API/claves eliminadas y migraciones; CHANGELOG 4.0.0 tiene sección «Cambios incompatibles»

**Criterios de aceptación**

- [ ] UPGRADE.md es una guía paso a paso 3.x → 4.0, con el drenado de colas como primer paso
- [ ] Cada cambio incompatible aparece en UPGRADE y en el CHANGELOG 4.0.0
- [ ] Cada hallazgo de `audit.md` figura en la matriz de cobertura como cerrado o fuera de alcance
- [ ] Las puertas de calidad pasan en la rama final

**Subtareas**

- [ ] Redactar UPGRADE.md (drenado, defaults, backends propios, eliminaciones, migraciones)
- [ ] Entrada 4.0.0 del CHANGELOG
- [ ] Pasar las puertas

**Notas**: Versión: 4.0.0.

