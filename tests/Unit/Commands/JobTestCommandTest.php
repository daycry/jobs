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

use CodeIgniter\CLI\Commands;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class JobTestCommandTest extends TestCase
{
    public function testCommandOutputsParams(): void
    {
        // Obtener instancia fresca de Commands para descubrir el comando de test
        $runner = new Commands();

        ob_start();
        // run(command, params[]) - params sin incluir el nombre
        $runner->run('jobs:test', ['foo', 'bar']);
        $output = ob_get_clean();

        $this->assertNotFalse($output);
        // El comando actual imprime: 'Commands can output text. ["foo","bar"]'
        $this->assertStringContainsString('Commands can output text.', $output);
        $this->assertStringContainsString('foo', $output);
        $this->assertStringContainsString('bar', $output);
    }

    public function testCommandNoParams(): void
    {
        $runner = new Commands();
        ob_start();
        $runner->run('jobs:test', []);
        $output = ob_get_clean();

        $this->assertNotFalse($output);
        // Sin params debería contener el prefijo y un array vacío []
        $this->assertStringContainsString('Commands can output text.', $output);
        $this->assertStringContainsString('[]', $output);
    }
}
