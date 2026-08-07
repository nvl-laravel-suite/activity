# Contributing to NVL Activity

Changes must remain generic, internally isolated, and compatible with PHP 8.3–8.5 and Laravel 12–13. Do not add consumer models, event vocabulary, authorization assumptions, or presentation rules to the module.

Add or update Pest coverage for every behavior. From the suite root, run `composer quality` or the documented package-aware Pest command. Also run Composer validation, dependency analysis, suite distribution validation, and the relevant integration checks before submitting a change. Exercise installation and documented integration paths in a clean consumer when public module wiring changes.

The canonical activity model and package-managed migration are invariants. Keep `Nvl\Activity\Models\ActivityLog` as Spatie's activity model; do not restore an `activity.model` option. The vendor migration may own only the literal `activity_log` table on the default connection. Custom storage, adopted schemas, and published migrations belong to the application, require `activity.migrations.enabled=false`, and must use frozen literal connection and table targets. Never edit a migration that consumers may already have deployed.

Public APIs require typed parameters and returns, complete PHPDoc, a changelog entry, README and bundled-skill updates, and an upgrade note when compatibility changes. Keep English and Bulgarian translation keys and placeholders in exact parity, and regenerate/check `Nvl.Activity.*` declarations after changing a DTO or enum. New integrations must use contracts, registries, events, or explicit provider registration.
