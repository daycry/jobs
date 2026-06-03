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

namespace Tests\Unit\V3\Handlers;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Handlers\TypedJobHandler;

/**
 * @internal
 */
final class TypedJobHandlerTest extends CIUnitTestCase
{
    public function testHydratesAssociativeArrayPayloadIntoTypedDto(): void
    {
        $handler = new TypedJobHandlerTestImportHandler();

        $result = $handler->handle(JobContext::fromPayload(['id' => 1, 'name' => 'x']));

        $this->assertSame('1:x', $result);
    }

    public function testAcceptsAlreadyTypedPayloadUnchanged(): void
    {
        $handler = new TypedJobHandlerTestImportHandler();

        $dto    = new TypedJobHandlerTestImportRequest(7, 'warehouse');
        $result = $handler->handle(JobContext::fromPayload($dto));

        $this->assertSame('7:warehouse', $result);
    }

    public function testHydratesFromJsonStringPayload(): void
    {
        $handler = new TypedJobHandlerTestImportHandler();

        $result = $handler->handle(JobContext::fromPayload('{"id":42,"name":"mobile"}'));

        $this->assertSame('42:mobile', $result);
    }

    public function testRejectsUnsupportedPayloadType(): void
    {
        $this->expectException(JobException::class);

        (new TypedJobHandlerTestImportHandler())->handle(JobContext::fromPayload(123));
    }

    public function testRejectsMissingRequiredField(): void
    {
        $this->expectException(JobException::class);

        (new TypedJobHandlerTestImportHandler())->handle(JobContext::fromPayload(['id' => 1]));
    }

    public function testRejectsMissingPayloadType(): void
    {
        $this->expectException(JobException::class);

        (new TypedJobHandlerTestBadHandler())->handle(JobContext::fromPayload(['id' => 1]));
    }
}

final class TypedJobHandlerTestImportRequest
{
    public function __construct(public int $id, public string $name)
    {
    }
}

final class TypedJobHandlerTestImportHandler extends TypedJobHandler
{
    public function payloadType(): string
    {
        return TypedJobHandlerTestImportRequest::class;
    }

    protected function run(object $payload): mixed
    {
        /** @var TypedJobHandlerTestImportRequest $payload */
        return $payload->id . ':' . $payload->name;
    }
}

/**
 * Intentionally returns a non-existent class so we can verify TypedJobHandler
 * rejects bad payloadType() declarations.
 */
final class TypedJobHandlerTestBadHandler extends TypedJobHandler
{
    public function payloadType(): string
    {
        // The contract is class-string but we deliberately return an invalid value to
        // exercise the runtime safety check inside TypedJobHandler::handle().
        // @phpstan-ignore return.type
        return '\\NonExistent\\Dto';
    }

    protected function run(object $payload): mixed
    {
        return null;
    }
}
