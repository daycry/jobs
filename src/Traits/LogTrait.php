<?php

declare(strict_types=1);

namespace Daycry\Logs\Traits;

use CodeIgniter\I18n\Time;
use Daycry\CronJob\Exceptions\CronJobException;
use CodeIgniter\Log\Handlers\BaseHandler;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Result;

/**
 * Trait LogTrait
 */
trait LogTrait
{
    protected ?Time $start              = null;
    protected ?Time $end                = null;
    protected ?Time $testTime           = null;
    protected ?BaseHandler $handler = null;

    public function startLog(?string $start = null): self
    {
        $this->start = ($start) ? new Time($start) : Time::now();

        return $this;
    }

    public function getStartAt(): ?Time
    {
        return $this->start;
    }

    public function getEndAt(): ?Time
    {
        return $this->end;
    }

    public function endLog(?string $end = null): self
    {
        $this->end = ($end) ? new Time($end) : Time::now();

        return $this;
    }

    public function duration(): string
    {
        $interval = $this->end->diff($this->start);

        return $interval->format('%H:%I:%S');
    }

    public function saveLog(Result $result): void
    {
        if (config('Jobs')->logPerformance) {
            if (! $this->end) {
                $this->endLog(null);
            }

            $content = $result->getData();
            $this->setHandler();

            // Truncate output & error if configured
            if (config('Jobs')->maxOutputLength !== null && config('Jobs')->maxOutputLength >= 0) {
                if ($content !== null) {
                    $len = \strlen($content);
                    if ($len > config('Jobs')->maxOutputLength) {
                        $content = \substr($content, 0, config('Jobs')->maxOutputLength) . "\n[truncated {$len} -> " . config('Jobs')->maxOutputLength . " chars]";
                    }
                }
            }

            $normalizedOutput = null;
            if (is_array($content) || is_object($content)) {
                $normalizedOutput = json_encode($content);
            } elseif ($content !== null) {
                $normalizedOutput = $content;
            }

            $data = [
                'name'        => $this->getName(),
                'job'        => $this->getJob(),
                'payload'      => (\is_object($this->getPayload())) ? \json_encode($this->getPayload()) : $this->getPayload(),
                'environment' => $this->environments ? \json_encode($this->environments) : null,
                'start_at'    => $this->start->format('Y-m-d H:i:s'),
                'end_at'      => $this->end->format('Y-m-d H:i:s'),
                'duration'    => $this->duration(),
                'output'      => ($result->isSuccess() === true) ? $normalizedOutput : null,
                'error'       => ($result->isSuccess() !== true) ? $normalizedOutput : null,
                'test_time'   => ($this->testTime) ? $this->testTime->format('Y-m-d H:i:s') : null,
            ];

            $this->handler->handle('info',json_encode($data));
        }
    }

    private function setHandler(): void
    {
        if (! config('Jobs')->log || ! array_key_exists(config('Jobs')->log, config('Jobs')->loggers)) {
            throw JobException::forInvalidLogType();
        }

        $class         = config('Jobs')->loggers[config('Jobs')->log];
        $this->handler = new $class();
        if(method_exists($this->handler, 'setPath')) {
            $this->handler->setPath($this->getName());
        }
    }
}
