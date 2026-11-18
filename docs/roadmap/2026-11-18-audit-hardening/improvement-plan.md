---
design: n/a
test-plan: n/a (sin UI)
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
---

# 2026-11-18-audit-hardening

> Plan de endurecimiento de `daycry/jobs` v3 derivado de la auditoría de código del 18-11-2026: 42 tareas en 6 fases para una única versión 4.0.0, sin spec ni evaluación previas.

| | |
|---|---|
| **Fecha** | 2026-11-18 |
| **Estado** | borrador |
| **Tipo** | Bugfix / Refactor / Investigación |
| **Versión objetivo** | 4.0.0 (única) |
| **Prioridad** | Alta |
| **Solicitante** | Daycry |
| **Responsable** | Daycry |
| **Origen** | [`audit.md`](audit.md) (el usuario decidió no hacer spec ni evaluación) |
| **Spec** | n/a |
| **Evaluación** | n/a |
| **Diseño** | n/a |

---

## Cuadro de mando

| Métrica | Estimado | Real | Confianza |
|--------|---------|------|-----------|
| Tiempo humano | **180,6h** (150,5h base +20 %) | 0h | Media |
| Tiempo IA (ejecución) | **18,1h** (+ 4,5h supervisión) | 0h | Baja |
| Coste total | **9030 €** (solo desarrollo; tokens ⚠️ verificar) | 0 € | Media |
| Tokens IA | **4.517k** (in 3.625k / out 892k) | 0 | Baja |
| Multiplicador productividad | **×8,0** | — | — |
| Tareas | **42** | 0 hechas | — |

La confianza es Media en las fases 1-3 (hallazgos reproducidos o con ubicación exacta) y Baja en las fases 4-6, donde hay decisiones abiertas e hipótesis sin verificar (R-05, Q-08).

---

## Estimación por fase

| Fase | Tareas | Horas base | Horas con margen | Tokens (in / out) | Coste € (con margen) |
|------|--------|-----------|------------------|-------------------|----------------------|
| Fase 1 — Críticos y red de seguridad | 7 | 20,5 | 24,6 | 560k / 141k | 1230 |
| Fase 2 — Fiabilidad de entrega | 8 | 30,5 | 36,6 | 760k / 192k | 1830 |
| Fase 3 — Seguridad | 7 | 23,5 | 28,2 | 550k / 136k | 1410 |
| Fase 4 — Robustez y operación | 12 | 44,0 | 52,8 | 1.020k / 248k | 2640 |
| Fase 5 — Limpieza | 5 | 15,0 | 18,0 | 355k / 75k | 900 |
| Fase 6 — Extensibilidad y cierre | 3 | 17,0 | 20,4 | 380k / 100k | 1020 |
| **Total** | **42** | **150,5** | **180,6** | **3.625k / 892k** | **9030 €** |

---

## Presupuesto económico

**Coste = (horas × tarifa) + coste de tokens de IA.** Importes en **EUR**. No existe `.claude/rates.json`: se aplican los defaults del plugin.

### Supuestos (ajustables)

| Parámetro | Valor | Nota |
|-----------|-------|------|
| Tarifa de desarrollo | 50 €/h | Default del plugin (sin `rates.json`) |
| Modelo IA asumido | claude-opus-4-8 | Default del plugin |
| Precio input | ⚠️ verificar | Sin precio vigente verificado; ejecutar la skill `rates-verify` |
| Precio output | ⚠️ verificar | Ídem |
| Tipo de cambio | 1 USD = 0,92 € | Solo si el proveedor factura en USD |
| Ratio tokens→hora IA | 300.000 tok/h | Default no calibrado; horas IA = tokens totales / 300k |
| Supervisión humana | 25 % de las horas IA | Default del plugin |
| Margen de contingencia | 20 % | Sobre horas base humanas e IA |

### Desglose

| Concepto | Cálculo | Importe |
|----------|---------|---------|
| Desarrollo (humano, base) | 150,5h × 50 €/h | 7525 € |
| Margen de contingencia | +20 % sobre desarrollo base | 1505 € |
| Tokens IA (input) | 3.625k tok × precio | ⚠️ verificar |
| Tokens IA (output) | 892k tok × precio | ⚠️ verificar |
| **Total estimado (con margen)** | | **9030 €** + tokens ⚠️ verificar |

El total no incluye el coste de tokens hasta verificar el precio vigente; el cálculo queda parametrizado (tokens × precio).

---

## Previsión de tokens (por fase)

Base: claude-opus-4-8. Sin precio verificado, la columna de coste queda pendiente.

| Fase | Input (tok) | Output (tok) | Total (tok) | Coste € |
|------|------------|-------------|-------------|---------|
| Fase 1 — Críticos y red de seguridad | 560k | 141k | 701k | ⚠️ verificar |
| Fase 2 — Fiabilidad de entrega | 760k | 192k | 952k | ⚠️ verificar |
| Fase 3 — Seguridad | 550k | 136k | 686k | ⚠️ verificar |
| Fase 4 — Robustez y operación | 1.020k | 248k | 1.268k | ⚠️ verificar |
| Fase 5 — Limpieza | 355k | 75k | 430k | ⚠️ verificar |
| Fase 6 — Extensibilidad y cierre | 380k | 100k | 480k | ⚠️ verificar |
| **Total** | **3.625k** | **892k** | **4.517k** | **⚠️ verificar** |

**Método de estimación:** por tarea, ficheros a leer × tamaño medio (backend ≈ 300-500 líneas) más generación de código y tests de reproducción; las tareas con backend externo (Redis/Service Bus) o diseño abierto pesan más. Horas humanas = estimación realista de un desarrollador que conoce el repositorio. No hay evaluación previa de la que heredar cifras.

---

## Productividad IA (humano vs. IA)

| KPI | Valor |
|-----|-------|
| Horas humanas estimadas | 180,6 h (150,5 h base +20 %) |
| Horas IA (ejecución) | 18,1 h (15,1 h base +20 %) — supuesto |
| Supervisión humana | 4,5 h |
| **Horas totales (IA + supervisión)** | **22,6 h** |
| Horas ahorradas | 158,0 h |
| **Ahorro** | **87,5 %** |
| **Multiplicador de productividad** | **×8,0** |
| FTE equivalentes *(opcional)* | 0,99 |

> Cifras con margen de contingencia (+20 %). Las horas IA son una estimación aproximada derivada de tokens a 300k tok/h, no calibrada; la supervisión es un 25 % de las horas IA.

---

## Resumen ejecutivo

La auditoría de `daycry/jobs` v3 encontró 5 defectos críticos (los handlers `event` y `url` no funcionan desde colas persistentes, un job con reintentos puede perderse, la firma HMAC se rompe en MySQL, etc.), 10 altos en fiabilidad de entrega, 6 de seguridad, 10 de robustez y 8 de calidad. Este plan los ordena en seis fases, todas en la versión mayor 4.0.0 (sin compatibilidad con 3.x: hay que drenar las colas antes de actualizar), empezando por un test de integración encolar → worker que sirve de red de seguridad. Cada tarea cierra uno o varios hallazgos y lleva su primer oráculo según su naturaleza (reproducción, test modificado, test nuevo o suite verde antes y después).

### Objetivos

- Que los cinco handlers funcionen de extremo a extremo desde la cola `database` (test de integración en verde).
- Eliminar la pérdida silenciosa de jobs (A-02, B-01, B-04, B-05, B-06, B-08).
- Cerrar los vectores de seguridad abiertos (S-01…S-06, B-09) con compatibilidad gestionada.
- Dejar todos los hallazgos de `audit.md` cerrados o explícitamente fuera de alcance.

---

## Datos necesarios para un informe completo

- [x] **Requisitos funcionales**: `audit.md` con hallazgos, ubicación y arreglo propuesto
- [x] **Alcance** cerrado: todo `audit.md`; sin implementar ahora
- [x] **Criterios de éxito / métricas**: ver «Métricas de éxito»
- [x] **Accesos y credenciales**: no se requieren para planificar
- [x] **Entornos**: SQLite local; CI con MySQL 8 y Redis 7, PHP 8.2-8.5
- [x] **Stakeholders**: todas las decisiones resueltas (ver «Decisiones»)
- [x] **Dependencias externas** mapeadas (Azure Service Bus para T-23, CI de GitHub para T-05/T-12/T-35)
- [x] **Restricciones**: compatibilidad de mensajes ya encolados y de firma
- [ ] **Tarifa/hora y precio de tokens** confirmados (defaults del plugin; precio de tokens ⚠️ verificar)

---

## Análisis de impacto

- **`src/Worker/QueueWorker.php`** — normalización de payload, idempotencia, ownerToken, dead-letter, replay, lease (T-02, T-03, T-08, T-10, T-11, T-17, T-18).
- **`src/Queues/EnvelopeFactory.php`, `src/Queues/Signing/EnvelopeSigner.php`** — formato de wire de 4.0: versión, canonicalización, campos nuevos, HKDF (T-04, T-05, T-06, T-22).
- **`src/Queues/Backends/*`** — decodificación, atomicidad, DLQ, conexión Redis, Beanstalk, Service Bus (T-02, T-10…T-15, T-31, T-33).
- **`src/Models/QueueModel.php`** — ownerToken, reaper, SKIP LOCKED (T-08, T-09, T-27).
- **`src/Handlers/*`** — Url, Shell, registro de handlers (T-16, T-19, T-20, T-21).
- **`src/Cron/*`, `src/Execution/*`, `src/Commands/*`** — scheduler, runtime, locks, worker de larga duración (T-07, T-24…T-30, T-34).
- **`src/Config/Jobs.php`** — nuevas claves, claves eliminadas y valores por defecto nuevos (T-04, T-14, T-16, T-17, T-37).
- **`src/Database/Migrations/`** — migraciones para DLQ, historial y columna `payload` (T-05, T-10, T-24).
- **`.github/workflows/php.yml`, `docs/`** — test MySQL en CI, documentación y UPGRADE (T-05, T-39, T-42).

---

## Cambios arquitectónicos

- **Wire/firma de 4.0 con versión explícita (`v`)**: sin lector dual. Un mensaje sin `v` (encolado con 3.x) se rechaza con error claro por el camino dead-letter y no se ejecuta; la actualización exige drenar antes las colas. Base de A-03, A-04, S-03 y S-06.
- **`QueueBackend` ampliada**: la renovación de lease entra directamente en la interfaz (T-11); los backends propios de terceros deben implementarla.
- **Dead-letter**: contrato único invocado por el worker y respaldado por cada backend (T-10).
- **Normalización de payload en un único punto** (T-02) en lugar de cuatro decodificaciones en backends.

---

## Compatibilidad, versión y mensajes ya encolados

**Versión objetivo única: 4.0.0** (decisión del usuario). Todas las tareas de código salen juntas en la misma versión mayor; no hay reparto 3.0.x / 3.1, ni pasos de deprecación, ni fase de aviso u opt-in para los defaults seguros, ni lector dual. Las dos tareas de investigación (T-23, T-35) no cambian código.

### Cambios incompatibles de 4.0.0

| Cambio | Tareas | Impacto |
|--------|--------|---------|
| Formato de wire y firma nuevos: campo `v`, canonicalización estable, `timeout`/`singleInstance`/`meta`, cola y caducidad firmadas, clave derivada con HKDF; los mensajes sin `v` se rechazan | T-04, T-05, T-06, T-18, T-22 | Todo mensaje 3.x en cola queda inválido: drenar antes |
| Valores por defecto seguros: `queueHandlers` vacío = ninguno permitido; firma obligatoria (`requireSignature=true`); `ShellHandler` por plantilla de comando y argumentos; `UrlHandler` con lista blanca de opciones, timeout y tope de respuesta | T-16, T-17, T-21, T-19 | Despliegues que dependían del comportamiento abierto |
| `QueueBackend` ampliada con la renovación de lease | T-11 | Backends propios deben implementar el método nuevo |
| Dead-letter real y migraciones de BD (estado/tabla de DLQ, historial, `payload` a `TEXT`) | T-05, T-10, T-24 | Ejecutar migraciones al desplegar |
| Redis: BD/conexión dedicada de colas por defecto y esquema de claves nuevo (ids en listas, payload en hash, prioridad) | T-12, T-14, T-33 | Colas Redis 3.x ilegibles: drenar; los pendientes en la BD de caché no se migran |
| Cambios de comportamiento: salida ≠ 0 de shell = fallo; nombres duplicados del scheduler lanzan excepción; el rate limiter cuenta jobs; `SyncBackend`/`CronRunner` propagan fallos | T-20, T-07, T-28, T-24 | Jobs que hoy «funcionan» por error pasan a fallar |
| API y configuración eliminadas sin deprecación: `ConfigCache`, `DelayResult`, `JsonPayloadSerializer`, `PayloadSerializerInterface`, `Priority`, `Utils`, `Config\Validation`, `DateTimeHelper`, `JobEnvelope::fromBackend/fromDecoded`, `QueueModel::getJob`, dependencia `michalsn/codeigniter4-uuid`, claves de config sin efecto | T-36, T-37 | Código de terceros que use estas piezas |

### Tratamiento de mensajes ya encolados

1. **Drenar todas las colas con workers 3.x antes de desplegar 4.0.** No hay compatibilidad con mensajes 3.x.
2. Un mensaje 3.x que llegue igualmente al worker 4.0 no se ejecuta: se rechaza con un error claro y sigue el camino dead-letter (T-04).
3. Con firma activa en MySQL, los mensajes que el motor ya reordenó no son recuperables de todos modos (se perdió el orden original): otro motivo para drenar.
4. Redis: drenar antes; el esquema de claves nuevo y la BD dedicada no se leen con lector dual.
5. Antes de las migraciones de BD: copia de la tabla de colas.
6. Orden recomendado: parar productores, drenar con workers 3.x, desplegar 4.0 y migrar, configurar clave de firma y `queueHandlers`, arrancar workers y productores.

---

## Orden de ejecución y oráculos

Cada tarea declara su primer oráculo según la clasificación acordada:

| Clase | Oráculo | Tareas |
|-------|---------|--------|
| Defecto | Reproducción mínima del fallo | A-01…A-05, B-01…B-10 (salvo capacidades nuevas), R-01…R-06, S-02…S-04 |
| Cambio de comportamiento que hoy funciona | Test existente modificado | S-01 (T-16), R-04 (T-28) |
| Capacidad nueva | Test nuevo en rojo | B-01 (T-10), B-03 (T-11), Q-01 (T-40), Q-02 (T-41), `--memory`/`--max-time` (T-30) |
| Refactor | Misma suite verde antes y después | Q-03…Q-06 (T-36…T-39), R-08 (T-32), R-09 (T-33) |
| Investigación previa | Informe con evidencia, sin tocar código | R-05 (T-23), Q-08 (T-35) |

La fase 1 empieza por T-01 (integración encolar → `QueueWorker::processOnce()` por handler sobre `database`), hoy en rojo para event y url.

---

## Matriz de cobertura de `audit.md`

| Hallazgo | Tarea(s) |
|----------|----------|
| A-01 | T-02 |
| A-02 | T-03 |
| A-03 | T-05 (con T-04) |
| A-04 | T-06 (con T-04) |
| A-05 | T-07 |
| B-01 | T-10 |
| B-02 | T-08 |
| B-03 | T-11 |
| B-04 | T-09 |
| B-05 | T-12 |
| B-06 | T-13 |
| B-07 | T-14 |
| B-08 | T-15 |
| B-09 | T-20 |
| B-10 | T-24 |
| S-01 | T-16 |
| S-02 | T-17 |
| S-03 | T-18 |
| S-04 | T-19 |
| S-05 | T-21 |
| S-06 | T-22 |
| R-01 | T-25 (atomicidad en T-34) |
| R-02 | T-26 |
| R-03 | T-27 |
| R-04 | T-28 |
| R-05 | T-23 (investigación) → T-31 (condicional) |
| R-06 | T-29 |
| R-07 | T-30 |
| R-08 | T-32 (parcial en T-30) |
| R-09 | T-33 |
| R-10 | T-34 |
| Q-01 | T-40 |
| Q-02 | T-41 |
| Q-03 | T-36 (DeadLetterQueue en T-10) |
| Q-04 | T-37 (deadLetterQueue en T-10) |
| Q-05 | T-38 |
| Q-06 | T-39 |
| Q-07 | T-01, T-05 (parcial; ver fuera de alcance) |
| Q-08 | T-35 (investigación) |

### Fuera de alcance

| Elemento | Motivo |
|----------|--------|
| Tests de integración de Q-07 contra Redis, Beanstalk y Service Bus reales por handler | El CI solo dispone de MySQL 8 y Redis 7; Beanstalk y Service Bus se prueban con dobles. Redis real se cubre en T-12. Ampliarlo exige infraestructura nueva. |
| Reescritura de `docs/superpowers/` | Planes antiguos de otro formato; se dejan intactos por decisión del usuario. |
| Spec y evaluación previas | Decisión expresa del usuario. |

Los 39 hallazgos tienen al menos una tarea; Q-07 queda cubierto parcialmente por T-01 y T-05 más lo anterior.

---

## Archivos a crear/modificar

| Archivo | Acción | Propósito |
|---------|--------|-----------|
| `tests/Unit/V3/Integration/EnqueueToWorkerTest.php` | Crear | Red de seguridad por handler (T-01) |
| `tests/Unit/V3/Integration/MysqlSignatureTest.php` | Crear | Firma contra MySQL 8 (T-05) |
| `src/Language/en/Job.php` | Crear | Cadenas de idioma faltantes (T-38) |
| `docs/UPGRADE.md`, `CHANGELOG.md` | Crear/Modificar | Guía de migración 3.x → 4.0 y entrada 4.0.0 (T-42) |
| `docs/roadmap/2026-11-18-audit-hardening/r05-investigacion.md`, `q08-investigacion.md` | Crear | Informes de investigación (T-23, T-35) |
| `src/Worker/QueueWorker.php`, `src/Queues/**`, `src/Models/QueueModel.php` | Modificar | Fases 1-2 |
| `src/Handlers/*`, `src/Config/Jobs.php` | Modificar | Fase 3 y configuración |
| `src/Cron/*`, `src/Execution/*`, `src/Commands/*` | Modificar | Fase 4 |
| `src/Libraries/*`, `src/Interfaces/*`, `composer.json` | Modificar/Eliminar | Limpieza (T-36) |
| `src/Database/Migrations/*` | Crear | DLQ, historial y `payload` a `TEXT` (T-05, T-10, T-24) |
| `.github/workflows/php.yml` | Modificar | Job MySQL para la firma (T-05) |

No se modifica `docs/superpowers/**`.

---

## Dependencias y prerequisitos

- T-01 es prerrequisito de casi todo el resto como red de seguridad.
- T-04 (campo de versión del wire) bloquea T-05, T-06, T-17, T-18 y T-22.
- T-12 antes de T-33 (evita doble migración de claves Redis); T-09 antes de T-10; T-23 antes de T-31.
- T-10 y T-36/T-37 se acoplan por `DeadLetterQueue` y `deadLetterQueue`.
- El CI con MySQL 8 y Redis 7 es necesario para T-05 y T-12.

---

## Criterios de aceptación (global)

- [ ] `composer test` en verde (incluido el test de integración por handler y, en CI, MySQL/Redis)
- [ ] `composer cs` y `composer analyze` pasan (según el informe de T-35)
- [ ] Todo hallazgo de `audit.md` figura como cerrado en la matriz o como fuera de alcance justificado
- [ ] `docs/UPGRADE.md` es la guía 3.x → 4.0 y el CHANGELOG 4.0.0 lista los cambios incompatibles

---

## Decisiones

Las decisiones del 2026-11-18 son del usuario (recomendaciones del planner aceptadas). Quedan abiertas solo dos decisiones menores, internas de tarea, que no estaban en la lista original.

| Decisión | Tareas | Resolución |
|----------|--------|------------|
| Reparto de versiones | todas | Una única versión mayor 4.0.0 |
| Lease | T-11 | Se amplía `QueueBackend` directamente |
| Redis | T-14, T-33 | BD dedicada y esquema nuevo por defecto, sin lector dual; drenar |
| Defaults seguros | T-16, T-17, T-21 | Defecto nuevo directamente, sin aviso ni opt-in |
| Borrado de API muerta y claves fantasma | T-36, T-37 | Borrado directo, sin `@deprecated` |
| Compatibilidad de wire | T-04 | Sin lector dual; mensajes sin `v` rechazados; drenado obligatorio |
| Normalización del payload | T-02 | En el worker, un único punto (decisión del usuario, 2026-11-18) |
| Idempotencia | T-03 | Dos fases con TTL (decisión del usuario, 2026-11-18) |
| Firma en MySQL | T-05 | `ksort` recursivo y columna `payload` a `TEXT`, ambas; +0,5 h ya contadas (decisión del usuario, 2026-11-18) |
| Duplicados en el scheduler | T-07 | Excepción (decisión del usuario, 2026-11-18) |
| Dead-letter | T-10 | Estado en la misma tabla/lista; `bury` o dead-letter nativo según backend (decisión del usuario, 2026-11-18) |
| Timeout sin `pcntl` | T-26 | Éxito con aviso (decisión del usuario, 2026-11-18) |
| Idioma de docblocks | T-39 | Inglés (decisión del usuario, 2026-11-18) |
| Protección anti-replay | T-18 | Caducidad por `createdAt` firmado (decisión del usuario, 2026-11-18) |
| Historial de ejecuciones | T-24 | Escribir la tabla `jobs` (decisión del usuario, 2026-11-18) |

No quedan decisiones abiertas.


---

## Riesgos y mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|-------------|---------|------------|
| Romper mensajes ya encolados al cambiar wire/firma | Media | Alto | Drenado obligatorio de colas antes de 4.0; T-04 rechaza mensajes sin `v` con error claro |
| Mensajes MySQL ya reordenados con firma activa irrecuperables | Alta (si existen) | Medio | Drenar antes de actualizar (obligatorio); documentado en UPGRADE |
| Cambios de defaults seguros rompen despliegues | Media | Alto | Guía de migración 3.x → 4.0 (T-42) con la configuración mínima segura |
| Renovación de lease limitada en PHP sin hilos | Media | Medio | Documentar límites, pruebas con reloj simulado |
| Tests de Redis/Service Bus/Beanstalk con dobles ocultan fallos reales | Media | Medio | Redis real en CI; Service Bus validado en T-23 |
| Hipótesis R-05/Q-08 resultan falsas | Media | Bajo | Tareas de investigación previas; T-31 condicional y cancelable |
| Estimación IA no calibrada | Alta | Bajo | Margen +20 %; ejecutar `/retro` tras las primeras fases |

---

## Métricas de éxito

- 0 handlers fallando en `EnqueueToWorkerTest` y test MySQL de firma en verde en CI.
- Reproducciones de A-01…A-05 y B-01…B-10 convertidas en tests permanentes.
- Suite con más de 360 tests y 0 fallos en PHP 8.2-8.5.
- Ningún job perdido en los escenarios de reintento, caída de worker y caída de conexión cubiertos por tests.

---

## Changelog del plan

| Fecha | Cambio | Autor |
|-------|--------|-------|
| 2026-11-18 | Creación del plan desde `audit.md` (sin spec ni evaluación) | planner |

---

## Siguiente paso

No quedan decisiones abiertas (ver «Decisiones»). El plan queda en `borrador`: no se implementa por ahora. Cuando se apruebe, `implementer` lo ejecutará fase a fase marcando `tasks.md`. Con `test-plan: n/a (sin UI)`, `qa` corre solo sus puertas deterministas.
