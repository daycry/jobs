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

use Daycry\Jobs\Exceptions\QueueException;
use Daycry\Jobs\Interfaces\QueueInterface;
use Daycry\Jobs\Interfaces\WorkerInterface;

/**
 * Centralized queue backend factory and registry.
 *
 * Responsibilities:
 *  - Instantiate queue backends from configuration
 *  - Cache instances (singleton per worker key)
 *  - Validate configuration consistency
 *
 * Usage:
 *   $queue = QueueManager::instance()->get('redis');
 *   $queue = QueueManager::instance()->getDefault();
 *
 * Benefits:
 *  - Single source of truth for queue instantiation
 *  - Easier testing (can inject test doubles)
 *  - Cleaner codebase (no scattered `new XxxQueue()`)
 */
class QueueManager
{
    private static ?QueueManager $instance = null;
    private array $instances = [];
    private array $workers;
    private string $defaultWorker;

    private function __construct()
    {
        $config              = config('Jobs');
        $this->workers       = $config->workers ?? [];
        $this->defaultWorker = $config->worker ?? 'sync';

        if (empty($this->workers)) {
            throw QueueException::forInvalidWorker('No workers configured');
        }
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Reinicia la instancia singleton (útil para tests o cambios de config runtime).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Obtiene instancia del worker especificado por nombre.
     *
     * @throws QueueException si el worker no existe
     */
    public function get(string $workerName): QueueInterface&WorkerInterface
    {
        if (! array_key_exists($workerName, $this->workers)) {
            throw QueueException::forInvalidWorker($workerName);
        }

        if (! isset($this->instances[$workerName])) {
            $class = $this->workers[$workerName];
            $this->instances[$workerName] = new $class();
        }

        return $this->instances[$workerName];
    }

    /**
     * Obtiene instancia del worker por defecto configurado.
     */
    public function getDefault(): QueueInterface&WorkerInterface
    {
        return $this->get($this->defaultWorker);
    }

    /**
     * Registra un worker personalizado en runtime (opcional, para extensibilidad).
     */
    public function register(string $name, string $class): void
    {
        $this->workers[$name] = $class;
        // Limpiar cache si existía
        unset($this->instances[$name]);
    }

    /**
     * Lista todos los workers disponibles.
     */
    public function available(): array
    {
        return array_keys($this->workers);
    }

    /**
     * Nombre del worker por defecto.
     */
    public function getDefaultWorkerName(): string
    {
        return $this->defaultWorker;
    }
}
