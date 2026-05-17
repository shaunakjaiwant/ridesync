# RideSync Service Layer

This folder is the incremental service boundary for RideSync business logic.

Keep pages and actions thin:

```text
pages/actions -> services -> repositories/helpers -> database
```

Current services:

- `RideService.php`: live ride status updates and driver trip history recording.
- `RideStateMachine.php`: canonical live ride states and allowed transitions.
- `NotificationService.php`: notification creation and inbox mutation operations.
- `QueueService.php`: database-backed background job boundary for async-safe work.
- `RealtimeEventService.php`: event outbox for SSE today and WebSocket/Redis fan-out later.

Guidelines:

- Put reusable business decisions here before adding more SQL to page/action files.
- Keep existing compatibility helper functions while migrating old code.
- Keep services database-aware but UI-agnostic.
- Prefer idempotent methods for operations that may be retried by future workers.
- Queue/WebSocket integrations should call these services instead of duplicating logic.

Worker entrypoint:

```text
php tools/queue_worker.php --watch --queue=notifications
```

Realtime events should be compact and safe for client delivery. Do not publish raw document references, tokens, passwords, or unmasked government identifiers.
