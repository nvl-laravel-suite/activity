# Upgrade to NVL Light

Target PHP 8.4, Laravel 13, Testbench 11 for development, and mandatory Spatie Activitylog v5 storage. The family remains version 6 during the coordinated suite conversion.

- Replace positional/facade recording with `ActivityRecorder::record($entry, $actor)`. Keys use snake_case. Actors are required persisted authenticatables or explicitly authorized `SystemActor::Background`. Scalar historical attribution belongs only in immutable envelopes. Blank events and unknown/protected fields now fail.
- Bind `ActivityAuthorization`. Replace HTTP gates with this native host policy plus consumer-owned transport authorization.
- Wrap mapped model mutations in `ActivityCaptureContext::run($actor, $operation)`. Preserve a host transaction when model/audit writes must roll back together. Unmapped models remain silent.
- Replace `ActivityReadService`, listing/causer Actions and read contracts with `ActivityReader::paginate`, `timeline`, `causers`. Use native models, collections and paginators. Map consumer DTOs explicitly.
- Replace `ModelActivityTimelineService`, `MergesActivityTimeline`, `ActivityTimelineData`, `MergesActivity`, `MergeableActivityData` and `TranslatesToActivity` presentation composition with native `ActivityTimelineSource` queries. Timelines now have explicit finite limits.
- Package Data, projection normalizers, response envelopes, headline segments, route providers/controllers/middleware, facade and per-Action interfaces are removed. Flat headline rendering, semantic mappings and diff arrays remain. Own presentation in the consumer.
- Replace `QueueActivityLogPurgeAction` with `ActivityPurgeDispatcher::dispatch($criteria, $actor)`. Replace old tenancy envelopes/worklists with native Ownership scopes and captured jobs. Old suffix event alias `ActivityLogPurgeQueuedEvent` is removed; subscribe to `ActivityLogPurgeQueued`.
- Remove `routes`, `authorization.abilities` and `tenancy` package configuration. No legacy aliases or automatic bridge authority remain. Own routes and response codes at the host boundary.
- `ActivityRecordEnvelope`, `ActivitySubjectReference` and `ActivityCauserReference` retain real immutable delivery/reference semantics. Replay checks operate on complete facts; redaction changes storage values without allowing conflicting deliveries to reuse an ID.

Drain obsolete queued payloads and restart workers before deploying changed contracts. No migration rewrites historical audit rows or transfers their owner. Review historical credentials separately; new recording redacts credential keys.
