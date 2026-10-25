# Activity events

### ActivityLogPurgeQueued

`Nvl\Activity\Events\ActivityLogPurgeQueued` is the canonical event. Fields are `days`, `systemOnly`, `includeImportant`, `schemaVersion`; its native Ownership envelope captures the producer scope without serializing an actor or subject model.

`ActivityPurgeDispatcher::dispatch()` authorizes the explicit principal and publishes the event through Core's `DomainEventDispatcher` on the actual Activity connection. Publication and job dispatch occur after that connection's outer commit. Rollback discards them. Immediate calls publish immediately. The event means a purge was requested, not completed; repeated requests may repeat the fact.

Local after-commit callbacks are not an outbox and do not guarantee delivery across process crashes. Hosts own workers and durable queue configuration. A delivered purge job retains partition-specific locking, chunking, backoff and failure reporting. Native Ownership restores the captured scope during queue execution and restores the prior worker scope afterward.

The old `ActivityLogPurgeQueuedEvent` alias and native listener bridge are removed. Subscribe to the canonical class, drain obsolete payloads and restart workers on upgrade. Machine-readable metadata is in `resources/event-catalog.json`.
