# NVL activity events

This document describes the implemented source behavior. Acceptance is exercised by the owning package suites and Core committed-event regression tests; current release execution evidence is tracked in consumer-readiness.md. The authoritative machine-readable schema is [event-catalog.json](../resources/event-catalog.json), catalog version `1`. Event `schemaVersion` is independent of catalog version.

## Publication and listener timing

The native host dispatcher receives the captured event after the supplied source connection outer commit, or immediately when that connection has no active transaction.

Callbacks attach to the matching native connection and current nesting record; native outer/savepoint rollback discards the corresponding callbacks.

Missing source transaction records fail before commit; Mail Notifications reports and drops unusable observations.

Host after-commit listeners and queue after_commit policies can add their own deferral after publication. Host transaction infrastructure and dispatcher bindings are preserved.

Local callbacks are not an outbox. Process exit between commit and callback can lose delivery; no crash durability or exactly-once delivery is promised.

One canonical object is dispatched per qualifying producer call. This is local publication, not cross-process deduplication or a guarantee that repeated observations are unique.

Use Nvl\Support\Events\DomainEventDispatcher::dispatch($event, $writerConnection). Native Event::dispatch() is immediate and has no package interception.

## Payload security and no-op behavior

Purge criteria only; no deleted rows or actor model. The separately queued purge job captures its own tenant envelope; this event does not carry one.

A queued purge request is an observation, not a claim that purge completion occurred. Repeated requests can repeat the fact.

Actor/owner identifiers do not grant access. Listeners must preserve the captured ownership and apply their own authorization when reading storage. Readonly payload fields and native value objects are schema facts; public constructors with mixed arrays do not create a new recursive sanitization boundary. Package producer shapes are documented below; hosts must not attach models, mutable service objects or private arbitrary data.

## Canonical events

| Event | Schema version | Trigger |
| --- | --- | --- |
| [ActivityLogPurgeQueued](#activitylogpurgequeued) | 1 | Purge job queued with criteria. |

### ActivityLogPurgeQueued

`Nvl\Activity\Events\ActivityLogPurgeQueued` · [source](../src/Events/ActivityLogPurgeQueued.php) · event schema `1`.

Purge job queued with criteria.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$days` | `int` | public | `required` | — |
| `$systemOnly` | `bool` | public | `required` | — |
| `$includeImportant` | `bool` | public | `false` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$days` | `int` | — |
| `$systemOnly` | `bool` | — |
| `$includeImportant` | `bool` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Actions/Activity/QueueActivityLogPurgeAction.php](../src/Actions/Activity/QueueActivityLogPurgeAction.php) | `DB::connection(PackageStorage::connection('activity'))` |

Deprecated alias: `Nvl\Activity\Events\ActivityLogPurgeQueuedEvent` ([shim](../src/Events/ActivityLogPurgeQueuedEvent.php)). It is the same canonical class with this constructor.

## Major 5 listener and queue migration

Legacy names are deprecated for major 5 and removed no earlier than major 6. class_alias preserves imports, instanceof, listener type hints and the new versioned constructor, not the former model-bearing API.

The native Laravel exact-listener bridge reads getRawListeners at delivery time and prepares legacy listeners through makeListener; strict-identical canonical registrations are skipped. Late registration, subscribers, cached discovery and queued listener preparation use the native dispatcher seams covered by Core’s committed-event and native host transaction regressions.

One canonical object is emitted once. Native wildcard/interface listeners see the canonical event once; suffix-specific *Event wildcards must migrate. The bridge does not redispatch a legacy string.

Drain old queued model-bearing payloads and restart workers before upgrade. New serialized alias instances restore the canonical versioned class without model restoration.

Event fakes/filters and assertions use canonical class names. EventAliases::canonicalName() helps migrate old names; old fake filters are not automatically rewritten.

Host custom dispatchers are retained. Explicit EventAliases::listen($event, $listener) maps through the canonical adapter; absent native bridging/host adapter, Doctor reports the deprecated-name limitation.

```php
$aliases = app(\Nvl\Support\Events\EventAliases::class);
$canonical = $aliases->canonicalName($legacyClass);
$aliases->listen($canonical, $listener);
```

## Deferred acceptance checks

Final testing must compare catalog types/defaults/aliases with actual classes, recursively inspect producer payloads, and prove source outer commit, nested rollback, unrelated connection independence and retry behavior without an uncommitted test-harness transaction. Where applicable it must cover legacy exact/cached/queued listeners, canonical fakes and wildcard delivery, tenant capture, package no-op guards and observational failure containment. This document does not report those checks as passing.

## Consumer event assertions

Use the canonical event class listed in the catalog for `Event::fake([...])` and `Event::assertDispatched(...)`. Laravel fake filters compare the emitted class name; an old alias import does not rename that canonical object. Legacy exact listeners are bridged at delivery time through Laravel’s native dispatcher. Keep compatibility listener tests on their exact legacy name, and migrate suffix-specific wildcards to canonical names.

