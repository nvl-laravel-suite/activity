# Upgrading NVL Activity

## Upgrading to 1.0

Version 1.0 remains unreleased. It removes consumer-specific mappings and model hooks, compatibility writers, mutable package-migration targets, TypeScript namespace assumptions, and route assumptions.

1. Update runtime and CI matrices to PHP 8.3–8.5 and Laravel 12–13.
2. Remove any `activity.model` configuration. The provider always binds `Nvl\Activity\Models\ActivityLog` to `activitylog.activity_model`; consumer overrides are unsupported.
3. Decide who owns the migration before running it:
   - For a clean canonical installation, keep the boolean `activity.migrations.enabled=true`; the vendor migration owns literal `activity_log` on the default connection.
   - If `activity-migrations` was published, set the boolean switch to `false`. The copied migration is application-owned and must not be edited after deployment.
   - For custom tables, custom connections, or an existing Spatie table, set the switch to `false` and use an application-owned migration with frozen literal table and connection names in both `up()` and `down()`.
4. Never read a migration target from `config()`. If storage configuration changes later, rollback must still reverse the exact target originally created.
   A separate Activity storage connection cannot participate atomically in a transaction on the business connection. Before cutover, choose an explicit consistency policy such as after-commit best-effort recording or an application-owned transactional outbox.
5. Run `php artisan nvl:activity:doctor --strict --format=json`. Resolve connection, UUID key, string morph identifier, JSON, index, binding, queue, and scheduling failures before cutover.
6. Keep application-specific adoption reversible until row counts, identifiers, representative structured properties, checksums, and rendered timelines match. A matching table name is not proof of schema compatibility.
7. Register every auto-logged model through a complete nine-method `ActivityMapping`; unmapped `HasModelActivity` models intentionally remain silent.
8. Replace the removed compatibility `entry()` writer with `ActivityLog::record(...)`.
9. Send only canonical `ActivitySource`, `ActivityVisibility`, and `ActivityImportance` values. Unknown non-blank metadata now throws `ActivityRecordingException` with `invalid_activity_metadata` instead of being stored.
10. Move extra timeline sources to `MergeableActivityData` and host-owned `MergesActivityTimeline` composition. Preserve the read contract: `null` means complete, and finite limits apply after source filtering and final merge ordering.
11. Enable routes only after configuring non-empty management middleware, real named `view` / `timeline` / `purge` Gate definitions, and an explicit allowlist of models implementing `MergesActivity`. Re-run Doctor after `config:cache`.
12. Preview retention with `php artisan nvl:activity:purge --dry-run`; automatic system retention remains disabled by default.
13. Configure a real maintenance queue. Purge attempts time out at 900 seconds; a time-based window keeps lock-contention releases valid for at least one configured lock lifetime, while five unhandled execution exceptions remain the failure bound. The queue connection's `retry_after` must be greater than 900 seconds. Configure one canonical LockProvider-backed default cache shared by every worker and scheduler; file is single-host only, and array/null/failover are not production-safe.
14. Read purge response codes from top-level `code`; `message` is translated copy and purge metadata is represented by `ActivityPurgeQueuedResult` under `data`.
15. Treat `HeadlineSegment::type` and `ActivityDoctorCheckData::severity` as backed enums in PHP. Their JSON and generated TypeScript values remain the same strings.
16. If the application overrides package copy, republish or merge `activity-translations`; validation, operational enum, Doctor, API, and scoped error keys are package-owned.
17. Republish `activity-skills` with `--force`, or manually merge the updated bundled skill when the consumer has customized its published copy. Review local changes before overwriting.
18. Regenerate and check `Nvl.Activity.*` TypeScript declarations, then run the isolated package quality gate and a clean consumer installation rehearsal.

Do not edit already-deployed package or application migrations. Add a new reversible application migration for every later schema change.
