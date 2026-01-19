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

namespace Daycry\Jobs\Loggers;

use CodeIgniter\I18n\Time;
use CodeIgniter\Log\Handlers\BaseHandler;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\ExecutionResult;
use Daycry\Jobs\Job;

/**
 * Centralized job logger replacing legacy LogTrait.
 * Captures start/end timestamps and writes structured execution records through
 * the configured handler (file or database). Accepts immutable ExecutionResult.
 */
class JobLogger
{
    private ?Time $start          = null;
    private ?Time $end            = null;
    private ?BaseHandler $handler = null;
    private string $executionId;

    public function __construct()
    {
        $this->executionId = $this->generateUuidV4();
    }

    /**
     * Mark start timestamp (optionally injecting a specific datetime string).
     */
    public function start(?string $at = null): void
    {
        $this->start = ($at) ? new Time($at) : Time::now();
    }

    /**
     * Mark end timestamp (optionally injecting a specific datetime string).
     */
    public function end(?string $at = null): void
    {
        $this->end = ($at) ? new Time($at) : Time::now();
    }

    public function getStart(): ?Time
    {
        return $this->start;
    }

    public function getEnd(): ?Time
    {
        return $this->end;
    }

    /**
     * Human readable HH:MM:SS duration or null if incomplete.
     */
    public function duration(): ?string
    {
        if (! $this->start || ! $this->end) {
            return null;
        }
        $interval = $this->end->diff($this->start);

        return $interval->format('%H:%I:%S');
    }

    /**
     * Persist log for executed job using ExecutionResult (respects logPerformance flag).
     */
    public function log(Job $job, ExecutionResult $result, ?Time $testTime = null): void
    {
        $config = config('Jobs');
        if (! $config->logPerformance) {
            return; // logging disabled
        }
        if (! $this->start) {
            $this->start = Time::createFromTimestamp((int) $result->startedAt);
        }
        if (! $this->end) {
            $this->end = Time::createFromTimestamp((int) $result->endedAt);
        }
        $this->ensureHandler();

        $output = $result->success ? $result->output : null;
        $error  = $result->success ? null : $result->error;

        // Truncation
        if ($config->maxOutputLength !== null && $config->maxOutputLength >= 0) {
            $truncate = static function (?string $text) use ($config): ?string {
                if ($text === null) {
                    return null;
                }
                $len = strlen($text);
                if ($len > $config->maxOutputLength) {
                    return substr($text, 0, $config->maxOutputLength) . "\n[truncated {$len} -> {$config->maxOutputLength} chars]";
                }

                return $text;
            };
            $output = $truncate($output);
            $error  = $truncate($error);
        }

        $rawPayload = $job->getPayload();
        // Merge default keys with user-configured (prevent accidental override dropping defaults)
        $defaultKeys   = ['password', 'token', 'secret', 'authorization', 'api_key'];
        $configured    = is_array($config->sensitiveKeys ?? null) ? $config->sensitiveKeys : [];
        $sensitiveKeys = array_values(array_unique(array_merge($defaultKeys, $configured)));
        $maskedPayload = $this->maskSensitive($rawPayload, $sensitiveKeys);

        // Additional pattern-based sanitization for token-like strings
        $maskedPayload = $this->sanitizeTokenPatterns($maskedPayload);
        $output        = $this->sanitizeTokenPatterns($output);
        $error         = $this->sanitizeTokenPatterns($error);
        $payloadJson   = $this->normalize($maskedPayload);

        $outputLength = $output !== null ? strlen($output) : 0;
        $payloadHash  = $payloadJson ? hash('sha256', $payloadJson) : null;

        $data = [
            'executionId'   => $this->executionId,
            'name'          => $job->getName(),
            'job'           => $job->getJob(),
            'attempt'       => $job->getAttempt(),
            'queue'         => $job->getQueue(),
            'source'        => method_exists($job, 'getSource') ? $job->getSource() : null,
            'retryStrategy' => $config->retryBackoffStrategy ?? null,
            'payload'       => $payloadJson,
            'payloadHash'   => $payloadHash,
            'environment'   => null,
            'start_at'      => $this->start?->format('Y-m-d H:i:s'),
            'end_at'        => $this->end?->format('Y-m-d H:i:s'),
            'duration'      => $this->duration(),
            'output'        => $this->normalize($this->maskSensitive($output, $sensitiveKeys)),
            'outputLength'  => $outputLength,
            'error'         => $this->normalize($this->maskSensitive($error, $sensitiveKeys)),
            'test_time'     => $testTime?->format('Y-m-d H:i:s'),
        ];

        if (method_exists($this->handler, 'setPath')) {
            $this->handler->setPath($job->getName());
        }
        $this->handler->handle('info', json_encode($data));
    }

    /**
     * Normalize scalar/complex data to JSON or return null if empty.
     */
    private function normalize(mixed $data): ?string
    {
        if ($data === null || $data === '') {
            return null;
        }
        if (is_scalar($data)) {
            return (string) $data;
        }

        return json_encode($data);
    }

    /**
     * Resolve and memoize the configured handler, validating configuration.
     */
    private function ensureHandler(): void
    {
        $config = config('Jobs');
        if (! $config->log || ! array_key_exists($config->log, $config->loggers)) {
            throw JobException::forInvalidLogType();
        }
        if (! $this->handler) {
            $class         = $config->loggers[$config->log];
            $this->handler = new $class();
        }
        if (method_exists($this->handler, 'setPath')) {
            // Use job name as path/filename context
            // setPath is called later with the job name by consumer if needed
        }
    }

    /**
     * Generate a RFC 4122 compliant UUID v4 (random).
     */
    private function generateUuidV4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80); // variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Recursively mask sensitive keys in arrays/objects.
     *
     * @param list<string> $keys
     */
    private function maskSensitive(mixed $value, array $keys): mixed
    {
        if (! $value || empty($keys)) {
            return $value;
        }
        $lowerKeys = array_map(static fn ($k) => strtolower($k), $keys);
        $mask      = static function ($v) use ($lowerKeys, &$mask) {
            if (is_array($v)) {
                $out = [];

                foreach ($v as $k => $val) {
                    if (in_array(strtolower((string) $k), $lowerKeys, true)) {
                        $out[$k] = '***';
                    } else {
                        $out[$k] = $mask($val);
                    }
                }

                return $out;
            }
            if (is_object($v)) {
                $o = clone $v;

                foreach (get_object_vars($o) as $k => $val) {
                    if (in_array(strtolower((string) $k), $lowerKeys, true)) {
                        $o->{$k} = '***';
                    } else {
                        $o->{$k} = $mask($val);
                    }
                }

                return $o;
            }

            return $v;
        };

        return $mask($value);
    }

    /**
     * Sanitize token-like patterns (API keys, JWTs, etc.) from strings.
     * Detects common patterns and masks them automatically.
     */
    private function sanitizeTokenPatterns(mixed $value): mixed
    {
        if (is_string($value)) {
            // Mask JWT tokens (format: xxx.yyy.zzz where parts are base64)
            $value = preg_replace(
                '/\b[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/',
                '***JWT_TOKEN***',
                $value,
            );

            // Mask long alphanumeric strings (likely API keys/tokens)
            // At least 32 chars, mostly alphanumeric
            $value = preg_replace(
                '/\b[A-Za-z0-9_-]{32,}\b/',
                '***API_KEY***',
                $value,
            );

            // Mask Bearer tokens
            $value = preg_replace(
                '/Bearer\s+[A-Za-z0-9_\-\.]+/i',
                'Bearer ***TOKEN***',
                $value,
            );
        } elseif (is_array($value)) {
            return array_map([$this, 'sanitizeTokenPatterns'], $value);
        } elseif (is_object($value)) {
            $clone = clone $value;

            foreach (get_object_vars($clone) as $k => $v) {
                $clone->{$k} = $this->sanitizeTokenPatterns($v);
            }

            return $clone;
        }

        return $value;
    }
}
