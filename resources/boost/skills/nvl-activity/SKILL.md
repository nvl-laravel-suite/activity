---
name: nvl-activity
description: Implement, integrate, test, or review nvl/activity on PHP 8.4–8.5, Laravel 12–13, and Spatie Activitylog 5.x. Use for generic audit capture, Spatie Activitylog adoption, semantic timelines, activity mappings, value formatters, retention, purge operations, API authorization, or activity DTOs.
---

# NVL Activity

Use this package for generic structured audit capture and semantic timelines on PHP 8.4–8.5, Laravel 12–13, and Spatie Activitylog 5.x. Keep raw audit storage separate from presentation, and never infer a consumer domain from event names, payload keys, models, or authorization conventions.

## Own the model and schema correctly

- `Nvl\Activity\Models\ActivityLog` is the canonical, non-configurable activity model. The provider binds it to Spatie; never add `activity.model` or override `activitylog.activity_model`.
- With `activity.migrations.enabled=true`, package migrations own only the literal `activity_log` table on the default connection. The baseline certifies a compatible existing canonical table before adopting it, the v5 bridge adds `attribute_changes`, and rollback never drops activity evidence.
- Publishing `activity-migrations` transfers ownership to the application. Set `activity.migrations.enabled` to the boolean `false`, maintain the copied migration in the application, and never edit it after deployment.
- A custom table, custom connection, or existing table that fails canonical certification requires `activity.migrations.enabled=false` and an application-owned migration with frozen literal table and connection names.
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

- Use `ActivityLog::record(...)` or the underlying `ActivityRecorder` as the canonical writer.
- Use `ActivityEvent` for package-wide meanings shared across domains. Define a domain-owned string-backed enum for business-specific events rather than adding application vocabulary to the package enum.
- Omit `description` for normal writes; it defaults to the stable event key. Never use it to persist translated labels or final timeline sentences.
- Let `ActivityEvent::Updated`, `ActivityEvent::DetailsUpdated`, and status-change events infer `attributes` and `old` from the saved subject. Supply explicit arrays only for domain-specific or multi-model changes, and pair a complete explicit payload with `resolveChanges=false`.
- Use `HasModelActivity` only with a registered `ActivityMapping`; unmapped models intentionally remain silent.
- Each mapping implements all nine methods: model class, entity label, subject label, log name, Spatie options, field label, field value, event display value, and event templates.
- Store stable event keys and safe structured properties. `ActivityEvent` uses the existing event/description columns and requires no schema migration. Never store secrets, credentials, access tokens, full request payloads, or unredacted sensitive values.
- Integer, UUID, ULID, and string subject or causer identifiers are supported.
- `source`, `visibility`, and `importance` accept backed enums or exact canonical values. Unknown non-blank metadata raises `ActivityRecordingException` with `invalid_activity_metadata`/422; do not coerce or silently store it.
- Blank overrides use canonical defaults. Historical absent/blank visibility remains compatible, while every unknown non-blank visibility is excluded from signal timelines so reads fail closed.
- The recorder joins the caller's transaction only when activity and the business model use the same database connection. Separate connections are not atomic: choose and implement an explicit policy such as after-commit best-effort recording or an application-owned transactional outbox. Dispatch dependent work only after commit, and decide explicitly whether an activity failure may roll back the business mutation.

## Build semantic timelines

- Register consumer `ActivityMapping` implementations with `MappingRegistry`; keep models lean and resolve mutable labels, values, and templates outside storage.
- Add application-owned sources through `MergeableActivityData` DTOs and select them from a host model implementing `MergesActivity` with `MergesActivityTimeline`.
- Source DTOs collect and translate their own records; the host owns source composition and optional supersession; controllers return the finished DTOs.
- `null` means a complete subject timeline. A finite limit means the newest requested rows after visibility/signal filtering. Base reads use deterministic `(created_at, id)` keyset batches of 100 until the limit is satisfied or storage is exhausted.
- The merged host applies supersession, newest-first ordering, and the final finite limit. Extra collectors must implement the same null/finite contract.
- Return `ActivityItem` and related `Nvl.Activity.*` DTOs, not raw Spatie models or ad-hoc payloads. Use `HeadlineSegmentType` for backend segments; never return HTML or frontend presentation flags.

## Authorize and expose safely

- Package API routes fail closed. Configure real Gate names for `view`, `timeline`, and `purge`, then define each ability in the application, for example:

```php
Gate::define('activity.view', fn (User $user): bool => $user->can('audit activity'));
Gate::define('activity.timeline', fn (User $user, Model $subject): bool => $user->can('view activity', $subject));
Gate::define('activity.purge', fn (User $user): bool => $user->can('purge activity'));
```

- Doctor verifies configured names have real Gate definitions. A timeline subject must also resolve through the explicit model/morph-alias allowlist and implement `MergesActivity`.
- Keep `ForceActivityJsonResponse` on package routes. Return DTO data under `data`, stable machine codes under `code`, and localized safe copy under `message`.
- Use snake-case validation fields and camelCase mapped DTO output. Do not expose model classes, identifiers, SQL, or configuration internals in public error context.
- API inputs are bounded: index accepts optional search/event/causer/subject/date filters plus page size 1–100; timeline requires an allowlisted subject type and identifier with an optional 1–100 limit; causer suggestions accept search plus a 1–50 limit; both purge endpoints require `days` from `retention.allowed_purge_options`. Treat index `limit` as a page-size alias, not an unpaginated cap.

## Own strings and public contracts

- Store canonical event keys, enum values, response codes, and structured context; translate only at display, API, and console boundaries.
- Use `activity::activity/general.*` for package-owned server copy and keep English/Bulgarian keys and placeholders in exact parity.
- Shared templates precede consumer mapping templates; do not let an application replace package-wide semantics accidentally.
- Treat `ActivityEvent::Sent` and `ActivityEvent::Resent` as business actions on the subject. Keep mail transport delivery, opening, and retry lifecycle in `nvl/mail-notifications`.
- Treat response codes as opaque machine values. Regenerate and check `Nvl.Activity.*` declarations after changing a DTO or enum.
- Publish translations only for application copy overrides. Never store translated headlines or exception messages.

## Purge and operate safely

- Doctor is read-only. Run it in strict JSON mode after storage, authorization, queue, schedule, or cached-configuration changes.
- Preview retention with `nvl:activity:purge --dry-run`; use queued deletion for mutation. Important rows are protected by default, including system retention, and require explicit `include_important=true` API input or `--include-important` CLI opt-in. `nvl:activity:purge-system` and its schedule are opt-in.
- `PurgeActivityLogsJob` runs after commit, deletes in chunks of 1,000, and holds a distributed lock. Lock contention releases it for 60 seconds.
- All workers and schedulers must use the same canonical LockProvider-backed default cache. Use Redis, database, Memcached, DynamoDB, or another shared atomic-lock backend for multi-node operation; file is single-host only, and array/null are never production-safe. Cache failover cannot preserve one lock domain across partial failures, so strict Doctor rejects it outright.
- The time-based retry window covers one configured lock lifetime plus bounded execution retries, so repeated 60-second contention releases remain valid; five unhandled execution exceptions fail the job. Each attempt has a public 900-second timeout contract with failure-on-timeout and exception backoff of 60, 300, 900, and 1,800 seconds. Configure database, Redis, or Beanstalkd `retry_after` above 900 seconds. For SQS or a custom driver without `retry_after`, declare the externally configured value through `retention.external_visibility_timeout_seconds`. Doctor validates every target behind failover connections. Allow sufficient worker shutdown time.

## Verify and upgrade

From the suite root, run `composer quality` or its module-aware Pest command, then run Composer validation, dependency analysis, suite archive/clean-consumer checks, `nvl:activity:doctor --strict --format=json`, and `nvl:data:types:check` as applicable. Root consumer automation proves installation from the single relocated `nvl/laravel-suite` artifact, discovery, cached configuration/routes, canonical and custom-connection migration lifecycles, mapping registration, exact CRUD and structured capture, visibility filtering, complete and finite merged timelines, all five authenticated API endpoints, serialized purge scopes, and execution on a real database queue worker. Treat that smoke as a required production contract rather than replacing it with installation-only checks.

Coverage must include canonical model binding; literal migration up/down behavior; custom/adopted storage rejection; mapped create/update/delete; structured and batch writes; identifier/actor variants; invalid metadata; complete and finite post-filter timelines across keyset batches; real Gates and allowlists; JSON/API/error contracts; EN/BG parity; retention scopes and dry runs; job locking/retry/backoff/timeout; and after-commit dispatch.

Activitylog 4 is not a supported runtime. Before enabling v5 writes, explicitly require Activitylog 5, move host imports to the v5 namespaces, add the nullable JSON `attribute_changes` column, run strict Doctor, and verify historical `properties` fallback reads. When upgrading a consumer that published this skill, republish `activity-skills` with `--force` only after reviewing local changes, or manually merge the newer bundled skill into the customized application copy. Review `CHANGELOG.md` and `UPGRADING.md` before changing storage or public contracts.
