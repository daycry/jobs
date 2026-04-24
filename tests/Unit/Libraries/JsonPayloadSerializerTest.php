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

namespace Tests\Unit\Libraries;

use Daycry\Jobs\Libraries\JsonPayloadSerializer;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JsonPayloadSerializerTest extends TestCase
{
    public function testSerializeInjectsSchemaVersion(): void
    {
        $serializer = new JsonPayloadSerializer(schemaVersion: 2);
        $payload    = (object) ['job' => 'MyJob', 'data' => 'hello'];
        $json       = $serializer->serialize($payload);
        $decoded    = json_decode($json);
        $this->assertSame(2, $decoded->_schemaVersion);
    }

    public function testSerializeDoesNotOverwriteExistingSchemaVersion(): void
    {
        $serializer = new JsonPayloadSerializer(schemaVersion: 3);
        $payload    = (object) ['job' => 'MyJob', '_schemaVersion' => 1];
        $json       = $serializer->serialize($payload);
        $decoded    = json_decode($json);
        // Pre-existing version should not be overwritten
        $this->assertSame(1, $decoded->_schemaVersion);
    }

    public function testSerializeWithNullSchemaVersionDoesNotInject(): void
    {
        $serializer = new JsonPayloadSerializer(schemaVersion: null);
        $payload    = (object) ['job' => 'MyJob'];
        $json       = $serializer->serialize($payload);
        $decoded    = json_decode($json);
        $this->assertFalse(isset($decoded->_schemaVersion));
    }

    public function testDeserializeValidJson(): void
    {
        $serializer = new JsonPayloadSerializer();
        $result     = $serializer->deserialize('{"job":"MyJob","data":"test"}');
        $this->assertIsObject($result);
        $this->assertSame('MyJob', $result->job);
    }

    public function testDeserializeInvalidJsonReturnsNull(): void
    {
        $serializer = new JsonPayloadSerializer();
        $result     = $serializer->deserialize('{invalid json}');
        $this->assertNull($result);
    }

    public function testDeserializeArrayJsonReturnsNull(): void
    {
        $serializer = new JsonPayloadSerializer();
        $result     = $serializer->deserialize('[1, 2, 3]');
        $this->assertNull($result);
    }

    public function testGetSchemaVersionReturnsVersionFromPayload(): void
    {
        $serializer = new JsonPayloadSerializer();
        $payload    = (object) ['_schemaVersion' => 5];
        $this->assertSame(5, $serializer->getSchemaVersion($payload));
    }

    public function testGetSchemaVersionReturnsNullWhenMissing(): void
    {
        $serializer = new JsonPayloadSerializer();
        $payload    = (object) ['job' => 'MyJob'];
        $this->assertNull($serializer->getSchemaVersion($payload));
    }

    public function testValidatePassesWhenRequiredFieldPresent(): void
    {
        $serializer = new JsonPayloadSerializer();
        $payload    = (object) ['job' => 'MyJob'];
        $this->assertTrue($serializer->validate($payload));
    }

    public function testValidateFailsWhenRequiredFieldMissing(): void
    {
        $serializer = new JsonPayloadSerializer();
        $payload    = (object) ['data' => 'something'];
        $this->assertFalse($serializer->validate($payload));
    }

    public function testValidateWithCustomRequiredFields(): void
    {
        $serializer = new JsonPayloadSerializer();
        $payload    = (object) ['foo' => 'bar', 'baz' => 'qux'];
        $this->assertTrue($serializer->validate($payload, ['foo', 'baz']));
        $this->assertFalse($serializer->validate($payload, ['foo', 'missing']));
    }

    public function testMigrateSetsSchemaVersionOnLegacyPayload(): void
    {
        $serializer = new JsonPayloadSerializer(schemaVersion: 2);
        $payload    = (object) ['job' => 'LegacyJob'];
        $migrated   = $serializer->migrate($payload);
        $this->assertSame(2, $migrated->_schemaVersion);
    }

    public function testMigrateDoesNotChangeVersionWhenAlreadySet(): void
    {
        $serializer = new JsonPayloadSerializer(schemaVersion: 2);
        $payload    = (object) ['job' => 'Job', '_schemaVersion' => 1];
        $migrated   = $serializer->migrate($payload);
        // The migrate method only sets version when null; does not overwrite
        $this->assertSame(1, $migrated->_schemaVersion);
    }

    public function testMigrateWithNullSchemaVersionDoesNotModify(): void
    {
        $serializer = new JsonPayloadSerializer(schemaVersion: null);
        $payload    = (object) ['job' => 'Job'];
        $migrated   = $serializer->migrate($payload);
        $this->assertFalse(isset($migrated->_schemaVersion));
    }
}
