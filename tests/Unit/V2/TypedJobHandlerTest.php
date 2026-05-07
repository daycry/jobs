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

namespace Tests\Unit\V2;

use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\V2\Handlers\TypedJobHandler;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class TypedJobHandlerTest extends TestCase
{
    public function testHydratesAssociativeArrayIntoDtoViaConstructor(): void
    {
        $handler = new _ImportHandler();

        $result = $handler->handle(['source' => 'crm', 'rows' => 10]);

        $this->assertSame('crm:10', $result);
    }

    public function testAcceptsAlreadyTypedDtoUnchanged(): void
    {
        $handler = new _ImportHandler();

        $dto = new _ImportRequest('warehouse', 5);
        $this->assertSame('warehouse:5', $handler->handle($dto));
    }

    public function testHydratesFromJsonString(): void
    {
        $handler = new _ImportHandler();

        $this->assertSame('mobile:7', $handler->handle('{"source":"mobile","rows":7}'));
    }

    public function testRejectsUnsupportedPayloadType(): void
    {
        $this->expectException(JobException::class);

        (new _ImportHandler())->handle(123);
    }

    public function testRejectsMissingPayloadType(): void
    {
        $this->expectException(JobException::class);

        (new _BadHandler())->handle(['x' => 1]);
    }
}

final class _ImportRequest
{
    public function __construct(public string $source, public int $rows)
    {
    }
}

final class _ImportHandler extends TypedJobHandler
{
    public function payloadType(): string
    {
        return _ImportRequest::class;
    }

    protected function run(object $payload): mixed
    {
        /** @var _ImportRequest $payload */
        return $payload->source . ':' . $payload->rows;
    }
}

/**
 * Intentionally returns a non-existent class so we can verify TypedJobHandler
 * rejects bad payloadType() declarations.
 */
final class _BadHandler extends TypedJobHandler
{
    public function payloadType(): string
    {
        // The contract is class-string but we deliberately return an invalid value to
        // exercise the runtime safety check inside TypedJobHandler::handle().
        // @phpstan-ignore-next-line return.type
        return '\\NonExistent\\Dto';
    }

    protected function run(object $payload): mixed
    {
        return null;
    }
}
