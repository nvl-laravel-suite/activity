# Upgrading NVL Activity

## Consumer contracts, committed events and runtime policy (5.x)

Prefer focused public interfaces in constructor injection; native implementations remain container defaults and host prebindings win. Returned models are documented identity/data handles: use package contracts for reads/writes and capability-specific batch readers instead of direct package queries. Enable the shipped Core PHPStan include in your host; do not invoke the suite workbench static audit command in a consumer.

Events now carry immutable schemaVersion=1 and scalar/DTO snapshots. Replace model-bearing event fields with the IDs listed in [events](docs/events.md); load only through an authorized public reader when needed. Only six declared legacy `*Event` names are retained as PHP aliases for major 5, removal no earlier than major 6. Migrate exact imports/listeners/fakes to canonical names, replace suffix wildcard patterns explicitly, drain old queued payloads, rebuild event caches and restart workers. Framework Verified/PasswordReset remain native classes. Source-connection callbacks are process-local after-commit publication, not a durable outbox or exactly-once delivery.

Package failures have a marker and optional response metadata. Opt into Core's JSON renderer deliberately; preserve existing host handlers and request-locale selection. Missing required host adapters produce `binding_required`/500; genuine configured authorization denial retains native handling. See the README error table and required-bindings section where applicable.

Factories ship in runtime package mappings for host tests. Ordinary make may persist parents; withoutParents()->make creates detached fixtures. Supply persisted native owners/parents and active tenants explicitly, retain source revisions, and never treat a factory row as a real storage/provider/workflow effect. Core's optional installer publishes common config without enabling features; strict Doctor and explicit deployment cache/worker steps belong in the host release process. The PHP 8.4/Laravel 13 local Dagger release gate and fresh public Composer installation passed for 5.0.0. Additional compatibility legs need separate evidence; hosts must verify their own adoption.


For tenant retention, configure the explicit active-tenant worklist before
enabling scheduled purges. Drain envelope-less legacy purge jobs; new jobs are
tenant queued jobs and cannot run under unresolved context.

## Upgrading to 1.0

Version 1.0 removed consumer-specific mappings and model hooks, compatibility writers, mutable package-migration targets, TypeScript namespace assumptions, and route assumptions. It supports only Spatie Activitylog 5.x.

1. Update runtime and CI matrices to PHP 8.4–8.5 and Laravel 13.
2. Explicitly require `spatie/laravel-activitylog:^5.0`; v4 is not a supported runtime. Replace host imports for `LogsActivity`, `LogOptions`, and `ActivityLogger` with their v5 namespaces under `Models\Concerns` and `Support`, or use `Nvl\Activity\Traits\HasModelActivity` for mapped capture.
3. Remove any `activity.model` configuration. The provider always binds `Nvl\Activity\Models\ActivityLog` to `activitylog.activity_model`; consumer overrides are unsupported.
4. Decide who owns the migrations before running them:
   - For a clean canonical installation, keep the boolean `nvl-activity.migrations.enabled=true`; vendor migrations own literal `activity_log` on the default connection.
   - For a compatible existing canonical table, keep the switch enabled. The baseline migration certifies its shape without recreating it, the v5 bridge adds nullable JSON `attribute_changes` when absent, and rollback never drops created or adopted audit evidence.
   - If `nvl-activity-migrations` was published, set the boolean switch to `false`. The copied migration is application-owned and must not be edited after deployment.
   - For custom tables, custom connections, or an existing table that fails canonical certification, set the switch to `false` and use a new application-owned migration with frozen literal table and connection names in both `up()` and `down()`.
5. Never read a migration target from `config()`. If storage configuration changes later, rollback must still address the exact target originally created.
   A separate Activity storage connection cannot participate atomically in a transaction on the business connection. Before cutover, choose an explicit consistency policy such as after-commit best-effort recording or an application-owned transactional outbox.
6. Run `php artisan nvl:activity:doctor --strict --format=json`. Resolve the Activitylog major, required v5 `attribute_changes`, connection, UUID key, string morph identifier, JSON, index, binding, queue, retention-policy, and scheduling failures before cutover.
7. Keep application-specific adoption reversible until row counts, identifiers, representative structured properties, checksums, and rendered timelines match. A matching table name is not proof of schema compatibility. Historical v4 changes stored in `properties` remain readable after upgrading, but the fallback is not v4 write compatibility.
8. Register every auto-logged model through a complete nine-method `ActivityMapping`; unmapped `HasModelActivity` models intentionally remain silent.
9. Replace the removed compatibility `entry()` writer with `ActivityLog::record(...)`. Use `ActivityEvent` for package-wide meanings, omit `description` when the event key is sufficient, and define a domain string-backed enum for application-specific events.
10. No schema migration is required for `ActivityEvent`; it uses the existing `event` and `description` columns. For ordinary update and status events, let the recorder infer `attributes` and `old` from the saved subject. Keep explicit arrays for domain-specific or multi-model flows.
11. Send only canonical `ActivitySource`, `ActivityVisibility`, and `ActivityImportance` values. Unknown non-blank metadata now throws `ActivityRecordingException` with `invalid_activity_metadata` instead of being stored.
12. Move extra timeline sources to `MergeableActivityData` and host-owned `MergesActivityTimeline` composition. Preserve the read contract: `null` means complete, and finite limits apply after source filtering and final merge ordering.
13. Enable routes only after configuring non-empty management middleware, real named `view` / `timeline` / `purge` Gate definitions, and an explicit allowlist of models implementing `MergesActivity`. Re-run Doctor after `config:cache`.
14. Preview retention with `php artisan nvl:activity:purge --dry-run`. Important activity is excluded by default, including system-only and scheduled purges. Purging it requires explicit API `include_important=true` or CLI `--include-important`, and that choice is retained in queued results and operational audit context. Automatic system retention remains disabled by default.
15. Run workers for the effective Activity queue. Package `queue.connection` and `queue.name` overrides take priority, then Core queue defaults, then Laravel's selected connection and queue; null inherits and explicit `sync` is preserved. Move the deprecated `retention.queue` override to `queue.name` before major 6. Purge attempts time out at 900 seconds; a time-based window keeps lock-contention releases valid for at least one configured lock lifetime, while five unhandled execution exceptions remain the failure bound. The queue connection's `retry_after` must be greater than 900 seconds. Configure one canonical LockProvider-backed default cache shared by every worker and scheduler; file is single-host only, and array/null/failover are not production-safe.
16. Read purge response codes from top-level `code`; `message` is translated copy and purge metadata, including `includeImportant`, is represented by `ActivityPurgeQueuedResult` under `data`.
17. Treat `ActivityEvent`, `HeadlineSegment::type`, and `ActivityDoctorCheckData::severity` as backed enums in PHP. Their JSON and generated TypeScript values remain stable strings.
18. If the application overrides package copy, republish or merge `nvl-activity-translations`; validation, operational enum, Doctor, API, and scoped error keys are package-owned.
19. Republish `nvl-activity-skills` with `--force`, or manually merge the updated bundled skill when the consumer has customized its published copy. Review local changes before overwriting.
20. Regenerate and check `Nvl.Activity.*` TypeScript declarations, then run the isolated package quality gate and a clean consumer installation rehearsal.

Do not edit already-deployed package or application migrations. Add a new application migration for every later schema change and document any deliberate forward-only audit-evidence boundary.

## Shared Doctor integration

The loaded package provider now contributes its existing inspection checks to Core's `nvl:doctor --strict --format=json`. The package command remains available. The shared gate fails errors and, in strict mode, warnings; no data upgrade is required for diagnostics.

## Next major: isolated schema identities

This is a breaking schema identity change. Back up storage and migration history, pause writes/workers, install this code with automatic package migrations disabled, and select one owner for migrations (vendor or published).

```sh
php artisan nvl:doctor --strict --format=json
php artisan nvl:schema:upgrade --package=activity --claim-legacy --migration-owner=vendor --dry-run --format=json
php artisan nvl:schema:upgrade --package=activity --claim-legacy --migration-owner=vendor --format=json
```

The command validates released columns and relational keys plus creating migration history, renames owned legacy tables to the effective `tables.*` targets and rewrites exact package migration identities while retaining batches and unrelated host records. It refuses foreign/incomplete shapes and conflicting targets. Explicit old table mappings retain those names; remove them when choosing new defaults. A second run is empty.

Declare each published path and canonical identity explicitly in `nvl-core.migrations.published`; retimestamped history also needs an exact `legacy` mapping. Use `--migration-owner=vendor` after manually archiving declared copies outside loaded paths, or `--migration-owner=published` after manually replacing executable copies with current migration code and disabling vendor loading. The plan verifies ownership and preserves batches; checksums do not automatically claim files. Modified host copies remain host-owned. No migration files or stored morph types are rewritten.

DDL transactions are driver dependent and per connection. Inspect dry-run warnings for MySQL/MariaDB or split storage; after a failure, inspect completed steps before resuming. Schema-qualified rename targets require an explicit host schema move first. Re-enable your selected migration owner, run `nvl:schema:preflight` with the same selected paths and connection, then migrate remaining package changes and rerun Doctor before resuming writes. See the suite upgrade guide for shared owner/locale inputs, Core option defaults and one-major deprecation rules.

## Major 5 cache and lock identities

Purge locks use `nvl:activity:purge:` followed by the hash of the admitted tenant boundary identity instead of the generic `purge` key. Inherited cache stores and Laravel scheduler mutexes are preserved; generic host locks are never acquired or removed.

Drain old mutation workers and maintenance processes, then wait for their outstanding lock leases to end before starting the new major across all nodes. Running old and new lock prefixes concurrently would create independent serialization domains. Restart workers after cutover; preserve host-selected stores and keys, and do not flush a shared cache to remove old NVL entries.

## Tagged consumer PHP boundary

Use source `@api` workflows, extension contracts, and value types for application integration. Direct use of untagged implementations or `@internal` members is unsupported. This classification keeps existing concrete Action signatures and runtime behavior; it does not authorize package model persistence, ad hoc queries, relation traversal, or generic model serialization. Returned models are identity/result handles with only the explicitly declared in-memory read fields described in the README.

`HasModelActivity::activitiesAsSubject()` are internal storage or lifecycle seams. Migrate direct traversal, eager/lazy loading, and aggregate queries to the package authorized read Actions or explicit C1 host scopes/adapters. Native host queries and opted-in Translatable behavior remain supported.

## Major 5 focused workflow contracts

Change application constructor dependencies from `ListActivitiesAction`, `ListActivityCauserSuggestionsAction`, `ActivityReadService`, and `ActivityRecorder` to `Nvl\Activity\Contracts\ListActivitiesContract`, `ListActivityCauserSuggestionsContract`, `ActivityReadContract`, and `ActivityRecorderContract`, respectively. Continue using the existing `QueueActivityLogPurgeContract` for purge orchestration. Concrete classes, constructor parameters, final/readonly qualifiers, public method signatures, generic result types, and execution behavior remain available in major 5.

The recording contract exposes exactly `recordEnvelope`, `record`, and `recordForSubjectReference`; it retains Spatie's Activity return contract, including the nullable results of the latter two methods. The read contract exposes the existing nine instance methods. Raw `ActivityLog` results remain identity/result handles under the documented consumer boundary.

Provider defaults are conditional, including the existing purge contract and the reader/recorder concrete scoped registrations. Bind host interfaces or preconstructed public reader/recorder instances before discovery to retain them. New service contracts resolve the existing concrete through the same application container and retain its scoped lifetime. Internal Action/service chains keep their concrete dependencies.

`ActivityLog` now resolves `ActivityRecorderContract::class`. Move test substitutes previously bound only to `ActivityRecorder::class` to that interface. If a facade was resolved earlier, call `ActivityLog::clearResolvedInstance(ActivityRecorderContract::class)` after replacement. Resolve new host services after installing substitutes; earlier objects keep their original injected dependencies. When tests manually switch applications or scopes, clear the facade root separately. A host instance supplied before discovery retains the host's chosen lifetime.

See [Testing your app](README.md#testing-your-app) for native Mockery/container substitution and the opt-in `vendor/nvl/core/support/consumer-audit.neon` configuration. These additive contracts require no schema or data migration.
