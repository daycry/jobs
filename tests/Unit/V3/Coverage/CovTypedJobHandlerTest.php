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
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Handlers\TypedJobHandler;
use stdClass;

/**
 * @internal
 */
final class CovTypedJobHandlerTest extends CIUnitTestCase
{
    public function testRejectsNonExistentPayloadType(): void
    {
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('existing class');
        (new CovTypedBadHandler())->handle(JobContext::fromPayload(['id' => 1]));
    }

    public function testHydratesFromAssociativeArray(): void
    {
        $result = (new CovTypedDtoHandler())->handle(
            JobContext::fromPayload(['id' => 1, 'name' => 'alpha']),
        );
        $this->assertSame('1:alpha', $result);
    }

    public function testHydratesFromStdClass(): void
    {
        $payload       = new stdClass();
        $payload->id   = 2;
        $payload->name = 'beta';

        $result = (new CovTypedDtoHandler())->handle(JobContext::fromPayload($payload));
        $this->assertSame('2:beta', $result);
    }

    public function testHydratesFromJsonString(): void
    {
        $result = (new CovTypedDtoHandler())->handle(
            JobContext::fromPayload('{"id":3,"name":"gamma"}'),
        );
        $this->assertSame('3:gamma', $result);
    }

    public function testAcceptsAlreadyTypedInstance(): void
    {
        $dto    = new CovTypedDto(9, 'delta');
        $result = (new CovTypedDtoHandler())->handle(JobContext::fromPayload($dto));
        $this->assertSame('9:delta', $result);
    }

    public function testRejectsUnsupportedPayloadValue(): void
    {
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('array, object, or JSON string');
        (new CovTypedDtoHandler())->handle(JobContext::fromPayload(3.14));
    }

    public function testRejectsMissingRequiredParameter(): void
    {
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('missing required parameter');
        (new CovTypedDtoHandler())->handle(JobContext::fromPayload(['id' => 5]));
    }

    public function testHydratesNoConstructorAndSetsPublicProperties(): void
    {
        $result = (new CovTypedNoCtorHandler())->handle(
            JobContext::fromPayload(['label' => 'tagged', 'ignored' => 'x']),
        );
        $this->assertSame('tagged', $result);
    }

    public function testUsesDefaultAndNullableParameters(): void
    {
        // 'count' has a default; 'note' is nullable with no default. Neither is supplied.
        $result = (new CovTypedOptionalHandler())->handle(
            JobContext::fromPayload(['id' => 1]),
        );
        $this->assertSame('1:7:null', $result);
    }
}

final class CovTypedDto
{
    public function __construct(public int $id, public string $name)
    {
    }
}

final class CovTypedDtoHandler extends TypedJobHandler
{
    public function payloadType(): string
    {
        return CovTypedDto::class;
    }

    protected function run(object $payload): mixed
    {
        /** @var CovTypedDto $payload */
        return $payload->id . ':' . $payload->name;
    }
}

final class CovTypedBadHandler extends TypedJobHandler
{
    public function payloadType(): string
    {
        // Deliberately invalid to exercise the class_exists() guard.
        // @phpstan-ignore return.type
        return '\\Cov\\NonExistent\\Dto';
    }

    protected function run(object $payload): mixed
    {
        return null;
    }
}

final class CovTypedNoCtorDto
{
    public string $label = '';
}

final class CovTypedNoCtorHandler extends TypedJobHandler
{
    public function payloadType(): string
    {
        return CovTypedNoCtorDto::class;
    }

    protected function run(object $payload): mixed
    {
        /** @var CovTypedNoCtorDto $payload */
        return $payload->label;
    }
}

final class CovTypedOptionalDto
{
    public function __construct(
        public int $id,
        public int $count = 7,
        public ?string $note = null,
    ) {
    }
}

final class CovTypedOptionalHandler extends TypedJobHandler
{
    public function payloadType(): string
    {
        return CovTypedOptionalDto::class;
    }

    protected function run(object $payload): mixed
    {
        /** @var CovTypedOptionalDto $payload */
        return $payload->id . ':' . $payload->count . ':' . ($payload->note ?? 'null');
    }
}
