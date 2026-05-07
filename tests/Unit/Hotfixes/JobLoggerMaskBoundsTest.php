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

namespace Tests\Unit\Hotfixes;

use Daycry\Jobs\Loggers\JobLogger;
use ReflectionMethod;
use Tests\Support\TestCase;

/**
 * Hotfix F14/F15: ensures masking and token sanitization are bounded in depth and
 * that the API-key regex no longer flags arbitrary 32-char identifiers (UUIDs, hashes).
 *
 * @internal
 */
final class JobLoggerMaskBoundsTest extends TestCase
{
    private function invoke(JobLogger $logger, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($logger, $method);

        return $ref->invoke($logger, ...$args);
    }

    public function testMaskSensitiveStopsAtMaxDepth(): void
    {
        // Build a deeply nested array (15 levels, larger than the bound of 10).
        $deep  = 'sentinel';
        $value = $deep;

        for ($i = 0; $i < 15; $i++) {
            $value = ['nested' => $value];
        }

        $logger = new JobLogger();
        $masked = $this->invoke($logger, 'maskSensitive', [$value, ['password']]);

        // Walk down to the depth-10 bound; everything beyond must be replaced.
        $cursor = $masked;

        for ($i = 0; $i < 10; $i++) {
            $this->assertIsArray($cursor, "Level {$i} must remain an array up to MAX_MASK_DEPTH.");
            $this->assertArrayHasKey('nested', $cursor);
            $cursor = $cursor['nested'];
        }

        $this->assertSame('[truncated:max-depth]', $cursor, 'Beyond MAX_MASK_DEPTH the value must be replaced.');
    }

    public function testSanitizeTokenPatternsStopsAtMaxDepth(): void
    {
        $value = 'not-a-token-just-a-plain-string';

        for ($i = 0; $i < 15; $i++) {
            $value = ['nested' => $value];
        }

        $logger    = new JobLogger();
        $sanitized = $this->invoke($logger, 'sanitizeTokenPatterns', [$value, 0]);

        $cursor = $sanitized;

        for ($i = 0; $i < 10; $i++) {
            $this->assertIsArray($cursor);
            $cursor = $cursor['nested'];
        }
        $this->assertSame('[truncated:max-depth]', $cursor);
    }

    public function testApiKeyRegexDoesNotFlagUuidsOrShortHashes(): void
    {
        $logger = new JobLogger();

        $samples = [
            // Plain UUID v4 (36 chars including dashes; without dashes, 32 chars hex).
            '550e8400-e29b-41d4-a716-446655440000',
            // SHA-1 hex (40 chars) intentionally below the new 40-char minimum threshold? It is exactly 40.
            // We accept this is borderline; what we want to guarantee is that 32-char hex (UUID stripped)
            // is NOT replaced.
            '550e8400e29b41d4a716446655440000', // 32 chars
            // Random short identifier (16 chars) — must not be flagged.
            'abcdef1234567890',
        ];

        foreach ($samples as $sample) {
            $sanitized = $this->invoke($logger, 'sanitizeTokenPatterns', [$sample, 0]);
            $this->assertSame(
                $sample,
                $sanitized,
                "Sample '{$sample}' should NOT be flagged as API key (32-char IDs and short identifiers are legitimate).",
            );
        }
    }

    public function testApiKeyRegexFlagsKnownPrefixes(): void
    {
        $logger = new JobLogger();

        $stripeLive = $this->invoke($logger, 'sanitizeTokenPatterns', ['sk_live_abc123def456ghij7890', 0]);
        $this->assertSame('***API_KEY***', $stripeLive);

        $awsKey = $this->invoke($logger, 'sanitizeTokenPatterns', ['AKIAIOSFODNN7EXAMPLE', 0]);
        $this->assertSame('***API_KEY***', $awsKey);

        $githubPat = $this->invoke($logger, 'sanitizeTokenPatterns', ['ghp_' . str_repeat('A', 36), 0]);
        $this->assertSame('***API_KEY***', $githubPat);

        $longOpaque = $this->invoke($logger, 'sanitizeTokenPatterns', [str_repeat('a', 40), 0]);
        $this->assertSame('***API_KEY***', $longOpaque);
    }

    public function testJwtAndBearerStillMasked(): void
    {
        $logger = new JobLogger();

        $jwt = $this->invoke($logger, 'sanitizeTokenPatterns', ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjMifQ.signed', 0]);
        $this->assertSame('***JWT_TOKEN***', $jwt);

        // Use a Bearer token that does NOT match the JWT 3-part shape, so the Bearer
        // regex (last pass) can rewrite it cleanly.
        $bearer = $this->invoke($logger, 'sanitizeTokenPatterns', ['Bearer abcDEF12345xyz', 0]);
        $this->assertStringStartsWith('Bearer ***TOKEN***', (string) $bearer);
    }
}
