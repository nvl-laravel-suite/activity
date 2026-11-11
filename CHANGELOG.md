# Changelog


All notable changes to `nvl/activity` are documented here.

## [Unreleased]

### Fixed

- Keep builder-level `forceDelete()`, `updateOrInsert()` and `updateFrom()` on the activity log inside the ownership boundary by adopting Core's owned write trait on the activity log builder. Require Core 7.0.6.
- Record the `deleted` fact when the capture actor deletes or soft-deletes its own record. The actor admitted before the write is delivered as the causer instead of failing the persisted-actor check after its row is gone.
- Store envelope facts in the application timezone like every other fact, and convert offset-bearing `created_at_from` / `created_at_to` filters, date-range scopes and purge cutoffs to it before comparing.
- Bind subject and causer identifiers as strings in reader filters, builder scopes, audience predicates and the `activitiesAsSubject` relation so integer keys keep the varchar indexes usable and valid on PostgreSQL.
- Return `null` from `ActivityReader::find()` for a malformed identifier instead of querying the UUID key.
- Stop capturing unchanged fields in `updated` facts when a resubmitted value is equivalent to the stored original, such as `"5"` for `5`.
- Let the `(created_at, id)` index serve newest-first reads on MySQL, MariaDB and SQLite.
- Add a PostgreSQL `created_at DESC NULLS LAST, id DESC` index and emit the matching plain `ORDER BY` so newest-first reads on PostgreSQL use an index instead of sorting through a `CASE` expression. Run the new migration; `nvl:activity:doctor` reports the index.
- Bound each `PurgeActivityLogsJob` run to 10,000 rows and continue in a new job from a `(created_at, id)` cursor, so a purge no longer runs into its timeout while holding the purge lock.
- Redact metadata keys by whole segment: `token`, `api_token` and `accessToken` are redacted, while `token_count`, `authorization_status` and `secretary_id` are no longer replaced by `[REDACTED]`.
- Remove the unused `CauserNormalizer` and the `causer_suggestions.label_attribute` / `sublabel_attribute` settings, which never affected the fixed safe causer projection.
- Compare integer subject keys as text in `whereHas('activitiesAsSubject')`, `whereDoesntHave()` and `withCount()`, so they work on PostgreSQL.
- Recognise a retried envelope whose fact was stored with the earlier UTC wall clock on a non-UTC application as the same fact instead of reporting a conflict.
- Read causer suggestions from the newest `scan_limit` attributed facts instead of aggregating the whole log on every call.
- Limit `description` to 65,535 bytes, the capacity of the `TEXT` column, instead of 65,535 characters.
- Remove `retention.external_visibility_timeout_seconds`; the Doctor now reads the visibility timeout from the queue connection's `retry_after`.
- Reject page offsets that overflow instead of returning the first page, name at most five fields in an update headline and quote at most 255 characters of a changed value.
- Remove the no-op `TimelineFilter::deduplicateByTimestamp()`, the provider's `provides()` list and empty `src/Http`, `routes` and `database/tenancy-migrations` directories.

## [7.0.1] - 2026-11-09

### Changed

- Refresh the inherited Ownership deletion API reference for ordinary models and native pivots.

<!-- NVL GENERATED CHANGES START -->
### Changed

- refresh inherited ownership deletion contracts
<!-- NVL GENERATED CHANGES END -->

## [7.0.0] - 2026-11-03

- Adopt the reviewed NVL 7 family for PHP 8.4+ and Laravel 13, with compatible required and optional NVL peers. Review the package upgrade guide before deployment.

- Replace Spatie Activitylog with native Eloquent storage, recording, explicit-field CRUD capture and canonical diffs.
- Add closed event policies for admitted fields, attribution, redaction, SQL audience filtering and minimum retention.
- Enforce append-only Eloquent mutation boundaries and retain immutable envelope replay checks and optional Ownership.
- Add precise keyset pagination and authorized individual reads; remove the v4/v5 migration bridge and global adoption switch.
- Bound literal Activity/causer search terms and escape SQL wildcards consistently on supported databases; remove advertised configuration defaults without runtime readers.

## [6.0.0] - 2026-10-20

### Release fixes

- Preserve host bindings when registering the canonical ownership guard alias through the collision boundary.

### Changed

- Capture native owner identity for logs, timelines, purge operations and worker restoration. Preserve canonical subject admission and bounded batch authorization.
- Align required/development NVL peers on `^6.0`, optional peer conflicts on major 6, and `dev-main` on `6.x-dev`. Review [UPGRADING.md](UPGRADING.md) before rollout.

## [5.0.1] - 2026-10-08

### Changed

- Correct published-family verification and adoption guidance for local Dagger CI; runtime contracts are unchanged.


## [5.0.0] - 2026-10-08

### Added

- Focused `ListActivitiesContract`, `ListActivityCauserSuggestionsContract`,
  `ActivityReadContract`, and `ActivityRecorderContract` interfaces with the
  existing native signatures and generic result contracts, plus host-service
  substitution examples and binding/lifetime regression coverage.

### Changed

- Activity facade recording now resolves `ActivityRecorderContract`. Public
  workflow defaults, including `QueueActivityLogPurgeContract` and the scoped
  reader/recorder concretes, preserve host bindings through conditional
  registration while retaining existing execution and constructor behavior.

- Classify the supported consumer PHP surface with explicit source annotations and restrict package model handles to declared identity and in-memory read fields; preserve existing workflow behavior and concrete signatures.
- Isolate owned cache and lock keys under `nvl:activity:`; preserve generic host entries and explicit store choices.

- Adopt lockstep major 5 with required and development NVL peer floors of `^5.0`.
- Record package activity independently of the host Spatie model setting; global model adoption is explicit.
- Use namespaced global surfaces and inherited queue destinations for maintenance.
- Review [UPGRADING.md](UPGRADING.md) before adopting the new names and infrastructure boundaries.

## [2.3.0] - 2026-09-28

### Added

- Add an immutable activity record envelope with explicit causer references and idempotent UUID recording for durable cross-connection delivery.

## [2.2.2] - 2026-09-26

### Documentation

- Clarify public support, contribution, and private security reporting paths.

## [2.2.1] - 2026-09-25

### Documentation

- Correct installation and security guidance now that the standalone package is published.

## [2.2.0] - 2026-09-25

### Changed

- Prepare `nvl/activity` for independent Composer and Git publication; require `nvl/core` for shared Support and Data services.

## [2.1.1] - 2026-09-23

### Changed

- Replace Activity API Form Requests with validated Data query and purge
  contracts. Keep policy authorization in the HTTP controllers, translated
  validation messages, query aliases, and existing response shapes.

## [2.0.1] - 2026-09-22

### Added

- Partitioned purge candidates, locks, deletion counts, and queued work by
  captured tenant ownership with explicit platform worklist enumeration.

### Fixed

- Restrict malformed historical visibility metadata to audit-only presentation,
  preserving compatibility for absent, null, and blank string values.

## [2.0.0] - 2026-08-29

### Changed

- Established `ActivityLog`, `ActivityReadService`, stable subject references,
  and documented model activity traits as the 2.0 consumer boundary; direct
  consumer Activity-model queries now fail Suite audit.

## [1.0.5] - 2026-08-12

### Changed

- Clarified and verified package-owned retention scheduling, distributed
  overlap guards, and feature-gated readiness.

## [1.0.2] - 2026-08-12

- Recognized MariaDB's native `longtext` JSON schema metadata in Doctor and
  canonical-table adoption checks.
- Enforced Spatie Activitylog 5.x as the only supported major, raised the suite and Activity PHP floor to 8.4, removed the v4 namespace shim, required the v5 `attribute_changes` schema in Doctor, and documented the explicit consumer namespace/schema upgrade.
- Made the package migration certify and baseline a compatible existing canonical `activity_log` table without destructive rollback, with a forward-only v5 bridge that adds `attribute_changes` when absent.
- Protected important activity from every purge by default and added explicit, auditable API and CLI opt-ins that propagate through criteria, queued DTOs, events, and job logs.
- Registered migration publication through Laravel's timestamp-aware API and made Doctor warn when automatic vendor loading overlaps a published host copy.
- Added the package-owned `ActivityEvent` enum with localized English/Bulgarian labels and shared headline templates, made recorder descriptions optional machine-key fallbacks, and kept ordinary update/status diffs automatic without a schema migration.
- Supported PHP 8.4–8.5 and Laravel 12–13.
- Made `Nvl\Activity\Models\ActivityLog` the canonical non-configurable Spatie activity model and removed the legacy `activity.model` override.
- Froze package-managed migrations to literal `activity_log` storage on the default connection; custom or incompatible storage requires disabled package migrations plus application-owned migrations with immutable targets.
- Tightened Doctor to reject stringly typed switches, unavailable connections, mutable managed storage, legacy model overrides, undefined Gate abilities, and invalid timeline hosts.
- Made nested consumer configuration preserve missing map defaults while replacing numeric list settings atomically instead of retaining default entries by index.
- Made complete subject reads use deterministic keyset batches and apply finite limits only after visibility and signal filtering.
- Made merged finite timelines backfill older base rows after richer-source supersession, preserved old-only diffs, and isolated malformed historical morph identifiers before eager loading.
- Declared merged timeline source iterators with array-compatible key types so consumer models remain valid under maximum-level PHPStan analysis.
- Made subject and causer hydration query each related model on its own declared or default connection so a dedicated Activity storage connection does not redirect application models to the audit database.
- Documented that recording is transaction-atomic only on the same database connection, with a regression proving the cross-connection rollback boundary.
- Replaced finite purge attempts with a lock-aware retry window plus five-exception bound, and removed the ineffective per-write `ActivityLogger::onTable()` macro in favor of canonical configured storage.
- Canonicalized historical integer, UUID, and ULID identifiers and enforced native database integer widths before polymorphic, timeline, or causer-suggestion queries.
- Recognized PostgreSQL `int2`, `int4`, and `int8` schema names when validating integer morph identifiers across database drivers.
- Rejected unknown non-blank source, visibility, and importance metadata and made historical non-canonical visibility fail closed.
- Forced package JSON negotiation ahead of authentication middleware so anonymous API calls cannot fall through to missing web login routes.
- Rejected malformed runtime storage values instead of silently falling back, and made strict Doctor readiness reject sync queues for every purge entrypoint.
- Added purge lock requeueing, a lock-aware retry window with five-exception failure bounds, a 900-second fail-on-timeout policy, exponential backoff, and explicit queue/cache safety guidance.
- Made the purge timeout a public runtime contract and made Doctor reject non-durable queues, short `retry_after` values, undeclared external visibility windows, and unsafe failover targets.
- Expanded consumer documentation and the bundled skill with complete mappings, merged-source composition, configuration, migration ownership, authorization, operations, and verification contracts.
- Added source and relocated-artifact consumer smokes for canonical and custom-connection storage, exact capture semantics, all management APIs, queue payloads, and real worker execution.
- Prevented package migrations from silently adopting incompatible pre-existing activity tables or dropping created/adopted audit evidence during rollback.
- Made automatic system retention opt-in and separated system origin from audit visibility.
- Wired `ActivityMapping` into `HasModelActivity` capture with safe silent defaults for unmapped models.
- Expanded Doctor to validate identifiers, JSON storage, indexes, Spatie compatibility, routes, queue, and scheduling.
- Replaced authorization callbacks with cacheable named abilities and explicit timeline subject allowlists.
- Removed description-based event inference, consumer-specific event templates, the compatibility entry builder, and the duplicate global timeline aggregator.
- Added configurable causer presentation, morph-map-aware mappings, bounded reads, portable JSON queries, and feed/purge indexes.
- Made activity index filters transport-neutral and kept HTTP normalization in the API controller.
- Removed the redundant causer controller, flat-headline compatibility wrapper, duplicate selector value, and mail-specific top-level timeline metadata.
- Added the `activity-migrations` publish tag and documented every distribution tag.
- Centralized API, validation, operational enum, doctor, console, and scoped error copy in parity-checked English and Bulgarian catalogs.
- Added typed doctor severity and headline segment enums plus a generated `ActivityPurgeQueuedResult` API DTO.
- Added scoped BusinessException-based configuration, recording, purge-criteria, and timeline failures with safe public and diagnostic context separation.
- Separated stable purge response codes from translated messages and made missing timeline errors safe and coded.
- Forced JSON negotiation across package routes before validation and authorization failures are rendered.
- Expanded the README and bundled agent skill with localization, error, enum, DTO, and API-boundary doctrine.

## [1.0.0] - 2026-08-08

- Added generic structured audit capture and semantic timeline registries.
- Added safe Spatie Activitylog schema adoption diagnostics.
- Added authorized, opt-in APIs and locked, chunked purge operations.
- Standardized DTOs under `Nvl.Activity.*` and commands under `nvl:activity:*`.
