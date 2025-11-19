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

namespace Daycry\Jobs\Interfaces;

interface PayloadSerializerInterface
{
    /**
     * Serializa un objeto de payload a string (normalmente JSON).
     *
     * @param object $payload Objeto a serializar
     * @return string Payload serializado
     */
    public function serialize(object $payload): string;

    /**
     * Deserializa un string a objeto payload.
     *
     * @param string $data String serializado
     * @return object|null Objeto deserializado o null si falla
     */
    public function deserialize(string $data): ?object;

    /**
     * Verifica si el payload tiene una versión de esquema específica.
     *
     * @param object $payload Payload a verificar
     * @return int|null Versión del esquema o null si no versionado
     */
    public function getSchemaVersion(object $payload): ?int;
}
