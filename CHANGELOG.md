# Changelog

All notable changes to `nvl/activity` are documented here.

## [Unreleased]

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
