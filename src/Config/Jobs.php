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

namespace Daycry\Jobs\Config;

use CodeIgniter\Config\BaseConfig;
use Daycry\Jobs\Cron\Scheduler;
use Daycry\Jobs\Handlers\ClosureHandler;
use Daycry\Jobs\Handlers\CommandHandler;
use Daycry\Jobs\Handlers\EventHandler;
use Daycry\Jobs\Handlers\ShellHandler;
use Daycry\Jobs\Handlers\UrlHandler;
use Daycry\Jobs\Metrics\InMemoryMetricsCollector;
use Daycry\Jobs\Queues\Backends\BeanstalkBackend;
use Daycry\Jobs\Queues\Backends\DatabaseBackend;
use Daycry\Jobs\Queues\Backends\RedisBackend;
use Daycry\Jobs\Queues\Backends\ServiceBusBackend;
use Daycry\Jobs\Queues\Backends\SyncBackend;

/**
 * Central configuration for Jobs package (scheduling, queues, retries).
 * Key groups:
 *  - handlers: handler-key mapping (key -> JobHandlerInterface class)
 *  - queueHandlers: per-queue handler allowlist
 *  - retries: backoff strategy & parameters (strategy/base/multiplier/jitter/max)
 *  - queues: available queues, default worker, backend map and backend-specific settings
 *  - init(): optional bootstrap registering scheduled jobs on the v3 Scheduler
 */
class Jobs extends BaseConfig
{
    /**
     * v3 handler map (key => JobHandlerInterface class). This is the single source of truth
     * for resolving handler keys; the v3 HandlerRegistry reads exclusively from here.
     *
     * @var array<string, class-string>
     */
    public array $handlers = [
        'command' => CommandHandler::class,
        'shell'   => ShellHandler::class,
        'closure' => ClosureHandler::class,
        'event'   => EventHandler::class,
        'url'     => UrlHandler::class,
    ];

    /**
     * Per-queue allowlist of handler keys. A queue may only run the handlers listed here.
     * A queue absent from this map (or with an empty list) imposes no restriction — set it
     * explicitly in production so remote queues cannot invoke 'shell'/'command'.
     * Example: ['reports' => ['command'], 'web' => ['url', 'event']]
     *
     * @var array<string, list<string>>
     */
    public array $queueHandlers = [];

    /**
     * Allowlist of event names EventHandler may trigger. Empty = deny all (secure default).
     *
     * @var list<string>
     */
    public array $allowedEvents = [];

    /**
     * Explicit escape hatch to allow ANY shell command (insecure).
     * Default false = deny-by-default: an empty $allowedShellCommands rejects execution.
     */
    public bool $allowAllShellCommands = false;

    /**
     * --------------------------------------------------------------------------
     * Envelope Signing (anti-tamper / anti-RCE)
     * --------------------------------------------------------------------------
     * HMAC-SHA256 key used to sign queue envelopes. When null, the signer falls back to
     * env('JOBS_SIGNING_KEY') and then to the CodeIgniter Encryption key. If no key can be
     * resolved, signing/verification operate in insecure mode (logged as critical).
     */
    public ?string $signingKey = null;

    /**
     * When true, the worker rejects queue messages whose HMAC signature is missing or invalid
     * (provided a signing key is available). Set false only for trusted, private backends.
     */
    public bool $verifyEnvelopeSignature = true;

    /**
     * Default TTL (seconds) for idempotency keys stored by IdempotencyGuard.
     */
    public int $idempotencyTtl = 86400;

    /**
     * Database connection group used by the Jobs migrations (null = default group).
     */
    public ?string $databaseGroup = null;

    /**
     * Name of the table created by the Jobs history migration.
     */
    public string $tableName = 'jobs';

    /**
     * --------------------------------------------------------------------------
     * Shell Command Whitelist
     * --------------------------------------------------------------------------
     * List of allowed shell commands. Empty array allows all (backward compatible).
     * When populated, only listed commands are permitted in ShellJob.
     * Example: ['ls', 'grep', 'cat', 'find']
     *
     * @var list<string>
     */
    public array $allowedShellCommands = [];

    /**
     * --------------------------------------------------------------------------
     * Rate Limiting
     * --------------------------------------------------------------------------
     * Maximum jobs processed per minute per queue. 0 = unlimited.
     * Example: ['high_priority' => 100, 'default' => 50]
     *
     * @var array<string, int>
     */
    public array $queueRateLimits = [];

    /**
     * --------------------------------------------------------------------------
     * Dead Letter Queue
     * --------------------------------------------------------------------------
     * Queue name where failed jobs (after max_retries) are moved.
     * Null disables DLQ functionality.
     */
    public ?string $deadLetterQueue = null;

    /**
     * --------------------------------------------------------------------------
     * Job Timeout
     * --------------------------------------------------------------------------
     * Maximum execution time per job in seconds. 0 = unlimited.
     * Can be overridden per-job.
     */
    public int $jobTimeout = 300; // 5 minutes

    /**
     * --------------------------------------------------------------------------
     * Poll Interval
     * --------------------------------------------------------------------------
     * Seconds to sleep between queue polling cycles when no job is available.
     * Ignored when blockingFetch is enabled and the active backend supports
     * blocking reads (Redis BRPOPLPUSH or Beanstalk reserve_with_timeout).
     */
    public int $pollInterval = 5;

    /**
     * --------------------------------------------------------------------------
     * Blocking Fetch (opt-in)
     * --------------------------------------------------------------------------
     * When true the worker uses blocking reads on backends that support them
     * (Redis BRPOPLPUSH; Beanstalk reserve_with_timeout) instead of polling.
     * The blockingFetchTimeout is the seconds to wait per fetch (also acts as
     * the upper bound for graceful shutdown latency).
     */
    public bool $blockingFetch = false;

    public int $blockingFetchTimeout = 5;

    /**
     * --------------------------------------------------------------------------
     * Redis Reliable Queue
     * --------------------------------------------------------------------------
     * Visibility timeout (seconds) used by the redis backend reaper (jobs:queue:reap)
     * to decide when an in-flight job left in the processing list belongs to a crashed
     * worker and must be returned to the waiting list for retry.
     */
    public int $redisProcessingVisibilityTimeout = 300;

    /**
     * --------------------------------------------------------------------------
     * Database Reliable Queue
     * --------------------------------------------------------------------------
     * Visibility timeout (seconds) used by the database backend reaper to decide when a
     * row left 'in_progress' belongs to a crashed worker and must be returned to 'pending'.
     * Must be greater than the maximum expected job runtime to avoid reclaiming live jobs.
     */
    public int $databaseVisibilityTimeout = 300;

    /**
     * --------------------------------------------------------------------------
     * Service Bus
     * --------------------------------------------------------------------------
     * Lock timeout (seconds) requested when peek-locking messages.
     * Must be >= the maximum job runtime, otherwise the broker may redeliver
     * the message to another worker mid-execution.
     */
    public int $serviceBusLockTimeout = 60;

    /**
     * --------------------------------------------------------------------------
     * Default Timeout (seconds)
     * --------------------------------------------------------------------------
     * A default execution timeout applied to jobs that do not explicitly
     * define one. Null disables global timeout.
     */
    public ?int $defaultTimeout = null; // in seconds e.g. 2

    /**
     * --------------------------------------------------------------------------
     * Circuit Breaker
     * --------------------------------------------------------------------------
     * Protects queue workers from hammering unavailable backends.
     * After $circuitBreakerThreshold consecutive failures, the circuit opens
     * and skips attempts for $circuitBreakerCooldown seconds.
     */
    public int $circuitBreakerThreshold = 5;

    public int $circuitBreakerCooldown = 60;

    /**
     * Backoff strategy for retries: 'none', 'fixed', 'exponential'
     */
    public string $retryBackoffStrategy = 'none';

    /**
     * Base seconds used when computing backoff delay (first retry delay).
     */
    public int $retryBackoffBase = 5;

    /**
     * Multiplier used for exponential strategy (delay = base * multiplier^(attempt-1)).
     */
    public float $retryBackoffMultiplier = 2.0;

    /**
     * Maximum delay cap (seconds) for any retry.
     */
    public int $retryBackoffMax = 300;

    /**
     * Whether to add +/- random jitter up to 15% of computed delay.
     */
    public bool $retryBackoffJitter = true;

    /**
     * @var list<string>|string
     */
    public array|string $queues = 'default,dummy';

    public string $worker = 'sync';

    /**
     * Fully-qualified class name of the metrics collector to use.
     * Must implement Daycry\Jobs\Metrics\MetricsCollectorInterface.
     * Null disables metrics collection (all increment/observe calls no-op).
     * Default uses the in-memory collector which is fine for local/dev but not for production scraping.
     */
    public ?string $metricsCollector = InMemoryMetricsCollector::class;

    /**
     * @var array{group: string|null, table: string}
     */
    public array $database = [
        'group' => null,
        'table' => 'queues',
    ];

    /**
     * Azure Service Bus basic config (usada por ServiceBusQueue)
     *
     * @var array{url: string, issuer: string, secret: string}
     */
    public array $serviceBus = [
        'url'    => '', // e.g. https://<namespace>.servicebus.windows.net/<queue>
        'issuer' => '', // SAS key name
        'secret' => '', // SAS key value — prefer env('SERVICEBUS_SECRET')
    ];

    /**
     * @var array{host: string, port: int}
     */
    public array $beanstalk = [
        'host' => '127.0.0.1',
        'port' => 11300,
    ];

    /**
     * v3 backend map (name => QueueBackend class). Single source of truth for the v3 worker
     * (jobs:queue:work), the cron runner and BackendFactory.
     *
     * @var array<string, class-string>
     */
    public array $backends = [
        'sync'       => SyncBackend::class,
        'database'   => DatabaseBackend::class,
        'redis'      => RedisBackend::class,
        'beanstalk'  => BeanstalkBackend::class,
        'serviceBus' => ServiceBusBackend::class,
    ];

    /**
     * Register scheduled jobs on the v3 {@see Scheduler}. Called by jobs:cronjob:run before
     * the cron runner evaluates due definitions. Each define() returns a fluent JobBuilder.
     *
     * Examples (uncomment and adapt in your application config):
     */
    public function init(Scheduler $scheduler): void
    {
        // $scheduler->define('command', 'jobs:test')->named('reports')->dailyAt('02:00')->queue('reports');
        // $scheduler->define('shell', 'ls')->named('shell_test')->everyMinute()->singleInstance();
        // $scheduler->define('closure', static fn () => 'done')->named('closure_test')->everyMinute();
        // $scheduler->define('event', ['name' => 'user.registered', 'data' => ['user_id' => 123]])->named('event_test')->hourly();
        // $scheduler->define('url', ['url' => 'https://example.com', 'method' => 'GET'])->named('ping')->everyMinute()->disable();
    }
}
