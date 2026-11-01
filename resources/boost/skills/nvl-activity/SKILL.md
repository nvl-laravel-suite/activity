---
name: nvl-activity
description: Use when integrating native Activity recording, explicit model capture, audit timelines, immutable outbox delivery, semantic labels or scoped retention with nvl/activity.
---

# NVL Activity

Activity targets PHP 8.4 and Laravel 13, requires Core and has no Spatie Activitylog dependency.
Optional Ownership supplies admission; DTO/TypeScript libraries and Tenancy are not runtime dependencies.
Consumers own transport, presentation, visual headline segments and domain outboxes.

## Portable references

- [Installation](references/installation.md): provider, exact publish tags, migration ownership and configuration.
- [Native API](references/api.md): all public declarations, contracts, model capabilities and shaped arrays.
- [Usage](references/usage.md): event policy setup, capture, immutable delivery, bounded reads and retention examples.

Read usage for capability selection and API before assembling native arguments.
Read installation when creating a consumer or changing storage/adoption.
All local references remain available after Laravel Boost publication.

## Define events before writing

Declare `nvl-activity.events` before first catalog use, or register `ActivityEventPolicy` during boot.
Unknown events and duplicate/late policy registration fail.
Empty field/metadata allowlists admit none; explicit null admits bounded native JSON.
Policies define attribution types, redaction, audiences and minimum retention.
Null retention means forever, including during requested purges.
Actor policy governs attribution; execution still needs host permission.

## Choose the complete capability

- `ActivityRecorder::record`: validate, authorize, redact and persist one explicit semantic fact.
- `recordEnvelope`: idempotently deliver already-authorized immutable facts from a domain outbox.
- `ActivityCaptureContext::run` with `HasModelActivity` and mappings: capture native CRUD facts.
- `ActivityReader::paginate` / `cursor` / `find`: admitted native history reads.
- `ActivityReader::timeline`: merge bounded subject history with host-owned query sources.
- `ActivityReader::causers`: safe historical actor identity projections.
- `MappingRegistry`, `LabelResolver`, `HeadlineRenderer`, `ActivityDiffBuilder`: semantic presentation facts.
- `ActivityPurgeDispatcher::count` / `dispatch`: preview or queue scoped policy-constrained retention.

## Supply explicit execution authority

Bind `ActivityAuthorization`; missing permission decisions deny, including background calls.
Pass persisted Authenticatable actors or an explicitly authorized `SystemActor::Background`.
Never infer authority from Request, null, scalar historical attribution or captured source facts.
Restricted audiences filter SQL before counts/pages; `read_all` overrides only within admitted ownership.
Custom timeline sources own subject predicates, permissions and field selection.

## Preserve immutable facts and transactions

Native fact rows are append-only; retention owns deletion.
Record/envelope writes use the actual Activity source connection and join its enclosing transaction.
Different source connections cannot share rollback; use a domain outbox where needed.
Envelope UUID, occurrence instant, subject/causer references and all facts must survive retries unchanged.
Equal delivery returns the same row; conflicting facts reject.
The envelope API does not create a durable outbox.
Capture scopes restore nested state in `finally`; quiet/bulk writes need explicit semantic recording.

## Models, reads and retention

Configure `models.activity_log` before boot to a compatible ActivityLog subclass.
Preserve native UUIDs, casts, query/persistence hooks, ownership and package table/connection.
Add native listeners through observers or traits; invariant listeners remain final.
Use bounded native projections; do not hydrate arbitrary actor security attributes.
Purge queue requests/events follow outer commit through process-local callbacks.
Once queued, jobs retain captured owner scope, locks, bounded chunks, backoff and failure handling.
Use `nvl:activity:doctor`, `nvl:activity:purge` and `nvl:activity:purge-system` as documented in usage.
