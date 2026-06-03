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

namespace Daycry\Jobs\Handlers;

use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;
use ReflectionClass;
use ReflectionException;
use Throwable;

/**
 * Base class for handlers that want to receive a *typed* payload object instead of
 * the loosely-typed `mixed` value carried by {@see JobContext}.
 *
 * Subclasses declare the payload class via {@see payloadType()} and implement
 * {@see run()} which is called with an instance of that class. If the queue carried
 * a serialised representation (associative array, stdClass with public properties, or a
 * JSON string), TypedJobHandler will rehydrate it via the public constructor or by setting
 * matching public properties — whichever the target class supports.
 *
 * Example:
 * ```
 * final class ProcessImport extends TypedJobHandler
 * {
 *     public function payloadType(): string { return ImportRequest::class; }
 *     protected function run(object $payload): mixed { ... }
 * }
 * ```
 */
abstract class TypedJobHandler extends AbstractJobHandler
{
    /**
     * @return class-string FQCN of the DTO that {@see run()} expects to receive.
     */
    abstract public function payloadType(): string;

    /**
     * Process the rehydrated payload. The return value is recorded as the job's output.
     */
    abstract protected function run(object $payload): mixed;

    public function handle(JobContext $ctx): mixed
    {
        $expected = $this->payloadType();
        if (! class_exists($expected)) {
            throw JobException::validationError("TypedJobHandler::payloadType() must return an existing class, got '{$expected}'.");
        }

        $payload  = $ctx->payload;
        $instance = $payload instanceof $expected ? $payload : $this->hydrate($payload, $expected);

        return $this->run($instance);
    }

    /**
     * @param class-string $type
     */
    private function hydrate(mixed $payload, string $type): object
    {
        $data = $this->normalisePayload($payload);

        // Constructor-based hydration: if the target class accepts an associative array
        // (e.g. via array unpacking or named arguments), prefer that; otherwise fall back
        // to setting public properties one by one.
        try {
            $reflection = new ReflectionClass($type);
            $ctor       = $reflection->getConstructor();

            if ($ctor === null) {
                $instance = $reflection->newInstance();
            } else {
                $args = [];

                foreach ($ctor->getParameters() as $param) {
                    $name = $param->getName();
                    if (array_key_exists($name, $data)) {
                        $args[$name] = $data[$name];
                    } elseif ($param->isDefaultValueAvailable()) {
                        $args[$name] = $param->getDefaultValue();
                    } elseif ($param->allowsNull()) {
                        $args[$name] = null;
                    } else {
                        throw JobException::validationError("TypedJobHandler cannot hydrate '{$type}': missing required parameter '{$name}'.");
                    }
                }

                $instance = $reflection->newInstanceArgs($args);
            }
        } catch (ReflectionException $e) {
            throw JobException::validationError("TypedJobHandler hydration failed for '{$type}': " . $e->getMessage());
        }

        // Best-effort: if there are leftover keys not consumed by the constructor and the
        // class has matching public properties, set them so optional fields survive the
        // round-trip without forcing every DTO to take every value through the constructor.
        foreach ($data as $key => $value) {
            if (! $reflection->hasProperty($key)) {
                continue;
            }

            $property = $reflection->getProperty($key);
            if (! $property->isPublic() || $property->isReadOnly()) {
                continue;
            }

            try {
                $property->setValue($instance, $value);
            } catch (Throwable) {
                // Ignore typed-property mismatches — constructor-only fields stay untouched.
            }
        }

        return $instance;
    }

    /**
     * Convert raw payload into an associative array.
     *
     * @return array<string, mixed>
     */
    private function normalisePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (is_object($payload)) {
            return get_object_vars($payload);
        }
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw JobException::validationError('TypedJobHandler payload must be an array, object, or JSON string.');
    }
}
