# Security Policy

Version 1.0 is currently unreleased. The upcoming `1.x` line targets PHP 8.3–8.5 and Laravel 12–13; after release, security fixes are provided for the current `1.x` line.

Report vulnerabilities privately through the repository host's security-advisory feature. Do not open a public issue containing an exploit, personal data, credentials, storage paths, or audit payloads.

Include the affected version or commit, configuration, reproduction, impact, and any proposed mitigation. Maintainers will acknowledge the report, assess severity, prepare tests and a fix, and coordinate disclosure.

Treat activity rows as sensitive operational data. Never include secrets, credentials, access tokens, full request payloads, or unredacted personal data in properties. Package routes are disabled by default and must remain behind configured middleware, real named Gate abilities, and an explicit timeline-subject allowlist. The canonical writer rejects unknown non-blank `source`, `visibility`, and `importance` values before storage. For adopted historical rows, signal timelines fail closed only on visibility: absent or blank visibility remains compatible, exact lowercase `timeline` is included, and every other non-blank value is excluded. Historical source and importance values remain readable to authorized consumers, so validate or normalize them during adoption when their provenance is not trusted.

Run `nvl:activity:doctor --strict --format=json` after storage, route, authorization, queue, cache, or cached-configuration changes. Preview destructive retention with `nvl:activity:purge --dry-run`; configure the purge worker's `retry_after` above the job's 900-second timeout before enabling queued or scheduled deletion. Every worker and scheduler must share one canonical LockProvider-backed cache in multi-node deployments. File locks are single-host only, while `array`, `null`, and cache failover stores are never production-safe for purge serialization.
