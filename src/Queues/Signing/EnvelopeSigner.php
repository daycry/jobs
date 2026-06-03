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

namespace Daycry\Jobs\Queues\Signing;

/**
 * Signs and verifies the JSON representation of a queue envelope using HMAC-SHA256.
 *
 * This is the anti-tamper foundation (fix #1): a worker can detect that a queued
 * envelope was modified in transit (or forged) before it deserialises and dispatches
 * the payload, preventing handler/argument injection on untrusted backends.
 *
 * Key resolution (constructor): explicit $key, then config('Jobs')->signingKey, then
 * env('JOBS_SIGNING_KEY'), then config('Encryption')->key. If none yields a non-empty
 * string the signer is "unconfigured" and operates in an insecure pass-through mode
 * (sign() returns '' and verify() returns true) which the worker logs as critical.
 */
final readonly class EnvelopeSigner
{
    private string $key;

    public function __construct(?string $key = null)
    {
        $this->key = $this->resolveKey($key);
    }

    /**
     * True when a non-empty signing key was resolved.
     */
    public function isConfigured(): bool
    {
        return $this->key !== '';
    }

    /**
     * Returns the hex HMAC-SHA256 of the given payload, or '' when no key is configured.
     */
    public function sign(string $payloadJson): string
    {
        if (! $this->isConfigured()) {
            return '';
        }

        return hash_hmac('sha256', $payloadJson, $this->key);
    }

    /**
     * Verifies a signature against the payload using a constant-time comparison.
     *
     * When unconfigured this returns true (insecure pass-through). When configured a
     * null or empty signature is rejected.
     */
    public function verify(string $payloadJson, ?string $signature): bool
    {
        if (! $this->isConfigured()) {
            return true;
        }

        if ($signature === null || $signature === '') {
            return false;
        }

        return hash_equals($this->sign($payloadJson), $signature);
    }

    /**
     * Resolves the signing key with strict null-coalescing semantics: an explicitly
     * provided string (even '') short-circuits the chain. Only a null explicit value
     * falls back to the Jobs config, then the JOBS_SIGNING_KEY environment variable and
     * finally the Encryption key. A resolved key of '' marks the signer as unconfigured.
     */
    private function resolveKey(?string $explicit): string
    {
        if ($explicit !== null) {
            return $explicit;
        }

        $configured = config('Jobs')->signingKey;
        if ($configured !== null) {
            return $configured;
        }

        $fromEnv = env('JOBS_SIGNING_KEY');
        if (is_string($fromEnv)) {
            return $fromEnv;
        }

        return config('Encryption')->key;
    }
}
