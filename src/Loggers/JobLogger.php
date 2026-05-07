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
    private const MAX_MASK_DEPTH = 10;

    private ?Time $start          = null;
    private ?Time $end            = null;
    private ?BaseHandler $handler = null;
    private readonly string $executionId;

    /**
     * @param BaseHandler|null $handler     Optional pre-built handler (skips ensureHandler() resolution).
     * @param string|null      $executionId Optional pre-computed execution ID; defaults to a UUIDv7.
     */
    public function __construct(?BaseHandler $handler = null, ?string $executionId = null)
    {
        $this->handler     = $handler;
        $this->executionId = $executionId ?? (string) service('uuid')->uuid7()->toRfc4122();
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
        if (! $this->start instanceof Time) {
            $this->start = Time::createFromTimestamp((int) $result->startedAt);
        }
        if (! $this->end instanceof Time) {
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

        $outputLength = $output !== null ? strlen((string) $output) : 0;
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

        $encoded = json_encode($data);

        return $encoded !== false ? $encoded : null;
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
        if (! $this->handler instanceof BaseHandler) {
            $class         = $config->loggers[$config->log];
            $this->handler = new $class();
        }
        if (method_exists($this->handler, 'setPath')) {
            // Use job name as path/filename context
            // setPath is called later with the job name by consumer if needed
        }
    }

    /**
     * Recursively mask sensitive keys in arrays/objects.
     * Bounded by MAX_MASK_DEPTH to prevent stack overflow on adversarial deep payloads.
     *
     * @param list<string> $keys
     */
    private function maskSensitive(mixed $value, array $keys): mixed
    {
        if (! $value || $keys === []) {
            return $value;
        }
        $lowerKeys = array_map(strtolower(...), $keys);
        $mask      = static function ($v, int $depth) use ($lowerKeys, &$mask) {
            if ($depth >= self::MAX_MASK_DEPTH) {
                return '[truncated:max-depth]';
            }
            if (is_array($v)) {
                $out = [];

                foreach ($v as $k => $val) {
                    $out[$k] = in_array(strtolower((string) $k), $lowerKeys, true) ? '***' : $mask($val, $depth + 1);
                }

                return $out;
            }
            if (is_object($v)) {
                $o = clone $v;

                foreach (get_object_vars($o) as $k => $val) {
                    $o->{$k} = in_array(strtolower((string) $k), $lowerKeys, true) ? '***' : $mask($val, $depth + 1);
                }

                return $o;
            }

            return $v;
        };

        return $mask($value, 0);
    }

    /**
     * Sanitize token-like patterns (API keys, JWTs, etc.) from strings.
     * Detects common patterns and masks them automatically.
     * Bounded by MAX_MASK_DEPTH on recursive structures.
     */
    private function sanitizeTokenPatterns(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= self::MAX_MASK_DEPTH) {
            return '[truncated:max-depth]';
        }

        if (is_string($value)) {
            // Mask JWT tokens (format: xxx.yyy.zzz where parts are base64)
            $value = preg_replace(
                '/\b[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/',
                '***JWT_TOKEN***',
                $value,
            );

            // Mask known API-key shapes:
            //  - Stripe: sk_live_/sk_test_/pk_live_/pk_test_ + 16+ chars
            //  - AWS access key: AKIA + 16 uppercase alphanums
            //  - GitHub PAT: ghp_/gho_/ghu_/ghs_/ghr_ + 30+ chars
            //  - Slack: xox[abp]- + 24+ chars
            //  - Generic: opaque token of >=40 chars (avoids false positives on UUIDs/SHA-1)
            $value = preg_replace(
                '/\b(?:'
                . 'sk_(?:live|test)_[A-Za-z0-9]{16,}'
                . '|pk_(?:live|test)_[A-Za-z0-9]{16,}'
                . '|AKIA[0-9A-Z]{16}'
                . '|gh[pousr]_[A-Za-z0-9]{30,}'
                . '|xox[abprs]-[A-Za-z0-9-]{20,}'
                . '|[A-Za-z0-9_-]{40,}'
                . ')\b/',
                '***API_KEY***',
                (string) $value,
            );

            // Mask Bearer tokens
            $value = preg_replace(
                '/Bearer\s+[A-Za-z0-9_\-\.=]+/i',
                'Bearer ***TOKEN***',
                (string) $value,
            );
        } elseif (is_array($value)) {
            $out = [];

            foreach ($value as $k => $v) {
                $out[$k] = $this->sanitizeTokenPatterns($v, $depth + 1);
            }

            return $out;
        } elseif (is_object($value)) {
            $clone = clone $value;

            foreach (get_object_vars($clone) as $k => $v) {
                $clone->{$k} = $this->sanitizeTokenPatterns($v, $depth + 1);
            }

            return $clone;
        }

        return $value;
    }
}
