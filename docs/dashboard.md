# Dashboard

Currently no bundled web dashboard UI.

## Suggested Approaches
- Build a small controller listing scheduled jobs from your `Jobs` config.
- Expose recent execution logs (file or DB) with filtering.
- Add metrics endpoint (Prometheus) and embed Grafana panels.

## Future Ideas
- React/Vue SPA listing queues, retry stats, and inline requeue/disable actions.
