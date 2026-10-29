# NVL Activity

Native audit recording, model capture, historical reads, semantic mappings and bounded merged timelines for PHP 8.4 and Laravel 13. Install `nvl/activity`; Core and Spatie Activitylog v5 are required domain dependencies. Spatie Data, TypeScript Transformer, Tenancy and Ownership are not required. Install and explicitly configure `nvl/ownership` when audit facts need owner isolation.

Consumers own Actions, controllers, routes, request validation, resources, DTOs, frontend types and headline segments. Activity registers no HTTP routes. Its useful models and services are the application API.

## Model replacement

Configure `nvl-activity.models.activity_log` before provider boot to select a concrete subclass of `Nvl\Activity\Models\ActivityLog`. Native recording, reads, capture, retention jobs, factories and Ownership adoption use that class; opt-in global Spatie adoption selects it too. Existing morph aliases remain readable. The selection is immutable for the application lifetime. Subclasses may add attributes, casts, accessors and relations while retaining native UUID identity, required casts, query/persistence hooks and the package ownership declaration. Native invariant listeners are final; add listeners through an observer or a bootable trait. Storage remains package-configured: the selected model must use the native effective table and connection. Added columns require host migrations. This does not replace timeline source models or historical subject/causer classes.

## Authorize direct operations

Bind `Nvl\Activity\Contracts\ActivityAuthorization` in the host container. Its `authorize(Authenticatable|SystemActor $actor, string $ability, Model|ActivitySubjectReference|null $subject = null): void` method must deny unauthorized `record`, `read`, `timeline`, `causers` and `purge` operations by throwing. Missing bindings deny access, including background calls. Pass a persisted authenticatable explicitly or `Nvl\Support\Enums\SystemActor::Background`; null, scalar actor IDs and ambient HTTP authentication never grant authority.

```php
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Services\ActivityReader;

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
$causers = app(ActivityReader::class)->causers($actor, search: 'Ada', limit: 10);
```

`record(array $entry, Authenticatable|SystemActor $actor): ActivityLog` accepts only `subject`, `event`, `description`, `log_name`, `context`, `attributes`, `old`, `visibility`, `importance`, `resolve_changes` and `batch_uuid`. Subject may be a persisted model, immutable subject reference, or null. Event is a nonblank string or backed enum, at most 255 characters. Visibility accepts `timeline`, `audit_only`, `hidden`; importance accepts the package enum values. Batch UUIDs must be valid. Actor/source/ownership/primary-key overrides and unknown keys fail before persistence.

Context and diffs are JSON-safe records, with a 64 KiB limit per record and bounded nesting. Null context means an empty record. Omitted diff arrays infer saved model changes for update/status events; explicit null suppresses that side and disables inference. `resolve_changes: false` disables inference. Credentials with password, secret, token, API-key, authorization or cookie keys are recursively replaced with `[REDACTED]`. Business metadata keys keep their original spelling.

## Immutable delivery and transaction guarantees

`ActivityRecorder::recordEnvelope(ActivityRecordEnvelope $envelope): ActivityLog` delivers an already-authorized immutable fact. Use it for a domain outbox, not for untrusted HTTP payloads. Its UUID, subject reference, optional causer reference/scalar historical actor ID, event, log name, exact occurrence time and JSON facts define delivery identity. Identical retries return the same row. Reusing an ID with different facts fails, including changes to redacted source values. Historical subjects and actors can be deleted: delivery never rehydrates them as execution authority. Current ownership admission is still mandatory.

Both recording operations transact on the Activity model's actual connection and participate in a host transaction on that connection. Outer rollback removes their writes. A domain outbox on another connection cannot share that atomicity; the consumer owns the cross-connection delivery boundary. The envelope does not create an outbox itself.

## Automatic model capture

Use `HasModelActivity` on host models and register a concrete `ActivityMapping` with `MappingRegistry`. Mapping-owned Spatie `LogOptions` define tracked fields, log names and semantic labels. Unmapped models and empty changes remain silent.

Wrap mapped model mutations in an explicit capture scope:

```php
use Nvl\Activity\Services\ActivityCaptureContext;

app(ActivityCaptureContext::class)->run($actor, function () use ($order): void {
    $order->getConnection()->transaction(function () use ($order): void {
        $order->update(['status' => 'approved']);
    });
});
```

Capture validates/authorizes mapped events before model mutation where Laravel provides a before-event, and uses the same explicit actor for the resulting fact. Scope restoration uses `finally`, including nested scopes and reused workers. Mapped capture without a scope fails closed. A host transaction on the shared model/Activity connection makes model and audit writes atomic. Separate Activity storage cannot roll back with a business connection; use a domain outbox for that requirement. Global Spatie storage remains host-owned unless `adoption.spatie` is explicitly enabled.

## Bounded subject references and event filters

`paginate()` accepts native snake-case filters: `search`, `event`, `events` (up to 100), `causer_id`, `subject_type`, `subject_id`, `subjects` (up to 100 exact `ActivitySubjectReference` pairs), `subject_or_causer`, `created_at_from`, `created_at_to`, `per_page` (1–100), and `page`. Empty `subjects` matches nothing. Dates accept `DateTimeInterface|string|null`; reversed ranges fail. Date-only strings retain midnight precision. Transport aliases, comma-separated event strings and response envelopes belong to consumers.

`timeline()` returns a Laravel collection of native models ordered by creation instant, descending morph type and descending native key within that type (numeric for integer keys, lexical for string keys). Source storage and its declared key type must agree, including sortable timestamps and string-key collation. It accepts limits 1–1000, scans at most 5000 base facts in batches of 100, and may return fewer results when the scan bound is reached. Historical visibility and noisy-only updates are filtered before the final limit. Equal timestamps retain distinct events. Missing historical relations safely yield null in service reads.

Custom sources implement `ActivityTimelineSource::query(Model|ActivitySubjectReference): Builder` and `supersedes(): array`. Pass at most ten sources to `timeline(..., sources: [$comments])`. Each source supplies its own subject constraint and canonical database `created_at` ordering; Activity adds native owner admission, a stable key tie-break and an independent row limit. Source models and columns remain consumer-owned. Supersession only applies when that source returns visible rows. Consumers own custom source authorization, sensitive-field selection and presentation. Do not return an unbounded materialized collection or a presentation DTO.

`causers()` searches historical identities, validates integer/UUID/ULID keys, and retains soft-deleted causers where supported. Results are native models with only the primary key and available `name`, `email`, `first_name`, `last_name` columns. Custom label configuration cannot expose credentials. Safe relation hydration batches queries per model type and preserves ownership predicates; schema/admission probes remain fresh and are distinct from protected row-loading budgets.

## Semantic rendering

`MappingRegistry`, `LabelResolver`, `HeadlineRenderer` and `ActivityDiffBuilder` retain model-specific labels, shared event templates, consumer placeholders, scalar/structured diffs and technical-noise suppression. `HeadlineRenderer::resolveHeadline()` returns plain text. `ActivityDiffBuilder::build()` returns a collection of documented arrays containing `key`, `label`, `old`, `new`, `description`. Consumers create visual segments and resources themselves.

## Retention and recovery

`ActivityPurgeDispatcher::dispatch(ActivityPurgeCriteria $criteria, Authenticatable|SystemActor $actor): int` authorizes and queues one bounded job in the currently admitted owner scope. `count($criteria, $actor): int` previews that same scope. `ActivityPurgeCriteria` validates days/date ranges and allowlisted filters. Important evidence is protected unless explicitly included.

Job dispatch and `ActivityLogPurgeQueued` publication follow the Activity connection's outer commit; rollback discards both. These local callbacks are not a durable outbox: a process crash after commit can lose a retention request. Once queued, `PurgeActivityLogsJob` retains captured native ownership, partition-specific locking, bounded chunks, exception backoff, lock-contention retry and failure reporting. Hosts run reviewed per-owner scopes; there is no implicit global worklist or Tenancy fallback.

Operational commands: `nvl:activity:purge`, `nvl:activity:purge-system`, and `nvl:activity:doctor`. Purge commands use the explicitly authorized background principal. Schedule retention only after configuring the host policy, durable queue, shared lock store and queue visibility timeout longer than the job timeout. `ActivityDoctor::inspect()` returns bounded diagnostic arrays. Run the package tests with `php tools/run-package-tests.php activity --concurrency=1` in the suite workbench.

## Ownership and direct model reads

`ActivityLog` is an immutable-owner captured fact. Optional Ownership supplies admission, quarantine/adoption, safe queries and worker restoration. Removing/disabling the valid runtime cannot expose previously adopted storage. Subject/causer relations use canonical declared storage and fixed causer projections. Caller-supplied relations and changed identities are re-admitted. Useful direct model queries retain ownership safeguards but do not substitute for a host operation policy.

See [upgrade notes](UPGRADING.md) and [events](docs/events.md). The supported consumer catalog is `resources/consumer-api.json`.

Shared [service contracts, model extensions and composition](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/consumer-api.md) apply alongside the package-specific rules above.
