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

use Daycry\Jobs\Interfaces\PayloadSerializerInterface;

/**
 * JSON serializer with optional schema versioning.
 *
 * Features:
 *  - Automatic schema version injection (_schemaVersion field)
 *  - Graceful handling of legacy payloads without version
 *  - Validation of required fields
 *  - Migration path for schema upgrades
 *
 * Usage:
 *   $serializer = new JsonPayloadSerializer(schemaVersion: 2);
 *   $json = $serializer->serialize($payload);
 *   $obj = $serializer->deserialize($json);
 */
class JsonPayloadSerializer implements PayloadSerializerInterface
{
    private const DEFAULT_SCHEMA_VERSION = 1;

    public function __construct(
        private ?int $schemaVersion = self::DEFAULT_SCHEMA_VERSION
    ) {
    }

    public function serialize(object $payload): string
    {
        // Clonar para evitar mutar el original
        $data = json_decode(json_encode($payload));

        // Inyectar versión de esquema si está configurada
        if ($this->schemaVersion !== null && ! isset($data->_schemaVersion)) {
            $data->_schemaVersion = $this->schemaVersion;
        }

        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    public function deserialize(string $data): ?object
    {
        try {
            $decoded = json_decode($data, false, 512, JSON_THROW_ON_ERROR);

            return is_object($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    public function getSchemaVersion(object $payload): ?int
    {
        return isset($payload->_schemaVersion) ? (int) $payload->_schemaVersion : null;
    }

    /**
     * Valida que el payload contenga campos mínimos requeridos.
     *
     * @param object $payload Payload a validar
     * @param array $requiredFields Lista de campos obligatorios
     * @return bool True si válido
     */
    public function validate(object $payload, array $requiredFields = ['job']): bool
    {
        foreach ($requiredFields as $field) {
            if (! isset($payload->{$field})) {
                return false;
            }
        }

        return true;
    }

    /**
     * Migra un payload de versión antigua a la actual.
     * Extiende este método en subclases para migraciones complejas.
     *
     * @param object $payload Payload a migrar
     * @return object Payload migrado
     */
    public function migrate(object $payload): object
    {
        $version = $this->getSchemaVersion($payload);

        // Sin versión = legacy (pre-v1), actualizar a v1
        if ($version === null && $this->schemaVersion !== null) {
            $payload->_schemaVersion = $this->schemaVersion;
        }

        // Aquí podrían agregarse migraciones específicas:
        // if ($version === 1 && $this->schemaVersion === 2) { ... }

        return $payload;
    }
}
