---
name: nvl-activity
description: Use native NVL Activity recording, explicit capture scopes, bounded model timelines, semantic mappings, immutable delivery and retention in Laravel consumers.
---

# NVL Activity

Use PHP 8.4 and Laravel 13. Activity requires Core and Spatie Activitylog v5. Optional Ownership supplies owner admission. Data, TypeScript Transformer and Tenancy are not runtime dependencies. Read the package README for exact native input shapes.

Bind `ActivityAuthorization` for `record`, `read`, `timeline`, `causers`, `purge`. Missing bindings deny. Pass persisted `Authenticatable` or explicitly authorized `SystemActor::Background`; never infer a principal from Request, null, scalar payload or historical facts.

- `ActivityRecorder::record(array $entry, Authenticatable|SystemActor $actor): ActivityLog` implements validation, attribution, redaction, ownership and persistence. Native entry keys are `subject`, `event`, `description`, `log_name`, `context`, `attributes`, `old`, `visibility`, `importance`, `resolve_changes`, `batch_uuid`. Unknown/protected fields fail. Metadata is bounded JSON; omission and explicit null differ for inferred diffs.
- `recordEnvelope(ActivityRecordEnvelope): ActivityLog` is trusted immutable-fact delivery for a domain outbox. Preserve UUID, subject/causer reference, occurrence time and complete facts across retries. Historical actors may be deleted and are never execution authority. Equal retries return the same row; conflicting facts fail. Envelope delivery does not create an outbox.
- `ActivityReader::paginate(array $filters, $actor)` returns a Laravel paginator of native ActivityLog rows; page size is 1–100 and exact subject references/event lists are bounded at 100.
- `ActivityReader::timeline($subject, $actor, $limit = 100, $sources = [])` returns native models; limit is 1–1000, at most ten `ActivityTimelineSource` query implementations, base scan at most 5000. Host sources own subject constraints, source permissions and field selection. Activity adds current owner scope and deterministic ordering. Keep source timestamp storage chronologically sortable.
- `ActivityReader::causers($actor, $search = null, $limit = 10)` returns fixed safe identity projections, including historical soft-deleted actors when supported.
- `ActivityPurgeDispatcher::dispatch($criteria, $actor)` and `count($criteria, $actor)` operate only in the admitted scope. Jobs capture native ownership and retain locks, bounded chunks and retry/failure behavior. The queue request/event follow the Activity connection's outer commit; local callbacks do not promise crash-durable delivery.

Register `ActivityMapping` implementations with `MappingRegistry` and use `HasModelActivity`. Wrap mapped model mutations in `ActivityCaptureContext::run($actor, fn () => ...)`; the scope restores nested state in `finally`. Use the model connection's transaction when audit/model writes share a connection. Different database connections cannot share rollback. Unmapped models remain silent. Do not emit duplicate manual facts for automatically captured CRUD unless a separate semantic event is needed.

Keep business context meaningful, diffs narrow, technical noise suppressed and mappings deterministic. `LabelResolver`, `HeadlineRenderer` and `ActivityDiffBuilder` retain semantic labels, plain-text headlines and documented diff arrays. Consumers own visual headline segments, merged-source presentation, Actions, HTTP routes/controllers/resources, DTO libraries and frontend types. Never recreate package transport DTOs or compatibility facades.

Useful model reads retain immutable ownership and canonical relation admission. Never weaken fresh admission or cache authority to meet a query budget. Measure protected row SQL separately from schema/admission probes. Do not hydrate foreign subjects or arbitrary security attributes; causers expose only fixed identity columns.

Verify explicit principal denial, pre-mutation capture denial, metadata/redaction, direct service inputs, deterministic bounds, owner isolation/quarantine, host rollback/outer commit, replay conflicts, safe relations and retained retention behavior. Use the owning package suite, Pint and configured maximum PHPStan; verify optional libraries physically absent in an independent no-dev installation.
