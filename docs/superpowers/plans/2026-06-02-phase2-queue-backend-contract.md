# Fase 2 — Contrato único QueueBackend + backends (Implementation Plan)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Colapsar `QueueInterface`+`WorkerInterface` en un único contrato canónico `Daycry\Jobs\Queues\QueueBackend` con semántica de *lease*, y refactorizar los 5 backends reutilizando su lógica probada, aplicando las correcciones de concurrencia (reaper DB, renovación de lease Redis, nack con delay, token de propiedad).

**Architecture:** Contrato en `Daycry\Jobs\Queues\` (no `V2\`). `JobLease` canónico con token de propiedad. Backends en `Daycry\Jobs\Queues\Backends\`. Los `Queues\*Queue` (V1) y `V2\Queues\*` quedan para retirarse en la fase de fachada. SyncBackend se cierra junto al runtime (Fase 3) por ejecutar inline.

**Referencia spec:** §6. Hallazgos: #2 (reaper DB), #3 (lease Redis + token), #12 (UPDATE in-place DB), #13 (nack delay), #21 (índice), #22 (batchSize retirado).

---

## File Structure

- Create `src/Queues/QueueBackend.php` — contrato canónico (enqueue/fetch/ack/nack/abandon/reapExpired).
- Create `src/Queues/JobLease.php` — lease con `ownerToken` + `expiresAt` + helpers.
- Create `src/Queues/EnvelopeFactory.php` — `JobDefinition` → `JobEnvelope` wire mapping (compartido).
- Create `src/Queues/Backends/DatabaseBackend.php` — reusa QueueModel; añade reaper + UPDATE in-place + nack delay.
- Create `src/Queues/Backends/RedisBackend.php` — reusa lógica Redis; renovación de lease + token; nack delay (ZSET).
- Create `src/Queues/Backends/BeanstalkBackend.php` — release-with-delay nativo.
- Create `src/Queues/Backends/ServiceBusBackend.php` — peek-lock; abandon→DLQ nativa.
- Modify `src/Models/QueueModel.php` — `reapStuck()`, `requeueInPlace()`.
- Create migration `*_QueueLeaseColumns.php` — `reserved_at`, `available_at`, `owner_token`; índice `(queue,status,priority,schedule)`.
- Modify `src/Config/Jobs.php` — `$databaseVisibilityTimeout`; retirar `$batchSize`.
- Tests: `tests/Unit/V3/Queues/*`.

---

### Task 1: JobLease canónico (con token de propiedad)

**Files:**
- Create: `src/Queues/JobLease.php`
- Test: `tests/Unit/V3/Queues/JobLeaseTest.php`

`JobLease` envuelve el `JobEnvelope` en vuelo. Campos: `envelope`, `token` (id backend para ack/nack), `ownerToken` (token aleatorio del worker; el reaper solo reclama leases sin dueño vivo / expirados), `expiresAt`, `backend`. Métodos: `withRelativeExpiry()`, `isExpired()`, `renew()` (devuelve nuevo lease con expiry extendido).

- [ ] **Step 1: Test** (infra-free):

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Queues;

use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\Queues\JobLease;

/**
 * @internal
 */
final class JobLeaseTest extends CIUnitTestCase
{
    private function envelope(): JobEnvelope
    {
        return new JobEnvelope(id: 'id-1', queue: 'default', payload: (object) ['job' => 'command']);
    }

    public function testWithRelativeExpiryCarriesOwnerToken(): void
    {
        $lease = JobLease::withRelativeExpiry($this->envelope(), 'tok', 'owner-abc', 300, 'database');
        $this->assertSame('tok', $lease->token);
        $this->assertSame('owner-abc', $lease->ownerToken);
        $this->assertSame('database', $lease->backend);
        $this->assertFalse($lease->isExpired());
    }

    public function testIsExpiredInPast(): void
    {
        $past  = new DateTimeImmutable('2000-01-01 00:00:00');
        $lease = new JobLease($this->envelope(), 'tok', 'owner', $past, 'redis');
        $this->assertTrue($lease->isExpired());
    }

    public function testRenewExtendsExpiry(): void
    {
        $lease   = JobLease::withRelativeExpiry($this->envelope(), 'tok', 'owner', 1, 'redis');
        $renewed = $lease->renew(300);
        $this->assertGreaterThan($lease->expiresAt, $renewed->expiresAt);
        $this->assertSame($lease->token, $renewed->token);
        $this->assertSame($lease->ownerToken, $renewed->ownerToken);
    }
}
```

- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** `src/Queues/JobLease.php` (see code in this turn's implementation).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(v3): canonical JobLease with ownership token`.

---

### Task 2: QueueBackend contrato canónico

**Files:**
- Create: `src/Queues/QueueBackend.php`

Interface: `enqueue(JobDefinition): string`, `fetch(string $queue): ?JobLease`, `ack(JobLease): bool`, `nack(JobLease, ?int $delaySeconds = null): bool`, `abandon(JobLease): bool`, `reapExpired(string $queue, int $visibilityTimeout): int`.

- [ ] Implement interface (no test for a pure interface; exercised by backends). Commit `feat(v3): canonical QueueBackend contract with reapExpired`.

---

### Task 3: EnvelopeFactory (JobDefinition → JobEnvelope)

**Files:**
- Create: `src/Queues/EnvelopeFactory.php`
- Test: `tests/Unit/V3/Queues/EnvelopeFactoryTest.php`

Builds the canonical wire object `{job, payload, queue, priority, maxRetries, attempts, name, schedule, idempotencyKey}` from a `JobDefinition`, and wraps it as a `JobEnvelope`. Backends serialize `envelope->payload` to JSON.

- [ ] TDD: definition → envelope carries handler key in `job`, payload, queue, attempts=0. Commit.

---

### Task 4: Migration — lease columns + composite index

**Files:**
- Create: `src/Database/Migrations/2026-06-02-000001_QueueLeaseColumns.php`

Adds `reserved_at DATETIME NULL`, `available_at DATETIME NULL`, `owner_token VARCHAR(64) NULL` to the queue table; adds composite index `(queue, status, priority, schedule)`; (down reverses).

- [ ] Implement migration. Commit.

---

### Task 5: QueueModel — reapStuck + requeueInPlace

**Files:**
- Modify: `src/Models/QueueModel.php`
- Test: `tests/Unit/V3/Queues/DatabaseBackendTest.php` (uses CIUnitTestCase + DatabaseTestCase migrations)

- `reapStuck(string $queue, int $visibilityTimeout): int` — `UPDATE ... SET status='pending', owner_token=NULL WHERE status='in_progress' AND reserved_at < (now - VT)`.
- `requeueInPlace(int $id, int $delaySeconds): bool` — `UPDATE ... SET status='pending', attempts=attempts+1, available_at=now+delay, reserved_at=NULL, owner_token=NULL WHERE id=?`.
- `reserveJob()` also stamps `reserved_at=now`, `owner_token=?`.

- [ ] TDD against test DB. Commit.

---

### Task 6: DatabaseBackend

**Files:**
- Create: `src/Queues/Backends/DatabaseBackend.php`
- Test: `tests/Unit/V3/Queues/DatabaseBackendTest.php`

Implements `QueueBackend`: enqueue (insert from definition), fetch (reserveJob → JobLease with ownerToken + visibilityTimeout expiry), ack (status=completed), nack(delay) (requeueInPlace → **no orphan row**), abandon (status=completed/DLQ), reapExpired (reapStuck). fetch respects `available_at <= now`.

- [ ] TDD: enqueue→fetch→ack cycle; nack requeues same row id with attempts+1; reapExpired recovers a stale in_progress row but leaves a fresh one. Commit.

---

### Task 7: RedisBackend

**Files:**
- Create: `src/Queues/Backends/RedisBackend.php`
- Test: `tests/Unit/V3/Queues/RedisBackendTest.php` (markTestSkipped if no ext-redis/connection)

Reuses the reliable-queue model (waiting/delayed/processing/processing-meta). fetch writes `ownerToken` + start ts to processing-meta; `renewLease()` re-stamps ts; `reapExpired()` only requeues entries whose ts is stale (lease expired). nack(delay) → ZSET delayed at now+delay. Lua promotion preserved.

- [ ] TDD (guarded by redis availability). Commit.

---

### Task 8: BeanstalkBackend + ServiceBusBackend

**Files:**
- Create: `src/Queues/Backends/BeanstalkBackend.php`, `src/Queues/Backends/ServiceBusBackend.php`
- Test: guarded tests.

Beanstalk: nack(delay) → `release($id, priority, delay)`; abandon → `bury`. ServiceBus: fetch peek-lock; ack=complete; nack=abandon(message)→redelivered; abandon=deadletter; lease token = LockToken.

- [ ] TDD (guarded). Commit.

---

### Task 9: Config + full suite + static analysis

- Modify `src/Config/Jobs.php`: add `public int $databaseVisibilityTimeout = 300;`; remove `$batchSize` (and its HealthCheck usage will be handled in Phase 5).
- [ ] `vendor/bin/phpunit` green; `vendor/bin/phpstan analyse src/Queues` clean; cs-fixer clean. Commit.

---

## Self-Review

- Spec §6 coverage: contract ✔(T2), lease+token ✔(T1), envelope mapping ✔(T3), DB reaper+in-place ✔(T4,T5,T6), Redis lease renewal ✔(T7), nack delay ✔(T6,T7,T8), index ✔(T4), batchSize retired ✔(T9). SyncBackend deferred to Phase 3 (executes via runtime).
- Placeholder scan: backend bodies reference the existing V1 logic to copy; the implementing turn must paste concrete code (no TODOs land in src/).
- Type consistency: `JobLease(envelope, token, ownerToken, expiresAt, backend)`, `withRelativeExpiry(envelope, token, ownerToken, secondsFromNow, backend)`, `QueueBackend::{enqueue,fetch,ack,nack,abandon,reapExpired}` used consistently across tasks.
