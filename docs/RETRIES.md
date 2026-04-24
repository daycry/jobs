# Retries & Backoff

Jobs may be requeued after failure based on the configured retry policy. The `JobLifecycleCoordinator` drives the retry loop using `RetryPolicyFixed`, which implements all strategies in a single class.

## RetryPolicyFixed
The unified retry class handles all strategies:
```php
use Daycry\Jobs\Execution\RetryPolicyFixed;

$policy = new RetryPolicyFixed(
    base: 5,
    strategy: 'exponential',
    multiplier: 2.0,
    max: 300,
    jitter: true,
);
$delay = $policy->computeDelay($attempt); // returns seconds
```

- First attempt (`attempt <= 1`) always returns 0 (no delay before first execution).
- Strategy is read from global config by `JobLifecycleCoordinator` automatically.

## Strategy
Configured via `retryBackoffStrategy`:
- `none` – immediate requeue (no delay)
- `fixed` – constant delay derived from `retryBackoffBase`
- `exponential` – growth via multiplier and capped ceiling

## Parameters
| Setting | Used In Strategies | Description |
|---------|--------------------|-------------|
| `retryBackoffBase` | fixed, exponential | Baseline seconds for first retry. |
| `retryBackoffMultiplier` | exponential | Factor applied for subsequent attempts. |
| `retryBackoffMax` | fixed, exponential | Upper bound on any computed delay. |
| `retryBackoffJitter` | any | If true, add +/- up to 15% randomness. |

## Formula (Exponential)
```
delay = base * multiplier^(attempt-2)   where attempt is the current retry number
```
`computeDelay(attempt)` returns 0 when `attempt <= 1` (no delay before the first execution).
The exponent starts at 0 for attempt 2, so the first retry delay equals `base`.

## Example
```
base=5, multiplier=2:
computeDelay(1) -> 0s  (first attempt, no pre-delay)
computeDelay(2) -> 5s  (before 2nd attempt: 5 * 2^0 = 5)
computeDelay(3) -> 10s (before 3rd attempt: 5 * 2^1 = 10)
computeDelay(4) -> 20s (before 4th attempt: 5 * 2^2 = 20)
computeDelay(5) -> 40s (before 5th attempt: 5 * 2^3 = 40)
```
With `max=45` attempt 6 (80s) would clamp to 45.

## Jitter
Adds randomness to reduce thundering herd: `delay = delay ± (delay * rand(0,0.15))`.

## Applying Delay
Backoff integration typically happens before re-enqueue inside failure handling; ensure scheduling/delay attribute is applied to job/envelope before pushing.

## Custom Policies
Implement custom logic using attempts:
```php
if ($job->getAttempt() >= 5) {
    // give up
} else {
    // compute delay and requeue
}
```

## Metrics
Track number of retries via `jobs_requeued` counter. You can export additional histograms for delay distribution.
