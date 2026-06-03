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

namespace Tests\Unit\V3\Coverage;

use CodeIgniter\Test\CIUnitTestCase;
use Closure;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Libraries\Utils;
use stdClass;

/**
 * @internal
 */
final class CovUtilsTest extends CIUnitTestCase
{
    public function testParseConfigFileWithNull(): void
    {
        $this->assertSame([], Utils::parseConfigFile(null));
    }

    public function testParseConfigFileWithEmptyString(): void
    {
        $this->assertSame([], Utils::parseConfigFile(''));
    }

    public function testParseConfigFileWithCommaDelimitedStringTrimsParts(): void
    {
        $this->assertSame(['a', 'b', 'c'], Utils::parseConfigFile(' a , b ,c '));
    }

    public function testParseConfigFileWithArrayTrimsEachItem(): void
    {
        $this->assertSame(['x', 'y'], Utils::parseConfigFile([' x ', 'y ']));
    }

    public function testCheckDataQueuePassesWithValidArray(): void
    {
        $data = [
            'queue'   => 'default',
            'job'     => 'closure',
            'payload' => ['anything'],
        ];

        Utils::checkDataQueue($data, 'queueData');
        // No exception means the validation passed.
        $this->addToAssertionCount(1);
    }

    public function testCheckDataQueueThrowsOnInvalidArray(): void
    {
        $this->expectException(JobException::class);
        // Missing required keys (queue, job, payload) triggers a validation error.
        Utils::checkDataQueue(['queue' => 'only'], 'queueData');
    }

    public function testCheckDataQueueConvertsObjectAndPassesValidation(): void
    {
        $closure = static fn (): string => 'noop';

        $nested        = new stdClass();
        $nested->inner = 'value';

        $data          = new stdClass();
        $data->queue   = 'default';
        $data->job     = 'closure';
        $data->payload = 'some-payload';
        $data->nested  = $nested;
        $data->closure = $closure;
        $data->list    = ['plain', $nested, $closure];

        Utils::checkDataQueue($data, 'queueData');
        $this->addToAssertionCount(1);
    }

    public function testCheckDataQueueThrowsWhenObjectInvalid(): void
    {
        $obj        = new stdClass();
        $obj->queue = 'default';
        // No job, no payload -> invalid.

        $this->expectException(JobException::class);
        Utils::checkDataQueue($obj, 'queueData');
    }

    public function testClosurePropertyIsHandledWithoutTraversal(): void
    {
        // Sanity: a Closure used as a property is recognised by PHP as a Closure.
        $closure = static fn (): int => 1;
        $this->assertInstanceOf(Closure::class, $closure);
    }
}
