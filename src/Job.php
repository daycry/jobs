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

namespace Daycry\Jobs;

use DateTime;
use DateTimeZone;
use Daycry\Jobs\Traits\ActivityTrait;
use Daycry\Jobs\Traits\CallbackTrait;
use Daycry\Jobs\Traits\DependableTrait;
use Daycry\Jobs\Traits\EnqueuableTrait;
use Daycry\Jobs\Traits\EnvironmentTrait;
use Daycry\Jobs\Traits\FrequenciesTrait;
use Daycry\Jobs\Traits\NameableTrait;
use Daycry\Jobs\Traits\NotificableTrait;
use Daycry\Jobs\Traits\StatusTrait;
use Throwable;

/**
 * Core Job definition aggregating scheduling traits, queue metadata, naming,
 * environment constraints, retry limits, notification flags and more.
 *
 * This class is a mutable builder used prior to execution. Execution runtime
 * state/results are captured separately by immutable DTOs (ExecutionResult, LifecycleOutcome).
 *
 * Properties (selected):
 *  - job: handler key referencing an entry in config('Jobs')->jobs
 *  - payload: mixed user data passed to the handler
 *  - singleInstance: whether only one concurrent execution is allowed
 *  - schedule: optional DateTime for queue delayed execution
 *  - attempts: internal retry attempt counter for queue contexts
 */
class Job
{
    use EnvironmentTrait;
    use FrequenciesTrait;
    use NameableTrait;
    use DependableTrait;
    use ActivityTrait;
    use StatusTrait;
    use NotificableTrait;
    use EnqueuableTrait;
    use CallbackTrait;

    protected string $job;
    protected mixed $payload;
    protected bool $singleInstance = false;
    /**
     * Origin of the job: 'cron' when created via Scheduler, 'queue' when pushed directly,
     * or custom (e.g. 'api') if set by integrator. Propagated into logs for observability.
     */
    protected ?string $source = null;

    public function __construct(...$params)
    {
        $this->job     = $params['job'] ?? '';
        $this->payload = $params['payload'] ?? '';
    }

    public function getJob(): string
    {
        return $this->job;
    }

    public function getPayload(): mixed
    {
        return $this->payload;
    }

    public function singleInstance(bool $singleInstance = true): self
    {
        $this->singleInstance = $singleInstance;

        return $this;
    }

    public function isSingleInstance(): bool
    {
        return $this->singleInstance;
    }

    public function toObject(): object
    {
        $data = get_object_vars($this);

        unset($data['types'], $data['worker']);

        $data = json_decode(json_encode($data));

        if (isset($data->schedule->date)) {
            $data->schedule = new DateTime($data->schedule->date, new DateTimeZone($data->schedule->timezone));
        } else {
            $data->schedule = null;
        }

        return $data;
    }

    /**
     * Define origin source for logging/analytics.
     */
    public function source(string $source): self
    {
        $this->source = $source;
        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    /**
     * Rebuild a Job instance from a queue record object.
     * The $record must at minimum provide 'job' and 'payload'. Optional fields restored:
     *  name, queue, priority, schedule (DateTime or serialized), attempts.
     */
    public static function fromQueueRecord(object $record): self
    {
        $instance = new self(job: $record->job ?? '', payload: $record->payload ?? null);

        // Restaurar propiedades conocidas si existen
        if (isset($record->name)) {
            $instance->named($record->name);
        }
        if (isset($record->queue)) {
            $instance->setQueue($record->queue);
        }
        if (isset($record->priority)) {
            try {
                $instance->priority((int) $record->priority);
            } catch (Throwable) { // ignore invalid
            }
        }
        if (isset($record->schedule) && $record->schedule) {
            try {
                $dt = new DateTime($record->schedule->date ?? $record->schedule, new DateTimeZone($record->schedule->timezone ?? date_default_timezone_get()));
                $instance->scheduled($dt);
            } catch (Throwable) {
                // ignorar si no se puede parsear
            }
        }
        // Attempts (si estuviera presente en versiones futuras)
        if (isset($record->attempts) && is_numeric($record->attempts)) {
            // Forzar el contador interno sumando tantas veces como sea necesario
            for ($i = 0; $i < (int) $record->attempts; $i++) {
                $instance->addAttempt();
            }
        }

        return $instance;
    }
}
