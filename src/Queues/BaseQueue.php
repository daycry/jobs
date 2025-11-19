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

namespace Daycry\Jobs\Queues;

use Daycry\Jobs\Interfaces\IdGeneratorInterface;
use Daycry\Jobs\Interfaces\PayloadSerializerInterface;
use Daycry\Jobs\Libraries\DelayResult;
use Daycry\Jobs\Libraries\JsonPayloadSerializer;
use Daycry\Jobs\Libraries\RandomIdGenerator;

abstract class BaseQueue
{
    private ?DelayResult $delayResult               = null;
    private ?IdGeneratorInterface $idGenerator      = null;
    private ?PayloadSerializerInterface $serializer = null;

    /**
     * Calcula delay desde schedule y retorna DelayResult.
     */
    protected function calculateDelay(object $data): DelayResult
    {
        $schedule          = $data->schedule ?? null;
        $this->delayResult = DelayResult::fromSchedule($schedule);

        return $this->delayResult;
    }

    protected function getDelayResult(): DelayResult
    {
        return $this->delayResult ?? new DelayResult(0, null);
    }

    public function setIdGenerator(IdGeneratorInterface $generator): self
    {
        $this->idGenerator = $generator;

        return $this;
    }

    protected function getIdGenerator(): IdGeneratorInterface
    {
        if (! $this->idGenerator) {
            // Posible clase personalizada desde config en fases futuras
            $this->idGenerator = new RandomIdGenerator();
        }

        return $this->idGenerator;
    }

    protected function generateId(?string $prefix = null, int $bytes = 8): string
    {
        return $this->getIdGenerator()->generate($bytes, $prefix);
    }

    public function setSerializer(PayloadSerializerInterface $serializer): self
    {
        $this->serializer = $serializer;

        return $this;
    }

    protected function getSerializer(): PayloadSerializerInterface
    {
        if (! $this->serializer) {
            $this->serializer = new JsonPayloadSerializer();
        }

        return $this->serializer;
    }
}
