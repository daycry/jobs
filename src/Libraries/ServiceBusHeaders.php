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

use CodeIgniter\I18n\Time;
use DateTime;
use DateTimeZone;

/**
 * Utility builder for Azure Service Bus HTTP message headers.
 * Responsibilities:
 *  - Generate SAS authorization header (SharedAccessSignature)
 *  - Build BrokerProperties JSON (MessageId, Label, ScheduledEnqueueTimeUtc)
 *  - Provide immutable-style fluent API (returns self for chaining)
 *
 * Note: Scheduling uses a UNIX timestamp (UTC) for ScheduledEnqueueTimeUtc.
 */
class ServiceBusHeaders
{
    private array $brokerProperties = [];
    private string $authorization   = '';

    public function generateMessageId(?string $messageId = null): self
    {
        helper('text');
        $messageId                           = $messageId ?: random_string('alnum', 32);
        $messageId                           = getenv('MESSAGEID') ?: $messageId;
        $this->brokerProperties['MessageId'] = $messageId;

        return $this;
    }

    public function getMessageId(): string
    {
        return $this->brokerProperties['MessageId'] ?? '';
    }

    public function setLabel(string $label): self
    {
        $this->brokerProperties['Label'] = $label;

        return $this;
    }

    public function schedule(DateTime $datetime): self
    {
        $this->brokerProperties['ScheduledEnqueueTimeUtc'] = $datetime->setTimezone(new DateTimeZone('UTC'))->getTimestamp();

        return $this;
    }

    public function generateSasToken(string $uri, string $sasKeyName, string $sasKeyValue): self
    {
        $now                 = getenv('MOCK_TIME') ? Time::createFromTimestamp((int) getenv('MOCK_TIME')) : Time::now();
        $targetUri           = strtolower(rawurlencode(strtolower($uri)));
        $week                = 60 * 60 * 24 * 7;
        $expires             = $now->getTimestamp() + $week;
        $toSign              = $targetUri . "\n" . $expires;
        $signature           = rawurlencode(base64_encode(hash_hmac('sha256', $toSign, $sasKeyValue, true)));
        $this->authorization = 'SharedAccessSignature sr=' . $targetUri . '&sig=' . $signature . '&se=' . $expires . '&skn=' . $sasKeyName;

        return $this;
    }

    public function getHeaders(): array
    {
        $headers = [];
        if ($this->authorization) {
            $headers['Authorization'] = $this->authorization;
        }
        if (! empty($this->brokerProperties)) {
            $headers['BrokerProperties'] = json_encode($this->brokerProperties);
        }

        return $headers;
    }
}
