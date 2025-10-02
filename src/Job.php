<?php

declare(strict_types=1);

namespace Daycry\Jobs;

use Daycry\Jobs\Traits\ActivityTrait;
use Daycry\Jobs\Traits\CallbackTrait;
use Daycry\Jobs\Traits\DependableTrait;
use Daycry\Jobs\Traits\EnqueuableTrait;
use Daycry\Jobs\Traits\EnvironmentTrait;
use Daycry\Jobs\Traits\FrequenciesTrait;
use Daycry\Jobs\Traits\NameableTrait;
use Daycry\Jobs\Traits\NotificableTrait;
use Daycry\Jobs\Traits\StatusTrait;
use Daycry\Jobs\Traits\LogTrait;
use DateTime;
use DateTimeZone;

class Job
{
    use EnvironmentTrait;
    use FrequenciesTrait;
    use NameableTrait;
    use DependableTrait;
    use ActivityTrait;
    use StatusTrait;
    use LogTrait;
    use NotificableTrait;
    use EnqueuableTrait;
    use CallbackTrait;

    protected string $job;
    protected mixed $payload;
    protected bool $singleInstance = false;
    public function __construct(...$params)
    {
        $this->job = $params['job'] ?? '';
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
     * Reconstruye un Job a partir del payload almacenado en la cola.
     * El objeto $record debe contener, como mínimo, la clave 'job' y 'payload'.
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
            try { $instance->priority((int) $record->priority); } catch (\Throwable) { /* ignore invalid */ }
        }
        if (isset($record->schedule) && $record->schedule) {
            try {
                $dt = new \DateTime($record->schedule->date ?? $record->schedule, new \DateTimeZone($record->schedule->timezone ?? date_default_timezone_get()));
                $instance->scheduled($dt);
            } catch (\Throwable) {
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