# Upgrading NVL Activity

## Upgrading to 1.0

Version 1.0 removed consumer-specific mappings and model hooks, compatibility writers, mutable package-migration targets, TypeScript namespace assumptions, and route assumptions. It supports only Spatie Activitylog 5.x.

1. Update runtime and CI matrices to PHP 8.4–8.5 and Laravel 13.
2. Explicitly require `spatie/laravel-activitylog:^5.0`; v4 is not a supported runtime. Replace host imports for `LogsActivity`, `LogOptions`, and `ActivityLogger` with their v5 namespaces under `Models\Concerns` and `Support`, or use `Nvl\Activity\Traits\HasModelActivity` for mapped capture.
3. Remove any `activity.model` configuration. The provider always binds `Nvl\Activity\Models\ActivityLog` to `activitylog.activity_model`; consumer overrides are unsupported.
4. Decide who owns the migrations before running them:
   - For a clean canonical installation, keep the boolean `activity.migrations.enabled=true`; vendor migrations own literal `activity_log` on the default connection.
   - For a compatible existing canonical table, keep the switch enabled. The baseline migration certifies its shape without recreating it, the v5 bridge adds nullable JSON `attribute_changes` when absent, and rollback never drops created or adopted audit evidence.
   - If `activity-migrations` was published, set the boolean switch to `false`. The copied migration is application-owned and must not be edited after deployment.
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
15. Configure a real maintenance queue. Purge attempts time out at 900 seconds; a time-based window keeps lock-contention releases valid for at least one configured lock lifetime, while five unhandled execution exceptions remain the failure bound. The queue connection's `retry_after` must be greater than 900 seconds. Configure one canonical LockProvider-backed default cache shared by every worker and scheduler; file is single-host only, and array/null/failover are not production-safe.
16. Read purge response codes from top-level `code`; `message` is translated copy and purge metadata, including `includeImportant`, is represented by `ActivityPurgeQueuedResult` under `data`.
17. Treat `ActivityEvent`, `HeadlineSegment::type`, and `ActivityDoctorCheckData::severity` as backed enums in PHP. Their JSON and generated TypeScript values remain stable strings.
18. If the application overrides package copy, republish or merge `activity-translations`; validation, operational enum, Doctor, API, and scoped error keys are package-owned.
19. Republish `activity-skills` with `--force`, or manually merge the updated bundled skill when the consumer has customized its published copy. Review local changes before overwriting.
20. Regenerate and check `Nvl.Activity.*` TypeScript declarations, then run the isolated package quality gate and a clean consumer installation rehearsal.

Do not edit already-deployed package or application migrations. Add a new application migration for every later schema change and document any deliberate forward-only audit-evidence boundary.
