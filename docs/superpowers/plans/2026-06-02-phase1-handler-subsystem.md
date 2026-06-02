# Fase 1 — Subsistema de handlers desacoplados (Implementation Plan)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Crear el núcleo de ejecución desacoplado de v3.0 — handlers que reciben un `JobContext` inmutable e implementan `JobHandlerInterface` (sin extender el god-object `Job`), con seguridad endurecida (ShellHandler deny-by-default, EventHandler allowlist), resueltos por un `HandlerRegistry`.

**Architecture:** Capa `Handlers/` nueva, independiente del scheduling y de las colas. Cada handler es una clase pequeña con una responsabilidad (`handle(JobContext): mixed`). No toca V1 (los `Jobs/*` actuales siguen intactos hasta la fase de fachada). Produce software testeable por unidad sin runtime ni backends.

**Tech Stack:** PHP 8.2+, CodeIgniter 4, PHPUnit 10.5 (tests descubiertos por directorio en `tests/`, namespace `Tests\Unit\...`, base `CodeIgniter\Test\CIUnitTestCase`).

**Referencia spec:** `docs/superpowers/specs/2026-06-02-jobs-v3-architecture-design.md` (§4.2, §7). Cubre hallazgos del informe: #7 (ShellHandler deny-by-default), #10 (handlers desacoplados), #20 (EventHandler allowlist), #26 (firmas explícitas).

---

## File Structure

- Create `src/Execution/JobContext.php` — value object inmutable que reciben los handlers.
- Create `src/Handlers/JobHandlerInterface.php` — contrato `handle(JobContext): mixed`.
- Create `src/Handlers/AbstractJobHandler.php` — hooks `beforeRun`/`afterRun` no-op.
- Create `src/Handlers/ClosureHandler.php` — ejecuta un callable del payload.
- Create `src/Handlers/CommandHandler.php` — ejecuta un comando spark.
- Create `src/Handlers/ShellHandler.php` — ejecuta binario vía `proc_open` (argv), deny-by-default.
- Create `src/Handlers/UrlHandler.php` — HTTP con anti-SSRF y sin redirecciones.
- Create `src/Handlers/EventHandler.php` — dispara eventos CI4 con allowlist.
- Create `src/Handlers/HandlerRegistry.php` — resuelve key→handler desde config.
- Modify `src/Exceptions/JobException.php` — nuevas factories.
- Modify `src/Config/Jobs.php` — nuevas propiedades de config v3.
- Test: `tests/Unit/V3/Handlers/*Test.php`.

---

### Task 1: JobContext (value object inmutable)

**Files:**
- Create: `src/Execution/JobContext.php`
- Test: `tests/Unit/V3/Execution/JobContextTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Execution;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Execution\JobContext;

/**
 * @internal
 */
final class JobContextTest extends CIUnitTestCase
{
    public function testHoldsPayloadAndDefaults(): void
    {
        $ctx = new JobContext(payload: ['a' => 1]);

        $this->assertSame(['a' => 1], $ctx->payload);
        $this->assertNull($ctx->name);
        $this->assertNull($ctx->queue);
        $this->assertSame(1, $ctx->attempt);
        $this->assertSame([], $ctx->meta);
    }

    public function testFromPayloadFactory(): void
    {
        $ctx = JobContext::fromPayload('hello');
        $this->assertSame('hello', $ctx->payload);
        $this->assertSame(1, $ctx->attempt);
    }

    public function testCarriesMetadata(): void
    {
        $ctx = new JobContext(payload: null, name: 'job-a', queue: 'reports', attempt: 3, meta: ['k' => 'v']);
        $this->assertSame('job-a', $ctx->name);
        $this->assertSame('reports', $ctx->queue);
        $this->assertSame(3, $ctx->attempt);
        $this->assertSame(['k' => 'v'], $ctx->meta);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/V3/Execution/JobContextTest.php`
Expected: FAIL — `Class "Daycry\Jobs\Execution\JobContext" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Daycry\Jobs\Execution;

/**
 * Immutable execution context passed to a job handler.
 * Decouples handlers from the job definition/builder: a handler only ever sees
 * the payload it must process plus read-only metadata about the current run.
 */
final readonly class JobContext
{
    /**
     * @param array<string,mixed> $meta
     */
    public function __construct(
        public mixed $payload,
        public ?string $name = null,
        public ?string $queue = null,
        public int $attempt = 1,
        public array $meta = [],
    ) {
    }

    public static function fromPayload(mixed $payload): self
    {
        return new self(payload: $payload);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/V3/Execution/JobContextTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Execution/JobContext.php tests/Unit/V3/Execution/JobContextTest.php
git commit -m "feat(v3): add immutable JobContext value object"
```

---

### Task 2: JobHandlerInterface + AbstractJobHandler

**Files:**
- Create: `src/Handlers/JobHandlerInterface.php`
- Create: `src/Handlers/AbstractJobHandler.php`
- Test: `tests/Unit/V3/Handlers/AbstractJobHandlerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Handlers;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Execution\ExecutionResult;
use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Handlers\AbstractJobHandler;
use Daycry\Jobs\Handlers\JobHandlerInterface;

/**
 * @internal
 */
final class AbstractJobHandlerTest extends CIUnitTestCase
{
    public function testConcreteHandlerImplementsInterfaceAndDefaultHooksAreNoOps(): void
    {
        $handler = new class () extends AbstractJobHandler {
            public function handle(JobContext $ctx): mixed
            {
                return strtoupper((string) $ctx->payload);
            }
        };

        $this->assertInstanceOf(JobHandlerInterface::class, $handler);

        $ctx = JobContext::fromPayload('hi');
        // Hooks must not throw and must not alter behaviour.
        $handler->beforeRun($ctx);
        $this->assertSame('HI', $handler->handle($ctx));
        $handler->afterRun($ctx, new ExecutionResult(true, 'HI', null, 0.0, 0.0));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/V3/Handlers/AbstractJobHandlerTest.php`
Expected: FAIL — interface/class not found.

- [ ] **Step 3: Write minimal implementation**

`src/Handlers/JobHandlerInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Daycry\Jobs\Handlers;

use Daycry\Jobs\Execution\ExecutionResult;
use Daycry\Jobs\Execution\JobContext;

/**
 * Contract for v3 job handlers. A handler contains ONLY business logic; it does
 * not extend the job builder and never carries scheduling/queue state.
 */
interface JobHandlerInterface
{
    /**
     * Main business logic. Return value is normalized upstream (scalar/array -> string|json).
     * Throwing any Throwable signals failure to the runtime.
     */
    public function handle(JobContext $ctx): mixed;

    /** Optional hook before handle(). */
    public function beforeRun(JobContext $ctx): void;

    /** Optional hook after handle(), regardless of success. */
    public function afterRun(JobContext $ctx, ExecutionResult $result): void;
}
```

`src/Handlers/AbstractJobHandler.php`:

```php
<?php

declare(strict_types=1);

namespace Daycry\Jobs\Handlers;

use Daycry\Jobs\Execution\ExecutionResult;
use Daycry\Jobs\Execution\JobContext;

/**
 * Base handler providing no-op lifecycle hooks so concrete handlers only need handle().
 */
abstract class AbstractJobHandler implements JobHandlerInterface
{
    public function beforeRun(JobContext $ctx): void
    {
    }

    public function afterRun(JobContext $ctx, ExecutionResult $result): void
    {
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/V3/Handlers/AbstractJobHandlerTest.php`
Expected: PASS. (If `ExecutionResult`'s constructor signature differs, open `src/Execution/ExecutionResult.php` and match it — it is `new ExecutionResult(bool $success, mixed $output, ?string $error, float $startedAt, float $endedAt, ?string $handlerClass = null)`.)

- [ ] **Step 5: Commit**

```bash
git add src/Handlers/JobHandlerInterface.php src/Handlers/AbstractJobHandler.php tests/Unit/V3/Handlers/AbstractJobHandlerTest.php
git commit -m "feat(v3): add JobHandlerInterface + AbstractJobHandler (decoupled from Job)"
```

---

### Task 3: JobException factories nuevas

**Files:**
- Modify: `src/Exceptions/JobException.php`
- Test: `tests/Unit/V3/Handlers/JobExceptionFactoriesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Handlers;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Exceptions\JobException;

/**
 * @internal
 */
final class JobExceptionFactoriesTest extends CIUnitTestCase
{
    public function testShellCommandsNotConfigured(): void
    {
        $e = JobException::forShellCommandsNotConfigured();
        $this->assertStringContainsString('deny-by-default', $e->getMessage());
    }

    public function testEventNotAllowed(): void
    {
        $e = JobException::forEventNotAllowed('user.deleted');
        $this->assertStringContainsString('user.deleted', $e->getMessage());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/V3/Handlers/JobExceptionFactoriesTest.php`
Expected: FAIL — methods not defined.

- [ ] **Step 3: Add the factories** to `src/Exceptions/JobException.php` (after `forShellCommandNotAllowed`):

```php
    public static function forShellCommandsNotConfigured(): static
    {
        return new self(
            'ShellHandler is deny-by-default: configure Config\\Jobs::$allowedShellCommands '
            . 'with absolute paths, or set $allowAllShellCommands = true to opt out explicitly.',
        );
    }

    public static function forEventNotAllowed(string $event): static
    {
        return new self("Event '{$event}' is not in Config\\Jobs::\$allowedEvents.");
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/V3/Handlers/JobExceptionFactoriesTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Exceptions/JobException.php tests/Unit/V3/Handlers/JobExceptionFactoriesTest.php
git commit -m "feat(v3): add JobException factories for shell/event hardening"
```

---

### Task 4: Config\Jobs — propiedades v3

**Files:**
- Modify: `src/Config/Jobs.php`

Add these properties (keep existing ones for V1 compat; do NOT remove `$jobs` yet):

```php
    /**
     * v3 handler map (key => JobHandlerInterface class). Replaces $jobs.
     * Falls back to $jobs while the v1 facade exists.
     *
     * @var array<string, class-string>
     */
    public array $handlers = [
        'command' => \Daycry\Jobs\Handlers\CommandHandler::class,
        'shell'   => \Daycry\Jobs\Handlers\ShellHandler::class,
        'closure' => \Daycry\Jobs\Handlers\ClosureHandler::class,
        'event'   => \Daycry\Jobs\Handlers\EventHandler::class,
        'url'     => \Daycry\Jobs\Handlers\UrlHandler::class,
    ];

    /**
     * Per-queue allowlist of handler keys. A queue may only run the handlers listed here.
     * Empty array for a queue (or queue absent) = no restriction (set explicitly in production).
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
     * Explicit escape hatch to allow ANY shell command (insecure). Default false = deny-by-default.
     */
    public bool $allowAllShellCommands = false;
```

- [ ] **Step 1: Add the properties** (no test needed — exercised by handler tests below).
- [ ] **Step 2: Commit**

```bash
git add src/Config/Jobs.php
git commit -m "feat(v3): add handlers/queueHandlers/allowedEvents config"
```

---

### Task 5: ClosureHandler

**Files:**
- Create: `src/Handlers/ClosureHandler.php`
- Test: `tests/Unit/V3/Handlers/ClosureHandlerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Handlers;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Handlers\ClosureHandler;

/**
 * @internal
 */
final class ClosureHandlerTest extends CIUnitTestCase
{
    public function testInvokesCallablePayload(): void
    {
        $handler = new ClosureHandler();
        $result  = $handler->handle(JobContext::fromPayload(static fn () => 'ok'));
        $this->assertSame('ok', $result);
    }

    public function testRejectsNonCallable(): void
    {
        $this->expectException(JobException::class);
        (new ClosureHandler())->handle(JobContext::fromPayload('not-callable'));
    }
}
```

- [ ] **Step 2: Run** → FAIL (class not found).

- [ ] **Step 3: Implement** `src/Handlers/ClosureHandler.php`:

```php
<?php

declare(strict_types=1);

namespace Daycry\Jobs\Handlers;

use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;

/**
 * Executes a user-provided callable carried in the payload. Only works in-process
 * (Sync backend); closures do not survive serialization to a remote queue.
 */
final class ClosureHandler extends AbstractJobHandler
{
    public function handle(JobContext $ctx): mixed
    {
        $payload = $ctx->payload;
        if (! is_callable($payload)) {
            throw JobException::validationError('ClosureHandler payload must be callable.');
        }

        return $payload();
    }
}
```

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit**

```bash
git add src/Handlers/ClosureHandler.php tests/Unit/V3/Handlers/ClosureHandlerTest.php
git commit -m "feat(v3): add ClosureHandler"
```

---

### Task 6: CommandHandler

**Files:**
- Create: `src/Handlers/CommandHandler.php`
- Test: `tests/Unit/V3/Handlers/CommandHandlerTest.php`

- [ ] **Step 1: Write the failing test** (uses the existing test spark command `jobs:test`):

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Handlers;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Handlers\CommandHandler;

/**
 * @internal
 */
final class CommandHandlerTest extends CIUnitTestCase
{
    public function testRejectsEmptyOrNonString(): void
    {
        $this->expectException(JobException::class);
        (new CommandHandler())->handle(JobContext::fromPayload(''));
    }

    public function testRunsFrameworkCommand(): void
    {
        // command() returns the captured CLI output as a string; jobs:test is registered in tests/_support.
        $result = (new CommandHandler())->handle(JobContext::fromPayload('jobs:test'));
        $this->assertIsString($result);
    }
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement** `src/Handlers/CommandHandler.php`:

```php
<?php

declare(strict_types=1);

namespace Daycry\Jobs\Handlers;

use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;

/**
 * Executes a CodeIgniter spark command. The command string is trusted: callers must
 * gate untrusted queues via Config\Jobs::$queueHandlers so this handler is unreachable
 * from untrusted sources.
 */
final class CommandHandler extends AbstractJobHandler
{
    public function handle(JobContext $ctx): mixed
    {
        $payload = $ctx->payload;
        if (! is_string($payload) || $payload === '') {
            throw JobException::validationError('CommandHandler payload must be a non-empty string.');
        }

        return command($payload);
    }
}
```

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit**

```bash
git add src/Handlers/CommandHandler.php tests/Unit/V3/Handlers/CommandHandlerTest.php
git commit -m "feat(v3): add CommandHandler"
```

---

### Task 7: ShellHandler (deny-by-default, proc_open argv)

**Files:**
- Create: `src/Handlers/ShellHandler.php`
- Test: `tests/Unit/V3/Handlers/ShellHandlerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Handlers;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Handlers\ShellHandler;

/**
 * @internal
 */
final class ShellHandlerTest extends CIUnitTestCase
{
    private function config(): Jobs
    {
        return config(Jobs::class) ?? new Jobs();
    }

    public function testEmptyWhitelistDeniesByDefault(): void
    {
        $cfg                        = $this->config();
        $cfg->allowedShellCommands  = [];
        $cfg->allowAllShellCommands = false;

        $this->expectException(JobException::class);
        $this->expectExceptionMessage('deny-by-default');
        (new ShellHandler())->handle(JobContext::fromPayload('echo hi'));
    }

    public function testAllowAllEscapeHatchRuns(): void
    {
        $cfg                        = $this->config();
        $cfg->allowedShellCommands  = [];
        $cfg->allowAllShellCommands = true;

        $result = (new ShellHandler())->handle(JobContext::fromPayload(['echo', 'hi']));
        $this->assertIsArray($result);
        $this->assertContains('hi', $result);
    }

    public function testRejectsBinaryNotInAllowlist(): void
    {
        $cfg                       = $this->config();
        $cfg->allowedShellCommands = ['/usr/bin/echo'];
        $cfg->allowAllShellCommands = false;

        $this->expectException(JobException::class);
        (new ShellHandler())->handle(JobContext::fromPayload(['/bin/rm', '-rf', '/tmp/x']));
    }
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement** `src/Handlers/ShellHandler.php`. Uses `proc_open` with an **argv array** (no `/bin/sh -c`), and rejects when no allowlist and no explicit escape hatch. Accepts payload as `string` (split on whitespace) or `list<string>` (argv, preferred):

```php
<?php

declare(strict_types=1);

namespace Daycry\Jobs\Handlers;

use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;

/**
 * Executes an OS command via proc_open with an argv array (never through /bin/sh -c).
 * Deny-by-default: with an empty allowlist and $allowAllShellCommands=false, execution is refused.
 */
final class ShellHandler extends AbstractJobHandler
{
    public function handle(JobContext $ctx): mixed
    {
        $argv = $this->toArgv($ctx->payload);
        if ($argv === []) {
            throw JobException::validationError('ShellHandler payload must be a non-empty command.');
        }

        $this->authorize($argv[0]);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process     = proc_open($argv, $descriptors, $pipes);
        if (! is_resource($process)) {
            throw JobException::validationError('ShellHandler could not start the process.');
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        // Preserve v1-style array-of-lines return.
        return array_values(array_filter(explode("\n", str_replace("\r\n", "\n", $stdout)), static fn ($l) => $l !== ''));
    }

    /**
     * @return list<string>
     */
    private function toArgv(mixed $payload): array
    {
        if (is_array($payload)) {
            return array_values(array_map('strval', $payload));
        }
        if (is_string($payload)) {
            $parts = preg_split('/\s+/', trim($payload)) ?: [];

            return array_values(array_filter($parts, static fn ($p) => $p !== ''));
        }

        throw JobException::validationError('ShellHandler payload must be a string or an argv array.');
    }

    private function authorize(string $binary): void
    {
        $cfg     = config('Jobs');
        $allowed = $cfg->allowedShellCommands ?? [];

        if ($allowed === []) {
            if (($cfg->allowAllShellCommands ?? false) === true) {
                return;
            }

            throw JobException::forShellCommandsNotConfigured();
        }

        $candidate = realpath($binary) ?: $binary;
        foreach ($allowed as $entry) {
            $resolved = realpath((string) $entry) ?: (string) $entry;
            if ($candidate === $resolved) {
                return;
            }
        }

        throw JobException::forShellCommandNotAllowed($binary);
    }
}
```

- [ ] **Step 4: Run** → PASS. (On Windows CI the `echo` builtin is not a binary; the escape-hatch test passes an argv `['echo','hi']` which `proc_open` runs via the program if present. If the runner lacks a standalone `echo`, mark that single assertion environment-guarded with `if (! $this->commandExists('echo')) { $this->markTestSkipped(...); }` — but do NOT weaken the deny-by-default test.)
- [ ] **Step 5: Commit**

```bash
git add src/Handlers/ShellHandler.php tests/Unit/V3/Handlers/ShellHandlerTest.php
git commit -m "feat(v3): add ShellHandler (deny-by-default, proc_open argv)"
```

---

### Task 8: UrlHandler (anti-SSRF, sin redirecciones)

**Files:**
- Create: `src/Handlers/UrlHandler.php`
- Test: `tests/Unit/V3/Handlers/UrlHandlerTest.php`

Port the SSRF logic from `src/Jobs/UrlJob.php` (already solid) and add explicit `allow_redirects=false`. Keep payload shape `['method','url','options'?]`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Handlers;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Handlers\UrlHandler;

/**
 * @internal
 */
final class UrlHandlerTest extends CIUnitTestCase
{
    public function testRejectsNonArrayPayload(): void
    {
        $this->expectException(JobException::class);
        (new UrlHandler())->handle(JobContext::fromPayload('https://x'));
    }

    public function testRejectsDisallowedScheme(): void
    {
        $this->expectException(JobException::class);
        (new UrlHandler())->handle(JobContext::fromPayload(['method' => 'GET', 'url' => 'file:///etc/passwd']));
    }

    public function testRejectsInternalHost(): void
    {
        $this->expectException(JobException::class);
        (new UrlHandler())->handle(JobContext::fromPayload(['method' => 'GET', 'url' => 'http://127.0.0.1/']));
    }

    public function testRejectsInvalidMethod(): void
    {
        $this->expectException(JobException::class);
        (new UrlHandler())->handle(JobContext::fromPayload(['method' => 'CONNECT', 'url' => 'https://example.com/']));
    }
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement** `src/Handlers/UrlHandler.php` (copy SSRF guards verbatim from `UrlJob`, adapt to `JobContext`, force `allow_redirects=false`):

```php
<?php

declare(strict_types=1);

namespace Daycry\Jobs\Handlers;

use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;

/**
 * Performs an HTTP request via CI4 curlrequest. SSRF-hardened: scheme allowlist,
 * private/reserved IP rejection (resolving A/AAAA), SSL verification forced on,
 * and redirects disabled.
 */
final class UrlHandler extends AbstractJobHandler
{
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
    private const ALLOWED_SCHEMES = ['http', 'https'];

    public function handle(JobContext $ctx): mixed
    {
        $payload = $ctx->payload;
        $this->validate($payload);

        $options = $payload['options'] ?? [];
        unset($options['verify'], $options[CURLOPT_SSL_VERIFYPEER], $options[CURLOPT_SSL_VERIFYHOST]);
        $options['allow_redirects'] = false; // SSRF: never follow redirects to internal targets

        return service('curlrequest')->request($payload['method'], $payload['url'], $options)->getBody();
    }

    private function validate(mixed $payload): void
    {
        if (! is_array($payload)) {
            throw JobException::validationError('UrlHandler payload must be an array with method and url keys.');
        }
        if (empty($payload['url']) || ! is_string($payload['url'])) {
            throw JobException::validationError('UrlHandler payload must contain a valid url string.');
        }
        if (empty($payload['method']) || ! is_string($payload['method'])) {
            throw JobException::validationError('UrlHandler payload must contain a valid method string.');
        }

        $method = strtoupper($payload['method']);
        if (! in_array($method, self::ALLOWED_METHODS, true)) {
            throw JobException::forInvalidMethod($method);
        }
        if (filter_var($payload['url'], FILTER_VALIDATE_URL) === false) {
            throw JobException::validationError('UrlHandler payload contains an invalid URL.');
        }

        $this->blockInternalUrls($payload['url']);
    }

    private function blockInternalUrls(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false) {
            throw JobException::validationError('UrlHandler could not parse URL.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw JobException::validationError("UrlHandler scheme '{$scheme}' is not allowed (only http/https).");
        }

        $host = $parts['host'] ?? null;
        if (! is_string($host) || $host === '') {
            throw JobException::validationError('UrlHandler could not parse host from URL.');
        }

        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP)) {
            $this->validatePublicIp($literal);

            return;
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records) || $records === []) {
            $ip = gethostbyname($host);
            if ($ip === $host || ! filter_var($ip, FILTER_VALIDATE_IP)) {
                throw JobException::validationError("UrlHandler could not resolve host '{$host}'.");
            }
            $this->validatePublicIp($ip);

            return;
        }

        foreach ($records as $rec) {
            $ip = $rec['ip'] ?? $rec['ipv6'] ?? null;
            if (is_string($ip) && $ip !== '') {
                $this->validatePublicIp($ip);
            }
        }
    }

    private function validatePublicIp(string $ip): void
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw JobException::validationError("UrlHandler does not allow requests to internal IP '{$ip}'.");
        }
    }
}
```

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit**

```bash
git add src/Handlers/UrlHandler.php tests/Unit/V3/Handlers/UrlHandlerTest.php
git commit -m "feat(v3): add UrlHandler (SSRF-hardened, redirects disabled)"
```

---

### Task 9: EventHandler (allowlist)

**Files:**
- Create: `src/Handlers/EventHandler.php`
- Test: `tests/Unit/V3/Handlers/EventHandlerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Handlers;

use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Handlers\EventHandler;

/**
 * @internal
 */
final class EventHandlerTest extends CIUnitTestCase
{
    public function testDeniesEventNotInAllowlist(): void
    {
        (config(Jobs::class) ?? new Jobs())->allowedEvents = [];

        $this->expectException(JobException::class);
        (new EventHandler())->handle(JobContext::fromPayload(['name' => 'user.deleted']));
    }

    public function testTriggersAllowedEvent(): void
    {
        (config(Jobs::class) ?? new Jobs())->allowedEvents = ['jobs.test.event'];

        $hit = false;
        Events::on('jobs.test.event', static function () use (&$hit): void { $hit = true; });

        (new EventHandler())->handle(JobContext::fromPayload(['name' => 'jobs.test.event', 'data' => []]));
        $this->assertTrue($hit);
    }
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement** `src/Handlers/EventHandler.php`:

```php
<?php

declare(strict_types=1);

namespace Daycry\Jobs\Handlers;

use CodeIgniter\Events\Events;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;

/**
 * Triggers a CodeIgniter event named in the payload. Secure-by-default: only events
 * listed in Config\Jobs::$allowedEvents may be fired.
 */
final class EventHandler extends AbstractJobHandler
{
    public function handle(JobContext $ctx): mixed
    {
        $payload = $ctx->payload;
        if (! is_array($payload) || empty($payload['name']) || ! is_string($payload['name'])) {
            throw JobException::validationError('EventHandler payload must be an array with a string "name".');
        }

        $allowed = config('Jobs')->allowedEvents ?? [];
        if (! in_array($payload['name'], $allowed, true)) {
            throw JobException::forEventNotAllowed($payload['name']);
        }

        $data = $payload['data'] ?? [];

        return Events::trigger($payload['name'], is_array($data) ? $data : [$data]);
    }
}
```

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit**

```bash
git add src/Handlers/EventHandler.php tests/Unit/V3/Handlers/EventHandlerTest.php
git commit -m "feat(v3): add EventHandler (event allowlist)"
```

---

### Task 10: HandlerRegistry

**Files:**
- Create: `src/Handlers/HandlerRegistry.php`
- Test: `tests/Unit/V3/Handlers/HandlerRegistryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Handlers;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Handlers\CommandHandler;
use Daycry\Jobs\Handlers\HandlerRegistry;
use Daycry\Jobs\Handlers\JobHandlerInterface;

/**
 * @internal
 */
final class HandlerRegistryTest extends CIUnitTestCase
{
    public function testResolvesConfiguredHandler(): void
    {
        $registry = new HandlerRegistry(config(Jobs::class) ?? new Jobs());
        $handler  = $registry->resolve('command');
        $this->assertInstanceOf(CommandHandler::class, $handler);
        $this->assertInstanceOf(JobHandlerInterface::class, $handler);
    }

    public function testThrowsForUnknownKey(): void
    {
        $this->expectException(JobException::class);
        (new HandlerRegistry(config(Jobs::class) ?? new Jobs()))->resolve('does-not-exist');
    }

    public function testEnforcesPerQueueAllowlist(): void
    {
        $cfg                = config(Jobs::class) ?? new Jobs();
        $cfg->queueHandlers = ['reports' => ['command']];

        $registry = new HandlerRegistry($cfg);
        // 'shell' is not allowed on the 'reports' queue.
        $this->expectException(JobException::class);
        $registry->resolveForQueue('shell', 'reports');
    }
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement** `src/Handlers/HandlerRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace Daycry\Jobs\Handlers;

use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Exceptions\JobException;

/**
 * Resolves a handler key (e.g. 'command') to a JobHandlerInterface instance, using
 * Config\Jobs::$handlers (falling back to legacy $jobs). Enforces the per-queue
 * handler allowlist (Config\Jobs::$queueHandlers).
 */
final class HandlerRegistry
{
    /** @var array<string, class-string> */
    private array $map;

    /** @var array<string, list<string>> */
    private array $queueHandlers;

    public function __construct(Jobs $config)
    {
        // Prefer v3 $handlers; fall back to legacy $jobs while the v1 facade exists.
        $this->map           = ! empty($config->handlers) ? $config->handlers : ($config->jobs ?? []);
        $this->queueHandlers = $config->queueHandlers ?? [];
    }

    public function resolve(string $key): JobHandlerInterface
    {
        $class = $this->map[$key] ?? null;
        if ($class === null || ! is_a($class, JobHandlerInterface::class, true)) {
            throw JobException::forInvalidJob($key);
        }

        return new $class();
    }

    public function resolveForQueue(string $key, string $queue): JobHandlerInterface
    {
        $allowed = $this->queueHandlers[$queue] ?? null;
        if ($allowed !== null && ! in_array($key, $allowed, true)) {
            throw JobException::validationError("Handler '{$key}' is not allowed on queue '{$queue}'.");
        }

        return $this->resolve($key);
    }
}
```

> Note: in v3 the `$config->jobs` fallback maps to legacy handlers that still `extends Job`
> (which are NOT `JobHandlerInterface`), so `resolve()` of a legacy key will throw. That is
> intentional: the registry is the v3 path; the v1 facade keeps using its own resolution until
> Phase 6 swaps the facade's handlers to delegate here.

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit**

```bash
git add src/Handlers/HandlerRegistry.php tests/Unit/V3/Handlers/HandlerRegistryTest.php
git commit -m "feat(v3): add HandlerRegistry with per-queue allowlist"
```

---

### Task 11: Full suite + static analysis green

- [ ] **Step 1:** Run the whole suite to ensure no regression in V1.

Run: `vendor/bin/phpunit`
Expected: PASS (existing tests untouched; new V3 tests green).

- [ ] **Step 2:** Static analysis on the new files.

Run: `vendor/bin/phpstan analyze src/Handlers src/Execution/JobContext.php`
Expected: no new errors. If the project's baseline rejects new files, run from `composer analyze`. Fix any `missingType.*` by adding the array shapes already documented in the docblocks above.

- [ ] **Step 3: Commit** any analysis fixes.

```bash
git add -A
git commit -m "chore(v3): phase 1 static analysis green"
```

---

## Self-Review

- **Spec coverage (Fase 1 scope):** §4.2 handler interface/registry/context ✔ (T1,T2,T10); §7 ShellHandler deny-by-default ✔ (T7), EventHandler allowlist ✔ (T9), UrlHandler redirects off ✔ (T8); per-queue allowlist ✔ (T10). Findings #7,#10,#20 addressed; #1/#23/#5 etc. belong to later phases (runtime/signing) — out of this phase by design.
- **Placeholder scan:** none — every step has complete code.
- **Type consistency:** `JobContext` props (`payload/name/queue/attempt/meta`) used consistently; `JobHandlerInterface::handle(JobContext): mixed` matches all handlers; `HandlerRegistry::resolve/resolveForQueue` names consistent; `ExecutionResult` constructor referenced as defined in `src/Execution/ExecutionResult.php` (verify signature in T2 Step 4).
- **Out of scope (next plans):** JobBuilder→JobDefinition + frequency helpers; backends/QueueBackend; runtime/retry/timeout/idempotency; signing; worker/cron commands; V1 deprecated facade; docs.
