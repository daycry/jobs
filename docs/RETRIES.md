# Retries & Backoff

Jobs may be requeued after failure based on your own conditional logic or helper flows. This document explains delay computation knobs.

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
base * multiplier^(attempt-1)
```
`attempt` is the post-run attempt count (first failed run => attempt=1). Apply cap & jitter last.

## Example
```
base=5 multiplier=2 attempts sequence (after failures):
Attempt 1 -> 5s
Attempt 2 -> 10s
Attempt 3 -> 20s
Attempt 4 -> 40s (etc)
```
With `max=45` attempt 5 (80s) would clamp to 45.

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
