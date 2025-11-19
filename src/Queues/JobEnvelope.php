<?php

declare(strict_types=1);

/**
 * This file is part of Daycry Queues.
 *
 * (c) Daycry <daycry9@proton.me>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Daycry\Jobs\Queues;

use DateTimeInterface;
use Daycry\Jobs\Job;
use Daycry\Jobs\Libraries\DateTimeHelper;

/**
 * Normalized queue message envelope independiente del backend (Redis, Database, Beanstalk, ServiceBus, etc.).
 * Diseñado para unificar el ciclo de consumo y métricas.
 *
 * Campos:
 *  - id: Identificador nativo del backend
 *  - queue: Nombre de la cola/tubo
 *  - payload: Carga decodificada original (stdClass|array)
 *  - name: Nombre lógico del job (si se suministró al crear)
 *  - attempts: Intentos actuales (real o sintético)
 *  - priority: Prioridad normalizada si la fuente la soporta
 *  - scheduledAt / availableAt: Cuándo fue planificado / cuándo estará disponible
 *  - createdAt: Marca temporal de creación (útil para latencias)
 *  - meta: Metadatos arbitrarios extra (estado, headers, delays)
 *  - raw: Registro/objeto nativo para operaciones de bajo nivel
 */
final class JobEnvelope
{
    public function __construct(
        public readonly string $id,
        public readonly string $queue,
        public readonly array|object|null $payload,
        public readonly ?string $name = null,
        public readonly int $attempts = 0,
        public readonly ?int $priority = null,
        public readonly ?DateTimeInterface $scheduledAt = null,
        public readonly ?DateTimeInterface $availableAt = null,
        public readonly ?DateTimeInterface $createdAt = null,
        public readonly array $meta = [],
        public readonly mixed $raw = null,
    ) {
    }

    /**
     * Create from a decoded backend payload plus raw reference.
     */
    public static function fromDecoded(
        string $id,
        string $queue,
        array|object|null $decoded,
        mixed $name = null,
        int $attempts = 0,
        ?int $priority = null,
        ?DateTimeInterface $scheduledAt = null,
        ?DateTimeInterface $availableAt = null,
        ?DateTimeInterface $createdAt = null,
        array $meta = [],
        mixed $raw = null,
    ): self {
        // Backwards compatibility: legacy signature (id, queue, decoded, attempts, priority, ...)
        if (! is_string($name)) {
            if (is_int($name) && $attempts === 0) {
                $attempts = $name; // shift
            }
            $name = null; // no provided name in legacy form
        }

        return new self($id, $queue, $decoded, $name, $attempts, $priority, $scheduledAt, $availableAt, $createdAt, $meta, $raw);
    }

    /**
     * Create directly from a Job domain object (minimal meta)
     */
    public static function fromJob(Job $job, array $meta = []): self
    {
        $payload = $job->toObject();

        return new self(
            id: $payload->identifier ?? $job->getName() . '-' . bin2hex(random_bytes(4)),
            queue: $payload->queue ?? 'default',
            payload: $payload,
            name: $payload->name ?? $job->getName(),
            attempts: $job->getAttempt(),
            priority: $payload->priority ?? null,
            scheduledAt: $payload->schedule ?? null,
            availableAt: null,
            createdAt: $payload->createdAt ?? ($payload->schedule ?? null),
            meta: $meta,
            raw: $job,
        );
    }

    /**
     * Unified factory for creating envelopes from backend-specific messages with normalized metadata.
     *
     * Standardized meta keys injected automatically:
     *  - backend: nombre del backend (redis, database, beanstalk, servicebus, sync)
     *  - rawId: ID nativo original del mensaje (útil para trazabilidad)
     *  - status: estado backend si disponible (pending, in_progress, completed, failed, etc.)
     *
     * @param string       $backend   Backend identifier (redis|database|beanstalk|servicebus|sync)
     * @param string       $id        Job identifier (unique within backend)
     * @param string       $queue     Queue/tube name
     * @param array|object $payload   Decoded payload object
     * @param array        $extraMeta Additional backend-specific metadata (delay, ttr, entity_id, headers, etc.)
     * @param mixed        $raw       Original backend object/record (for low-level operations)
     */
    public static function fromBackend(
        string $backend,
        string $id,
        string $queue,
        array|object|null $payload,
        array $extraMeta = [],
        mixed $raw = null,
    ): self {
        // Extract common fields from payload (puede ser stdClass o array)
        $data = is_array($payload) ? (object) $payload : $payload;

        $name        = isset($data->name) ? (string) $data->name : null;
        $attempts    = isset($data->attempts) ? (int) $data->attempts : 0;
        $priority    = isset($data->priority) ? (int) $data->priority : null;
        $scheduledAt = DateTimeHelper::parseImmutable($data->schedule ?? null);
        $availableAt = isset($data->availableAt) ? DateTimeHelper::parseImmutable($data->availableAt) : null;
        $createdAt   = DateTimeHelper::parseImmutable($data->createdAt ?? null)
            ?? $scheduledAt
            ?? DateTimeHelper::now();

        // Merge standard meta + extra
        $meta = array_merge(
            [
                'backend' => $backend,
                'rawId'   => $id,
            ],
            $extraMeta,
        );

        return new self(
            id: $id,
            queue: $queue,
            payload: $payload,
            name: $name,
            attempts: $attempts,
            priority: $priority,
            scheduledAt: $scheduledAt,
            availableAt: $availableAt,
            createdAt: $createdAt,
            meta: $meta,
            raw: $raw,
        );
    }
}
