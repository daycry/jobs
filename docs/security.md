# Security Model

`daycry/jobs` runs arbitrary application work in response to queue messages. Because a queue
message tells the worker *which handler to invoke and with what arguments*, the queue is part of
your trust boundary. v3 was redesigned around that fact: every layer that can turn a message into
code execution is locked down by default. This page describes each control, what it protects
against, and how the pieces compose into a defensible deployment.

The controls are:

- **Envelope signing** (HMAC-SHA256) — detect tampered or forged messages before they are dispatched.
- **Per-queue handler allowlist** — restrict which handler keys a given queue may invoke.
- **ShellHandler deny-by-default** — refuse to run OS commands unless explicitly allowed.
- **EventHandler allowlist** — refuse to fire application events unless explicitly allowed.
- **UrlHandler SSRF hardening** — block requests to internal/private targets.

See [Configuration](CONFIGURATION.md) for the property reference and [Concurrency & Resilience](concurrency.md)
for the locking/rate-limiting controls.

## Envelope signing (anti-tamper / anti-RCE)

Every message that is enqueued to a persistent backend is serialised into a canonical *wire*
object and signed with HMAC-SHA256. The worker verifies the signature **after** fetching and
**before** it deserialises the payload and resolves a handler. A message that fails verification
is abandoned (not retried) and a `critical` line is logged.

The relevant classes are `Daycry\Jobs\Queues\Signing\EnvelopeSigner` (sign/verify) and
`Daycry\Jobs\Queues\EnvelopeFactory` (builds the wire object and computes the canonical JSON).

### Key resolution chain

`EnvelopeSigner::__construct(?string $key = null)` resolves the signing key in strict order. An
explicitly provided string short-circuits the chain (even an empty string, which disables signing):

| Order | Source | Notes |
|-------|--------|-------|
| 1 | Constructor `$key` argument | Only when not `null`. An explicit `''` marks the signer *unconfigured*. |
| 2 | `Config\Jobs::$signingKey` | Used when not `null`. |
| 3 | `env('JOBS_SIGNING_KEY')` | Used when the env var is a string. |
| 4 | `config('Encryption')->key` | Final fallback — the CodeIgniter Encryption key. |

```php
// Recommended: a dedicated key in the environment.
// .env
JOBS_SIGNING_KEY = "base64:Zm9vYmFyYmF6..."   // any sufficiently random secret string
```

```php
// Or set it explicitly in app/Config/Jobs.php
public ?string $signingKey = null; // null -> falls back to JOBS_SIGNING_KEY, then Encryption key
```

> **Warning:** If no non-empty key can be resolved the signer is *unconfigured*: `sign()` returns
> `''` and `verify()` returns `true` (insecure pass-through). The worker only rejects messages when
> `verifyEnvelopeSignature` is `true` **and** the signer reports `isConfigured() === true`. In
> production always set `JOBS_SIGNING_KEY` (or a non-empty Encryption key) so signing is active.

### What is signed

The signature covers only the **immutable identity fields**. `EnvelopeFactory::canonicalJson()`
produces deterministic JSON over exactly these keys:

| Field | Signed | Reason |
|-------|--------|--------|
| `job` | yes | The handler key — the field that decides which code runs. |
| `payload` | yes | The handler arguments. |
| `queue` | yes | Binds the message to a queue (and thus its allowlist). |
| `priority` | yes | Part of the immutable identity. |
| `maxRetries` | yes | Retry budget is fixed at enqueue time. |
| `name` | yes | Logical identity used for locks/logs. |
| `identifier` | yes | Per-enqueue random id. |
| `attempts` | **no** | Mutable — incremented on every requeue. |
| `schedule` | **no** | Mutable — rewritten when a delayed requeue is scheduled. |
| `_sig` | **no** | The signature itself. |

Excluding `attempts` and `schedule` is deliberate: a backend such as Redis re-serialises the
message with `attempts + 1` on each `nack()`, so a signature over those fields would break after the
first requeue. Because the fields that select code (`job`, `payload`, `queue`) *are* signed, a
tamper attempt that changes the handler or its arguments is still detected.

### Verification and rejection

`QueueWorker::processOnce()` performs the check:

```php
$signer = $this->signer ?? new EnvelopeSigner();
if ($this->config->verifyEnvelopeSignature && $signer->isConfigured()) {
    $signature = isset($wire->_sig) && is_string($wire->_sig) ? $wire->_sig : null;
    if (! $signer->verify(EnvelopeFactory::canonicalJson($wire), $signature)) {
        // metric jobs_rejected_signature, critical log, then:
        $this->backend->abandon($lease);
        return new WorkerResult('rejected', error: 'invalid signature');
    }
}
```

Properties of the check:

- `verify()` uses `hash_equals()` (constant-time comparison) to avoid timing oracles.
- A configured signer rejects a `null` or empty signature.
- A rejected message is **abandoned, not retried** — a forged message cannot consume the retry
  budget or loop.

> **Note:** Setting `verifyEnvelopeSignature = false` disables the rejection path entirely. Only do
> this for a fully trusted, private backend where no untrusted party can write to the queue.

## Per-queue handler allowlist

`Config\Jobs::$queueHandlers` maps a queue name to the list of handler keys that queue may run.
`HandlerRegistry::resolveForQueue($key, $queue)` enforces it inside `JobRuntime` before the handler
is instantiated:

```php
public function resolveForQueue(string $key, string $queue): JobHandlerInterface
{
    $allowed = $this->queueHandlers[$queue] ?? null;
    if ($allowed !== null && ! in_array($key, $allowed, true)) {
        throw JobException::validationError("Handler '{$key}' is not allowed on queue '{$queue}'.");
    }
    return $this->resolve($key);
}
```

Rules:

- A queue **absent** from `$queueHandlers` (or mapped to an empty list) imposes **no restriction**.
- A queue **present** in the map may run **only** the keys it lists; everything else is a validation
  error recorded as a failed attempt.

```php
// app/Config/Jobs.php — lock down public-facing queues.
public array $queueHandlers = [
    'web'     => ['url', 'event'],   // a web-triggered queue can never run 'shell' or 'command'
    'reports' => ['command'],
    // 'internal' is intentionally absent -> trusted, no restriction
];
```

> **Warning:** A queue that any untrusted producer can write to should **always** appear in
> `$queueHandlers` with the minimum set of handlers. Otherwise a forged (but, if you disabled
> signing, accepted) message could request the `shell` or `command` handler.

## ShellHandler — deny-by-default

`ShellHandler` executes OS commands through `proc_open()` with an **argv array**, never through
`/bin/sh -c`. Passing an argv array means shell metacharacters (`;`, `|`, `$()`, backticks) are
treated as literal arguments, so the classic shell-injection surface does not exist.

On top of that, execution is **deny-by-default**:

```php
private function authorize(string $binary): void
{
    $cfg     = config('Jobs');
    $allowed = $cfg->allowedShellCommands ?? [];

    if ($allowed === []) {
        if (($cfg->allowAllShellCommands ?? false) === true) {
            return; // explicit opt-out
        }
        throw JobException::forShellCommandsNotConfigured(); // refuse
    }

    $candidate = realpath($binary) ?: $binary;
    foreach ($allowed as $entry) {
        $resolved = realpath((string) $entry) ?: (string) $entry;
        if ($candidate === $resolved) {
            return; // allowed
        }
    }
    throw JobException::forShellCommandNotAllowed($binary);
}
```

| Config | Behaviour |
|--------|-----------|
| `$allowedShellCommands = []` and `$allowAllShellCommands = false` (defaults) | Refuse all execution (`forShellCommandsNotConfigured`). |
| `$allowedShellCommands = ['/usr/bin/git', ...]` | Only the listed binaries run; matched via `realpath()`. |
| `$allowAllShellCommands = true` | Insecure escape hatch: any binary runs even with an empty allowlist. |

```php
// Recommended: pin absolute paths.
public array $allowedShellCommands = ['/usr/bin/git', '/usr/bin/rsync'];
```

> **Note:** Allowlist entries are compared by `realpath()`, so a dropped `/tmp/git` cannot
> impersonate `/usr/bin/git`. Prefer absolute paths; relative names rely on `realpath()` resolving
> against the current working directory.

> **Warning:** Never combine `$allowAllShellCommands = true` with a queue that untrusted producers
> can reach. If you must enable it, gate the `shell` key behind `$queueHandlers` for trusted queues
> only.

## EventHandler — allowlist

`EventHandler` triggers a CodeIgniter event named in the payload, but only if the name appears in
`Config\Jobs::$allowedEvents`. An empty allowlist denies everything:

```php
$allowed = config('Jobs')->allowedEvents ?? [];
if (! in_array($payload['name'], $allowed, true)) {
    throw JobException::forEventNotAllowed($payload['name']);
}
return Events::trigger($payload['name'], is_array($data) ? $data : [$data]);
```

```php
public array $allowedEvents = ['user.registered', 'cache.warm'];
```

This prevents a tampered or attacker-controlled message from dispatching arbitrary internal events
(which could trigger sensitive listeners).

## UrlHandler — SSRF hardening

`UrlHandler` performs an outbound HTTP request via the `curlrequest` service. Server-Side Request
Forgery is mitigated with several layers:

| Control | Implementation |
|---------|----------------|
| Method allowlist | Only `GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS` (`forInvalidMethod` otherwise). |
| Scheme allowlist | Only `http` / `https`. |
| URL validation | `filter_var(..., FILTER_VALIDATE_URL)`. |
| Private/reserved IP block | Resolves **all** A and AAAA records; rejects if **any** is private/reserved (`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`). |
| IPv6 literals | Bracketed hosts (`http://[::1]/`) are unwrapped and validated. |
| Forced TLS verification | `verify`, `CURLOPT_SSL_VERIFYPEER`, `CURLOPT_SSL_VERIFYHOST` are stripped from caller options. |
| No redirects | `allow_redirects = false` so a `3xx` cannot bounce the request to an internal host. |

```php
use Daycry\Jobs\Jobs;

Jobs::define('url', [
    'method'  => 'POST',
    'url'     => 'https://api.example.com/webhook',
    'options' => ['json' => ['event' => 'ping']],
])->queue('web')->dispatch();
```

> **Warning (residual risk — DNS rebinding):** The handler validates the resolved IPs at *validation*
> time, but cURL re-resolves the hostname at *request* time. A hostile DNS server can return a public
> IP during validation and a private IP at request time. The package does not fully mitigate this; if
> you call sensitive internal hosts, pin DNS resolution (e.g. `CURLOPT_RESOLVE`) or front the request
> with an egress proxy that enforces an allowlist.

## Threat model

### Trust boundary

The worker treats the **queue contents as untrusted input**. A message names a handler key and
carries a payload; if either can be controlled by an attacker, they influence what code runs.

### What if the queue backend is compromised?

Assume an attacker can read and write arbitrary messages on your Redis/database/broker:

| Attacker capability | Mitigation |
|---------------------|------------|
| Forge a message that runs `shell`/`command` with attacker arguments | **Signing** rejects the message (no valid HMAC). Even without signing, **`$queueHandlers`** prevents the key from running on a locked-down queue, and **ShellHandler deny-by-default** refuses unconfigured binaries. |
| Tamper with an existing message's payload/handler in transit | **Signing** detects the change (the identity fields are covered) and the worker abandons it. |
| Replay an old message | At-least-once delivery already allows redelivery; use **`idempotencyKey()`** to deduplicate (see [Retries](RETRIES.md)). |
| Trigger an arbitrary internal event | **EventHandler allowlist** blocks any event not in `$allowedEvents`. |
| Make the worker call an internal URL | **UrlHandler SSRF** controls block private/reserved targets (DNS rebinding is residual). |
| Steal the signing key from the broker | The key lives in **config/env**, not in the queue. Compromising the queue store does not reveal it. |

If signing is unconfigured **and** `$queueHandlers` is empty, a writable backend is effectively
remote code execution. The two controls together close that gap.

### Deployment recommendations

1. **Set a strong `JOBS_SIGNING_KEY`** (or a non-empty Encryption key) and keep
   `verifyEnvelopeSignature = true`. Treat the key as a secret; rotate it if a worker host is
   compromised (note: rotation invalidates in-flight messages signed with the old key).
2. **Define `$queueHandlers` for every queue an untrusted party can write to.** List the minimum
   handlers; never expose `shell`/`command` on such queues.
3. **Keep ShellHandler deny-by-default.** Pin absolute paths in `$allowedShellCommands`; avoid
   `$allowAllShellCommands = true`.
4. **Keep `$allowedEvents` tight** — only events that are safe to trigger from a queue.
5. **Run workers with least privilege** (dedicated OS user, no write access to application code).
6. **Isolate the broker** on a private network and authenticate it; signing is defence in depth, not
   a substitute for network controls.
7. **Make handlers idempotent** so replays and at-least-once redelivery are safe.

## Related exceptions

Security refusals surface as `JobException` (see [Exception Handling](EXCEPTIONS.md)):

```php
JobException::forShellCommandsNotConfigured(); // deny-by-default ShellHandler
JobException::forShellCommandNotAllowed('/usr/bin/rm');
JobException::forEventNotAllowed('user.deleted');
JobException::forInvalidMethod('TRACE');        // UrlHandler method allowlist
```

A signature rejection is not an exception thrown to the caller — it is handled inside the worker,
which abandons the message and returns a `WorkerResult` with status `rejected`.
