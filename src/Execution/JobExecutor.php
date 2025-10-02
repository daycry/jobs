<?php

declare(strict_types=1);

namespace Daycry\Jobs\Execution;

use Daycry\Jobs\Job;
use Daycry\Jobs\Result;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Interfaces\JobInterface;
use Throwable;

/**
 * Centraliza la ejecución de un Job (scheduled o proveniente de una cola)
 * aplicando el pipeline beforeRun -> handle -> afterRun, captura de salida,
 * logging y notificaciones.
 */
class JobExecutor
{
    /**
     * Ejecuta un Job y siempre retorna un Result consistente.
     */
    public function execute(Job $job): Result
    {
        $result = new Result(false, null);

    $bufferActive = false;
    try {
            if ($job->getStartAt() === null) {
                $job->startLog();
            }

            $mapping = config('Jobs')->jobs;
            $class   = $mapping[$job->getJob()] ?? null;

            if (! $class || ! is_subclass_of($class, Job::class)) {
                throw JobException::forInvalidJob($job->getJob());
            }

            /** @var JobInterface $handler */
            $handler = new $class();

            // Hook anterior a la ejecución concreta
            $job = $handler->beforeRun($job);

            ob_start();
            $bufferActive = true;
            $result = $handler->handle($job->getPayload()); // Debe devolver Result
            $buffer = ob_get_clean();
            $bufferActive = false;

            // Normalizar buffer: tratar cadena vacía o false como null (sin salida)
            if ($buffer === '' || $buffer === false) {
                $buffer = null;
            }

            // Combinar buffer y data devuelta solo si realmente hay salida
            if ($result->getData() === null && $buffer !== null) {
                $result->setData($buffer);
            } elseif ($buffer !== null && $result->getData() !== null && is_string($result->getData())) {
                // Evitar duplicar nueva línea si buffer ya empieza con salto
                $separator = str_starts_with($buffer, "\n") ? '' : "\n";
                $result->setData($result->getData() . $separator . $buffer);
            }

            // Hook posterior
            $job = $handler->afterRun($job);

            // Aseguramos success si no se marcó explícitamente
            if (! $result->isSuccess()) {
                $result->setSuccess(true);
            }
        } catch (Throwable $e) {
            if ($bufferActive && ob_get_level() > 0) {
                // Limpieza de buffer en caso de excepción
                try { ob_end_clean(); } catch (\Throwable) { /* ignore */ }
            }
            $result = new Result(false, $e->getMessage());
        } finally {
            // Finaliza tiempos y log
            $job->endLog();
            $job->saveLog($result);

            // Notificaciones
            if ($result->isSuccess() && $job->shouldNotifyOnSuccess()) {
                $job->notify($result);
            } elseif (! $result->isSuccess() && $job->shouldNotifyOnFailure()) {
                $job->notify($result);
            }
        }

        return $result;
    }
}
