---
name: nvl-activity
description: Implement, integrate, test, or review nvl/activity on PHP 8.4–8.5, Laravel 13, and Spatie Activitylog 5.x. Use for generic audit capture, Spatie Activitylog adoption, semantic timelines, activity mappings, value formatters, retention, purge operations, API authorization, or activity DTOs.
---

# NVL Activity

Use this package for generic structured audit capture and semantic timelines on PHP 8.4–8.5, Laravel 13, and Spatie Activitylog 5.x. Keep raw audit storage separate from presentation, and never infer a consumer domain from event names, payload keys, models, or authorization conventions.

## Own the model and schema correctly

- `Nvl\Activity\Models\ActivityLog` is the canonical, non-configurable activity model. The provider binds it to Spatie; never add `activity.model` or override `activitylog.activity_model`.
- With `nvl-activity.migrations.enabled=true`, package migrations own only the literal `activity_log` table on the default connection. The baseline certifies a compatible existing canonical table before adopting it, the v5 bridge adds `attribute_changes`, and rollback never drops activity evidence.
- Publishing `nvl-activity-migrations` transfers ownership to the application. Set `nvl-activity.migrations.enabled` to the boolean `false`, maintain the copied migration in the application, and never edit it after deployment.
- A custom table, custom connection, or existing table that fails canonical certification requires `nvl-activity.migrations.enabled=false` and an application-owned migration with frozen literal table and connection names.
- A matching table name does not prove schema compatibility. Run `nvl:activity:doctor --strict --format=json` before altering an adopted table. For a brand-new custom schema, migrate the application-owned schema first because Doctor requires the configured table to exist. Run Doctor after migration and before cutover in both cases, then compare identifiers, columns, JSON properties, indexes, row counts, and representative timelines.

## Configure deliberately

- `routes.enabled` defaults to `false`. When enabling routes, set base and management middleware, all three named abilities, and an explicit `timeline_subjects` allowlist.
- `storage.connection` and `storage.table` control runtime model/query storage; changing either does not retarget the package migration.
- `causer_suggestions` owns the optional model, label/sublabel/type attributes, search allowlist, and scan limit. An incompatible or absent model returns no suggestions safely.
- `retention` owns default/system days, API-allowed purge choices, queue, lock lifetime, and the opt-in schedule.
- `capture.ignored_attributes` replaces the complete list of technical fields omitted from inferred diffs.
- Consumer configuration maps merge recursively with defaults, but every list-valued setting replaces the default list atomically, including an explicit empty list.
- Boolean switches must be real booleans. Run Doctor after publishing configuration and after `config:cache`.

## Capture activity

The recording facade is `Nvl\Activity\Facades\ActivityLog`. The distinct `Nvl\Activity\Models\ActivityLog` is an identity-only result handle; it does not grant consumer model queries, writes, or relationship traversal. Read timelines through the supported Activity Actions/read service and authorized C1 host scopes.

- Use `Nvl\Activity\Facades\ActivityLog::record(...)` or inject `Nvl\Activity\Contracts\ActivityRecorderContract` as the canonical writer. The contract exposes the existing `recordEnvelope`, `record`, and `recordForSubjectReference` methods and preserves their Spatie Activity return contracts/nullability. Concrete `ActivityRecorder` construction remains available.
- When only stable subject identity is available, use `Nvl\Activity\Facades\ActivityLog::recordForSubjectReference(new ActivitySubjectReference($type, $id), ...)`. It inserts without instantiating or querying the subject and never infers model diffs. This value-only seam accepts `context`, `actor`, and `importance`, but not `attributes`, `old`, or `resolveChanges`; keep value-only change metadata in `context`, or use the model-backed writer when Eloquent diffs are required.
- Use `ActivityEvent` for package-wide meanings shared across domains. Define a domain-owned string-backed enum for business-specific events rather than adding application vocabulary to the package enum.
- Omit `description` for normal writes; it defaults to the stable event key. Never use it to persist translated labels or final timeline sentences.
- Let `ActivityEvent::Updated`, `ActivityEvent::DetailsUpdated`, and status-change events infer `attributes` and `old` from the saved subject. Supply explicit arrays only for domain-specific or multi-model changes, and pair a complete explicit payload with `resolveChanges=false`.
- Use `HasModelActivity` only with a registered `ActivityMapping`; unmapped models intentionally remain silent.
- Each mapping implements all nine methods: model class, entity label, subject label, log name, Spatie options, field label, field value, event display value, and event templates.
- Store stable event keys and safe structured properties. `ActivityEvent` uses the existing event/description columns and requires no schema migration. Never store secrets, credentials, access tokens, full request payloads, or unredacted sensitive values.
- Integer, UUID, ULID, and string subject or causer identifiers are supported.
- `source`, `visibility`, and `importance` accept backed enums or exact canonical values. Unknown non-blank metadata raises `ActivityRecordingException` with `invalid_activity_metadata`/422; do not coerce or silently store it.
- Blank overrides use canonical defaults. Historical absent/blank visibility remains compatible, while every unknown non-blank visibility is excluded from signal timelines so reads fail closed. Malformed non-string visibility, including arrays and booleans, is treated as audit-only.
- The recorder joins the caller's transaction only when activity and the business model use the same database connection. Separate connections are not atomic: choose and implement an explicit policy such as after-commit best-effort recording or an application-owned transactional outbox. Dispatch dependent work only after commit, and decide explicitly whether an activity failure may roll back the business mutation.

## Build semantic timelines

- Register consumer `ActivityMapping` implementations with `MappingRegistry`; keep models lean and resolve mutable labels, values, and templates outside storage.
- Add application-owned sources through `MergeableActivityData` DTOs and select them from a host model implementing `MergesActivity` with `MergesActivityTimeline`.
- Source DTOs collect and translate their own records; the host owns source composition and optional supersession; controllers return the finished DTOs.
- `null` means a complete subject timeline. A finite limit means the newest requested rows after visibility/signal filtering. Base reads use deterministic `(created_at, id)` keyset batches of 100 until the limit is satisfied or storage is exhausted.
- The merged host applies supersession, newest-first ordering, and the final finite limit. Extra collectors must implement the same null/finite contract.
- Inject `ActivityReadContract` for raw history across existing value-only identities and call `paginateForSubjectReferences($references, $perPage)`. It retains all nine reader methods. Pass at most 100 `ActivitySubjectReference` values; exact type/ID pairs are deduplicated, page size clamps to 1–100, and an empty list returns an empty paginator without querying. `paginateForSubjectKey()` uses the same page-size clamp.
- At transport boundaries, return `ActivityItem` and related `Nvl.Activity.*` DTOs rather than exposing the raw `ActivityLog` rows returned by low-level read services. Use `HeadlineSegmentType` for backend segments; never return HTML or frontend presentation flags.

```php
use Nvl\Activity\Contracts\ActivityReadContract;
use Nvl\Activity\Contracts\ListActivitiesContract;
use Nvl\Activity\Data\ActivityIndexFilter;
use Nvl\Activity\Facades\ActivityLog;
use Nvl\Activity\Support\ActivitySubjectReference;

$filters = ActivityIndexFilter::fromInput(['events' => ['created', 'updated']]);
$page = app(ListActivitiesContract::class)->execute($filters);

$references = [
    new ActivitySubjectReference('registration', $registrationId),
    new ActivitySubjectReference('participation', $participationId),
];
$history = app(ActivityReadContract::class)->paginateForSubjectReferences($references, 50);

ActivityLog::recordForSubjectReference(
    new ActivitySubjectReference('setting', $settingKey),
    'setting_changed',
    context: ['namespace' => $namespace],
    actor: $actor,
);
```

## Authorize and expose safely

- Package API routes fail closed. Configure real Gate names for `view`, `timeline`, and `purge`, then define each ability in the application, for example:

```php
Gate::define('activity.view', fn (User $user): bool => $user->can('audit activity'));
Gate::define('activity.timeline', fn (User $user, Model $subject): bool => $user->can('view activity', $subject));
Gate::define('activity.purge', fn (User $user): bool => $user->can('purge activity'));
```

- Doctor verifies configured names have real Gate definitions. A timeline subject must also resolve through the explicit model/morph-alias allowlist and implement `MergesActivity`.
- Keep `ForceActivityJsonResponse` on package routes. Return DTO data under `data`, stable machine codes under `code`, and localized safe copy under `message`.
- The index endpoint is `GET /api/v1/activities` with the default `routes.prefix`; use the configured prefix when the consumer changes it.
- Use snake-case validation fields and camelCase mapped DTO output. Do not expose model classes, identifiers, SQL, or configuration internals in public error context.
- API inputs are bounded: index accepts optional search, legacy `event`, `events`, causer, subject, and date filters plus page size 1–100. Send `events` as repeated array values or one comma-separated string; normalization trims blanks, preserves first occurrence order, and accepts at most ten unique names of at most 100 characters. Timeline requires an allowlisted subject type and identifier with an optional 1–100 limit; causer suggestions accept search plus a 1–50 limit; both purge endpoints require `days` from `retention.allowed_purge_options`. Treat index `limit` as a page-size alias, not an unpaginated cap.

## Own strings and public contracts

- Store canonical event keys, enum values, response codes, and structured context; translate only at display, API, and console boundaries.
- Use `nvl-activity::activity/general.*` for package-owned server copy and keep English/Bulgarian keys and placeholders in exact parity.
- Shared templates precede consumer mapping templates; do not let an application replace package-wide semantics accidentally.
- Treat `ActivityEvent::Sent` and `ActivityEvent::Resent` as business actions on the subject. Keep mail transport delivery, opening, and retry lifecycle in `nvl/mail-notifications`.
- Treat response codes as opaque machine values. Regenerate and check `Nvl.Activity.*` declarations after changing a DTO or enum.
- Publish translations only for application copy overrides. Never store translated headlines or exception messages.

## Purge and operate safely

- Doctor is read-only. Run it in strict JSON mode after storage, authorization, queue, schedule, or cached-configuration changes.
- Preview retention with `nvl:activity:purge --dry-run`; use queued deletion for mutation. Important rows are protected by default, including system retention, and require explicit `include_important=true` API input or `--include-important` CLI opt-in. `nvl:activity:purge-system` and its schedule are opt-in.
- In tenant mode, purge jobs require captured tenant envelopes; queries and
  overlap locks stay tenant-bound. Platform commands use only the configured
  active-tenant worklist and never enumerate application tables implicitly.
- `PurgeActivityLogsJob` runs after commit, deletes in chunks of 1,000, and holds a distributed lock. Lock contention releases it for 60 seconds.
- All workers and schedulers must use the same canonical LockProvider-backed default cache. Use Redis, database, Memcached, DynamoDB, or another shared atomic-lock backend for multi-node operation; file is single-host only, and array/null are never production-safe. Cache failover cannot preserve one lock domain across partial failures, so strict Doctor rejects it outright.
- The time-based retry window covers one configured lock lifetime plus bounded execution retries, so repeated 60-second contention releases remain valid; five unhandled execution exceptions fail the job. Each attempt has a public 900-second timeout contract with failure-on-timeout and exception backoff of 60, 300, 900, and 1,800 seconds. Configure database, Redis, or Beanstalkd `retry_after` above 900 seconds. For SQS or a custom driver without `retry_after`, declare the externally configured value through `retention.external_visibility_timeout_seconds`. Doctor validates every target behind failover connections. Allow sufficient worker shutdown time.

## Test host orchestration through contracts

- Application services inject `ListActivitiesContract`, `ListActivityCauserSuggestionsContract`, `QueueActivityLogPurgeContract`, `ActivityReadContract`, or `ActivityRecorderContract` from `Nvl\Activity\Contracts`. Preserve the exact native results: normalized ActivityItem paginators, suggestion DataCollections, raw ActivityLog collections/paginators, Spatie Activity handles, and the purge's `void` result.
- Use native Mockery interface mocks or anonymous contract implementations in host tests, bind them with `$app->instance(Contract::class, $substitute)`, then resolve the real host service and assert its result. Return actual declared DTO/model handles without querying or invoking package persistence. An unsaved ActivityLog with `setRawAttributes(['id' => 'activity-1'])` is a test result handle.
- Defaults use `bindIf` for Actions and `scopedIf` for reader/recorder contracts and their public concretes. Contract closures resolve the concrete through their own application. Host prebindings survive discovery; late replacements reach newly resolved host services. A pre-discovery host instance keeps the host's chosen lifetime, while scoped defaults/late substitutes reset at the next Laravel lifecycle.
- The ActivityLog facade resolves ActivityRecorderContract. After a late replacement, clear `ActivityLog::clearResolvedInstance(ActivityRecorderContract::class)`; facade roots also need clearing when tests manually switch applications/scopes. Existing constructed services keep their injected dependencies.
- Use Laravel Queue/Event fakes for host tests that execute real purge dispatch. Interface substitution proves host orchestration; retain real schema/authorization/ownership/retention tests for package behavior.
- Include `vendor/nvl/core/support/consumer-audit.neon` explicitly in host PHPStan configuration and declare exact `nvlConsumer.testPaths`. It checks known API/persistence boundaries, while dynamic SQL, authorization, and unknown mixed provenance require application tests. Follow the package README's **Testing your app** examples and the major-5 contract migration in UPGRADING.md.

## Verify and upgrade

From the suite root, run `composer quality` or its module-aware Pest command, then run Composer validation, dependency analysis, suite archive/clean-consumer checks, `nvl:activity:doctor --strict --format=json`, and `nvl:data:types:check` as applicable. Root consumer automation proves installation from the single relocated `nvl/laravel-suite` artifact, discovery, cached configuration/routes, canonical and custom-connection migration lifecycles, mapping registration, exact CRUD and structured capture, visibility filtering, complete and finite merged timelines, all five authenticated API endpoints, serialized purge scopes, and execution on a real database queue worker. Treat that smoke as a required production contract rather than replacing it with installation-only checks.

Coverage must include canonical model binding; literal migration up/down behavior; custom/adopted storage rejection; mapped create/update/delete; structured and batch writes; identifier/actor variants; invalid metadata; complete and finite post-filter timelines across keyset batches; real Gates and allowlists; JSON/API/error contracts; EN/BG parity; retention scopes and dry runs; job locking/retry/backoff/timeout; and after-commit dispatch.

Activitylog 4 is not a supported runtime. Before enabling v5 writes, explicitly require Activitylog 5, move host imports to the v5 namespaces, add the nullable JSON `attribute_changes` column, run strict Doctor, and verify historical `properties` fallback reads. When upgrading a consumer that published this skill, republish `nvl-activity-skills` with `--force` only after reviewing local changes, or manually merge the newer bundled skill into the customized application copy. Review `CHANGELOG.md` and `UPGRADING.md` before changing storage or public contracts.

## Configurable-tenancy release discipline

- Preserve disabled compatibility and package independence; tenant support never creates an undeclared Auth or Suite dependency.
- Use registered package-owned resources, adoption adapters, Actions, and lifecycle APIs. Never add a generic tenant delete-all path or raw cross-package cleanup.
- Treat mapping/configuration hashes, interruption checkpoints, conservation evidence, worker context, tenant-leading queries, and standalone consumption as release contracts.

## Shared consumer diagnostics

Run `php artisan nvl:doctor --strict --format=json` to combine checks from loaded NVL providers. Retain the package Doctor command for its detailed report; both paths reuse the package-owned inspection service.

### Brownfield storage identities

Resolve all package tables through the table helper and canonical `nvl-activity.tables.*`, connections through `nvl-activity.connection` with Core/Laravel inheritance. Defaults use `nvl_activity_*`; migration filenames include that package slug. Never silently adopt a matching table or generic migration filename. Run shared `nvl:doctor --strict --format=json` and the explicit `nvl:schema:upgrade --package=activity --claim-legacy --dry-run --format=json` before upgrading owned legacy storage. Validate the complete plan and choose one migration owner. Preserve host records, constraint names and stored morph values. Deprecated config inputs last one major; canonical options take precedence.

## Cache and lock ownership

Purge locks use `nvl:activity:purge:` followed by the hash of the admitted tenant boundary identity instead of the generic `purge` key. Inherited cache stores and Laravel scheduler mutexes are preserved; generic host locks are never acquired or removed.

Drain old mutation workers and maintenance processes, then wait for their outstanding lock leases to end before starting the new major across all nodes. Running old and new lock prefixes concurrently would create independent serialization domains. Restart workers after cutover; preserve host-selected stores and keys, and do not flush a shared cache to remove old NVL entries.

## Canonical configuration ownership

- Read/write `nvl-activity` configuration and publish only canonical `nvl-<package>-<resource>` tags. Keep logical package/tenant resource identifiers unchanged.
- Generic config roots and unprefixed package environment names are foreign by default. For an upgrading NVL host only, select `nvl-core.compatibility.legacy_config` package IDs and `legacy_env` explicitly; both default off. Canonical presence wins, including false/null/empty values. Legacy inputs are read without writing back and are removed in major 6.
- Use canonical `NVL_<PACKAGE>_*` variables only in config evaluation, then rebuild configuration caches and restart workers after cutover. Shared Laravel environment variables retain their names. Consult Core's versioned `support/resources/global-names.json` for all renames.
- Old global aliases and legacy route families require separate explicit `global_aliases`/`legacy_routes` package selections. Preserve collisions and use Doctor diagnostics; never grant generic permissions automatically or claim signed-link compatibility without the same authorization/signature checks.


## Consumer runtime and testing contracts

Start with the package README Quickstart and Testing your app sections. Use `nvl:install <package>` for loaded-package common config publication; it does not enable features, run schema or refresh caches. Preserve native host owner keys/morph maps and selected auth/tenancy defaults. Read full runtime defaults and publish advanced config only deliberately.

Inject the supported focused interfaces and preserve host bindings. Returned model handles do not permit package-table queries/writes outside documented capability/extension seams. Host tests may substitute contracts in Laravel's container, use shipped model factories (ordinary make may persist parents; withoutParents()->make is detached), and use Laravel effect fakes deliberately. Only Media/Stripe have dedicated provider/library fakes; do not invent a universal package fake. Settings InteractsWithSettings is definition-only. Host PHPStan may include vendor/nvl/core/support/consumer-audit.neon; no unpublished workbench command is a consumer requirement.

Read docs/events.md and the package README error table. Domain events use schemaVersion=1, model-free facts and actual source-connection commit callbacks; only six declared old Event suffix aliases remain for major 5. Migrate exact listeners/fakes and suffix wildcards, drain old queued payloads, rebuild event cache and restart workers. Delivery is not a durable outbox. The Core exception renderer is opt-in, JSON-only for respondable failures, with exactly message/code/context and host-selected locale. Do not expose diagnostics or reinterpret missing bindings as authorization denial.

Core package logging uses nvl/normal with CSV quiet by default, stable message keys and bounded context; incidents survive quiet. Do not mutate global logger context or log raw row/provider/content/credential payloads. Run only authorized project checks and report new acceptance as pending until actual output exists.
