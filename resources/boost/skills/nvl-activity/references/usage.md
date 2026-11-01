# Activity recording, capture and reads

Use [installation](installation.md) for providers/publication and [API](api.md) for full signatures and types. Services are in `Nvl\Activity\Services`; domain values such as `ActivityEventPolicy`, `ActivityCaptureOptions`, `ActivityRecordEnvelope`, `ActivitySubjectReference` and `ActivityPurgeCriteria` are in `Nvl\Activity\Support`.

## Register semantics and host authorization

Bind `Nvl\Activity\Contracts\ActivityAuthorization::authorize(Authenticatable|SystemActor $actor, string $ability, Model|ActivitySubjectReference|null $subject = null): void`. Throw for denied `record`, `read`, `timeline`, `causers`, `purge`; explicitly grant `read_all` only where intended. The default denies human and background execution. Pass persisted principals or a host-authorized `SystemActor::Background`; historical scalar actor IDs are attribution, not execution authority.

Before first policy lookup, declare event-keyed `nvl-activity.events` entries. Allowed policy fields are `subject_types`, `actor`, `actor_types`, `change_fields`, `metadata_fields`, `redacted_fields`, `retention_days`, `audience`. Alternatively register `ActivityEventPolicy` in `ActivityPolicyRegistry` during provider boot. Duplicate or late registration rejects.

`actor` is `required`, `optional` or `system`; audiences are `authorized`, `administrative`, `subject`, `actor`, `subject_or_actor`. Empty change/metadata lists admit none; explicit null admits any bounded native JSON. Redacted fields must be admitted change fields. `retention_days` is a positive minimum age or null for forever. Unknown/forever events remain during retention. Configure every event emitted by automatic model capture and integrated packages.

## Verified semantic write-to-read example

During host bootstrap, before resolving the catalog:

```php
config()->set('nvl-activity.events', [
    'order.approved' => [
        'actor' => 'required',
        'change_fields' => ['status'],
        'metadata_fields' => ['reason'],
        'retention_days' => 365,
        'audience' => 'authorized',
    ],
]);
```

With persisted, admitted `$order` and `$actor` and the host policy bound:

```php
use Nvl\Activity\Services\ActivityReader;
use Nvl\Activity\Services\ActivityRecorder;

$fact = app(ActivityRecorder::class)->record([
    'subject' => $order,
    'event' => 'order.approved',
    'log_name' => 'orders',
    'context' => ['reason' => 'Reviewed'],
    'attributes' => ['status' => 'approved'],
    'old' => ['status' => 'pending'],
], $actor);

$page = app(ActivityReader::class)->paginate([
    'events' => ['order.approved'],
    'per_page' => 20,
    'page' => 1,
], $actor);
$timeline = app(ActivityReader::class)->timeline($order, $actor, 50);
```

This records semantic evidence; the host separately owns the actual order transition. Put both writes in a transaction on their shared connection when atomicity is required.

## Native entry fields and null behavior

`record` accepts exactly `subject`, `event`, `description`, `log_name`, `context`, `attributes`, `old`, `visibility`, `importance`, `resolve_changes`, `batch_uuid`. Subject is persisted model, `ActivitySubjectReference` or null. Event is a registered lowercase alias or backed enum value, at most 255 characters. Visibility is `timeline`, `audit_only` or `hidden`; importance uses native enum values. A supplied batch UUID must be valid. Unknown keys and actor/source/owner/primary-key overrides reject.

Context/diffs are bounded JSON records (64 KiB per record with bounded depth). Null context becomes empty. Omitting diff arrays permits saved-model change inference for update/status events; explicit null suppresses that side and disables inference. `resolve_changes:false` disables inference. Canonical diffs are `attribute_changes.attributes` and `.old`; `properties` holds context/classifications. Credential-like keys are recursively replaced with `[REDACTED]`, while business key spelling is retained. Redaction does not relax event field allowlists.

## Automatic capture and semantic presentation

Add `HasModelActivity` to host models and register one `ActivityMapping` per exact class in `MappingRegistry`. Its `modelClass`, `entityLabel`, `subjectLabel`, `logName`, `captureOptions`, field label/value and event template methods define semantics; see the API contract. Return `new ActivityCaptureOptions(['name', 'status'])` with explicit fields, optional lifecycle event list and `onlyDirty`. No wildcard or third-party logging options.

Wrap changes in `ActivityCaptureContext::run($actor, fn () => ...)`. Native capture authorizes before the business mutation where an Eloquent before-event exists, restores scope in `finally`, remains silent for unmapped/empty changes and emits one restored fact. Quiet saves and bulk business SQL do not emit model events; record a deliberate semantic fact for those paths. Avoid duplicating automatically captured CRUD facts manually.

`LabelResolver` supplies semantic entity/field labels. `ActivityDiffBuilder::build` returns a collection of arrays with `key`, `label`, `old`, `new`, `description`; `HeadlineRenderer::resolveHeadline` returns plain text. Host resources build visual segments. Translated labels use the host's selected locale; historical identity/occurrence facts remain unchanged.

## Immutable outbox delivery

`ActivityRecorder::recordEnvelope(ActivityRecordEnvelope $envelope): ActivityLog` accepts a trusted already-authorized domain fact. Persist its UUID, exact microsecond occurrence instant, subject/causer references, optional scalar historical actor ID, event, log name, context and diffs unchanged. `toArray` / `fromArray` use the complete closed shape in the API. Do not synthesize an envelope directly from public request fields.

Identical retries return the same Activity row; conflicting facts under the same UUID reject, including changed source values that redact identically. Deleted historical subjects/actors do not become current authority. Native owner admission remains required. The call owns an Activity-source transaction and joins a host transaction on that connection. A domain outbox on another connection needs its own durable handoff; `recordEnvelope` does not create one.

## Bounded read selection

`paginate(filters, actor)` supports `search`, `event`, `events` (maximum 100), `causer_id`, `subject_type`, `subject_id`, `subjects` (maximum 100 exact references), `subject_or_causer`, `created_at_from`, `created_at_to`, `per_page` (1–100), `page`. Empty `subjects` matches nothing. Dates accept `DateTimeInterface|string|null`, and reversed ranges reject. Exact subject pairs prevent accidental cross-type identity matches.

`cursor(filters, actor, cursor:null)` uses the same filters except `page`; it returns native `items` and nullable opaque `next_cursor` without total count. Preserve filters and cursor unchanged; timestamps retain microseconds and UUID tie-breaks. `find(id, actor)` returns null for missing/inaccessible records.

`timeline(subject, actor, limit:100, sources:[])` permits 1–1000 results, at most ten `ActivityTimelineSource` query sources and a 5000-row base scan. Each source supplies subject constraints, source authorization, safe columns and sortable canonical `created_at` storage. Activity adds owner admission, stable key tie-breaks and independent bounds; supersession applies only when the source returns visible facts. Source timestamps and declared key types must agree with database ordering. Consumers own merged-source presentation.

`causers(actor, search:null, limit:10)` returns fixed safe identity projections including supported soft-deleted historical actors. It cannot expose credentials through custom label settings. Relation hydration batches by model type while retaining fresh admission; count protected data queries separately from schema/admission probes.

## Retention, storage and errors

Preview with `ActivityPurgeDispatcher::count($criteria, $actor)` and queue with `dispatch($criteria, $actor)`. `ActivityPurgeCriteria::fromDays(365)` is a validated starting point; use its full constructor/API for date/filter selection. Important evidence stays protected unless explicitly included; caller cutoff cannot shorten event minimum retention. Queue requests/events follow Activity's source outer commit, and rollback cancels them. Those callbacks are process-local, so a crash after commit can lose a request. Once queued, jobs retain captured owner scope, partition locks, bounded chunks, retries/backoff and failure reporting.

Choose `models.activity_log` before provider boot with a concrete `ActivityLog` subclass. Preserve UUID identity, required casts, canonical hooks/ownership, package-configured table and actual connection; add host columns through host migrations. Use observers or bootable traits for added listeners because native invariant listeners are final. Ordinary native model mutation APIs reject edits/deletes to persisted facts; raw privileged SQL is outside that package guarantee.

Optional Ownership requires reviewed resource/adoption and live scope; disabling its runtime cannot expose adopted data. The base package needs no Spatie Activitylog, Data, TypeScript or Tenancy. Operational commands are `nvl:activity:doctor`, `nvl:activity:purge`, `nvl:activity:purge-system`; purge flags and exact signatures are in the API. Configure an explicitly authorized background principal, durable queue, shared lock store and queue visibility timeout beyond job timeout before scheduling retention. The host maps authorization, policy/input and replay-conflict exceptions and projects native results.
