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

use Daycry\Jobs\Libraries\ServiceBusHeaders;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ServiceBusHeadersTest extends TestCase
{
    public function testGenerateMessageIdAndLabelAndSchedule(): void
    {
        $h = new ServiceBusHeaders();
        $h->generateMessageId();
        $id = $h->getMessageId();
        $this->assertNotEmpty($id);
        $h->setLabel('demo');
        $h->schedule(new DateTime('2030-01-02 03:04:05'));
        $headers = $h->getHeaders();
        $this->assertArrayHasKey('BrokerProperties', $headers);
        $this->assertStringContainsString('demo', $headers['BrokerProperties']);
        // ScheduledEnqueueTimeUtc se guarda como timestamp (UTC) -> validar aproximación
        $decoded = json_decode($headers['BrokerProperties'], true);
        $this->assertArrayHasKey('ScheduledEnqueueTimeUtc', $decoded);
        $this->assertIsInt($decoded['ScheduledEnqueueTimeUtc']);
        $this->assertGreaterThan(strtotime('2029-12-31'), $decoded['ScheduledEnqueueTimeUtc']);
    }

    public function testGenerateSasToken(): void
    {
        $h = new ServiceBusHeaders();
        $h->generateSasToken('https://example.servicebus.windows.net/q', 'issuer', 'c2VjcmV0'); // 'secret' base64 example
        $headers = $h->getHeaders();
        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertStringContainsString('SharedAccessSignature', $headers['Authorization']);
    }
}
