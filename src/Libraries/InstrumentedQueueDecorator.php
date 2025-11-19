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

namespace Daycry\Jobs\Libraries;

use Daycry\Jobs\Interfaces\QueueInterface;
use Daycry\Jobs\Interfaces\WorkerInterface;
use Daycry\Jobs\Job;
use Daycry\Jobs\Metrics\MetricsCollectorInterface;
use InvalidArgumentException;
use Throwable;

/**
 * Decorator transparente que instrumenta cualquier QueueInterface con métricas.
 *
 * Métricas capturadas:
 *  - queue_enqueue_total: Contador de jobs enqueued
 *  - queue_fetch_total: Contador de watch() exitosos
 *  - queue_fetch_empty_total: Contador de watch() sin jobs
 *  - queue_ack_total: Contador de removeJob success
 *  - queue_nack_total: Contador de removeJob con recreate
 *  - queue_enqueue_duration_seconds: Histograma de latencia de enqueue
 *  - queue_fetch_duration_seconds: Histograma de latencia de watch
 *
 * Uso:
 *   $redis = new RedisQueue();
 *   $instrumented = new InstrumentedQueueDecorator($redis, $metricsCollector, 'redis');
 *   $id = $instrumented->enqueue($data); // métricas capturadas automáticamente
 */
class InstrumentedQueueDecorator implements QueueInterface, WorkerInterface
{
    private QueueInterface $decorated;
    private MetricsCollectorInterface $metrics;
    private string $backendName;

    public function __construct(
        QueueInterface $decorated,
        MetricsCollectorInterface $metrics,
        string $backendName,
    ) {
        // Validar que también implementa WorkerInterface
        if (! $decorated instanceof WorkerInterface) {
            throw new InvalidArgumentException('Decorated queue must implement WorkerInterface');
        }
        $this->decorated   = $decorated;
        $this->metrics     = $metrics;
        $this->backendName = $backendName;
    }

    public function enqueue(object $data): string
    {
        $start = microtime(true);
        $queue = $data->queue ?? 'default';

        try {
            $id = $this->decorated->enqueue($data);
            $this->metrics->increment('queue_enqueue_total', 1, [
                'backend' => $this->backendName,
                'queue'   => $queue,
                'status'  => 'success',
            ]);

            return $id;
        } catch (Throwable $e) {
            $this->metrics->increment('queue_enqueue_total', 1, [
                'backend' => $this->backendName,
                'queue'   => $queue,
                'status'  => 'error',
            ]);

            throw $e;
        } finally {
            $duration = microtime(true) - $start;
            $this->metrics->observe('queue_enqueue_duration_seconds', $duration, [
                'backend' => $this->backendName,
                'queue'   => $queue,
            ]);
        }
    }

    public function watch(string $queue)
    {
        $start = microtime(true);

        try {
            /** @var WorkerInterface $worker */
            $worker   = $this->decorated;
            $envelope = $worker->watch($queue);

            if ($envelope !== null) {
                $this->metrics->increment('queue_fetch_total', 1, [
                    'backend' => $this->backendName,
                    'queue'   => $queue,
                ]);
            } else {
                $this->metrics->increment('queue_fetch_empty_total', 1, [
                    'backend' => $this->backendName,
                    'queue'   => $queue,
                ]);
            }

            return $envelope;
        } finally {
            $duration = microtime(true) - $start;
            $this->metrics->observe('queue_fetch_duration_seconds', $duration, [
                'backend' => $this->backendName,
                'queue'   => $queue,
            ]);
        }
    }

    public function removeJob(Job $job, bool $recreate = false): bool
    {
        /** @var WorkerInterface $worker */
        $worker = $this->decorated;
        $result = $worker->removeJob($job, $recreate);

        $metricName = $recreate ? 'queue_nack_total' : 'queue_ack_total';
        $this->metrics->increment($metricName, 1, [
            'backend' => $this->backendName,
            'queue'   => $job->getQueue() ?? 'unknown',
        ]);

        return $result;
    }

    /**
     * Acceso al backend decorado (útil para operaciones especializadas).
     */
    public function getDecorated(): QueueInterface
    {
        return $this->decorated;
    }
}
