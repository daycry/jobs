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

use Daycry\Jobs\Execution\ExecutionResult;
use Daycry\Jobs\Job;
use Daycry\Jobs\Loggers\DatabaseHandler;
use Daycry\Jobs\Loggers\JobLogger;
use PHPUnit\Framework\TestCase;

/**
 * Verifica que con el esquema reconstruido (rebuild) los campos extendidos
 * se persisten como columnas explícitas y que la columna `data` conserva el JSON completo.
 *
 * @internal
 */
final class DatabaseHandlerRebuildTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $cfg                 = config('Jobs');
        $cfg->logPerformance = true;
        $cfg->log            = 'database';
    }

    public function testExtendedColumnsPersist(): void
    {
        $cfg = config('Jobs');
        if ($cfg->log !== 'database') {
            $this->markTestSkipped('Database logging no habilitado');
        }

        $job = new Job(job: 'command', payload: ['foo' => 'bar']);
        $job->named('db_rebuild_test');
        $job->source('queue');

        $logger = new JobLogger();
        $logger->start();
        $res = new ExecutionResult(true, 'OK', null, microtime(true) - 0.01, microtime(true));
        $logger->end();

        try {
            $logger->log($job, $res);
        } catch (Throwable $e) {
            $this->markTestSkipped('Base de datos no disponible: ' . $e->getMessage());
        }

        $handler = new DatabaseHandler();
        $history = $handler->history('db_rebuild_test', 1);
        if (empty($history)) {
            $this->markTestSkipped('Sin filas de historial (posible falta de migraciones / DB)');
        }
        $row = $history[0];

        // Columnas explícitas
        $this->assertNotEmpty($row->executionId ?? null, 'executionId debe persistirse');
        $this->assertSame('queue', $row->source ?? null);
        $this->assertTrue(isset($row->attempt), 'attempt column');
        $this->assertTrue(isset($row->payloadHash), 'payloadHash column');
        $this->assertTrue(isset($row->outputLength), 'outputLength column');
        $this->assertTrue(isset($row->status), 'status column');
        $this->assertSame('OK', $row->status);

        // Columna data con JSON completo
        $this->assertNotEmpty($row->data ?? null, 'data JSON debe existir');
        $decoded = json_decode($row->data ?? 'null', true);
        $this->assertIsArray($decoded, 'data JSON decodificable');
        $this->assertArrayHasKey('executionId', $decoded);
        $this->assertArrayHasKey('payloadHash', $decoded);
    }
}
