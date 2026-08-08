# NVL Activity — API and usage

[← NVL Laravel Suite](../../../README.md)

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/laravel-suite:^1.0` |
| Module identifier | `nvl/activity` |
| PHP namespace | `Nvl\Activity` |
| Service provider | `Nvl\Activity\Providers\ActivityServiceProvider` |
| Configuration | `config/activity.php` |

## Purpose

`nvl/activity` provides generic structured audit capture and readable semantic timelines for Laravel 12–13 on PHP 8.3–8.5. It builds on Spatie Activitylog without embedding application event names, models, labels, or business rules.

Activity depends on `nvl/data` and `nvl/support`. It supports compatible Spatie Activitylog 4.x and 5.x schemas. It is not event sourcing, workflow orchestration, authorization policy, or a domain event replacement.

## Requirements and installation

Version 1.0 is currently unreleased. This monorepo consumes `dev-main` through a Composer path repository. After 1.0 is published, applications can install the stable release with:

```bash
composer require nvl/laravel-suite:^1.0
php artisan migrate
```

Laravel auto-discovers `ActivityServiceProvider`. On a clean installation, the package-managed vendor migration owns exactly the literal `activity_log` table on the application's default database connection. It uses a UUID activity primary key and string-compatible morph identifiers for integer, UUID, ULID, and string subject or causer keys.

Optional publish tags:

```bash
php artisan vendor:publish --tag=activity-config
php artisan vendor:publish --tag=activity-migrations
php artisan vendor:publish --tag=activity-translations
php artisan vendor:publish --tag=activity-skills
```

Publishing is an ownership transfer:

- The package-loaded vendor migration is maintained by the package and must remain enabled only for the canonical `activity_log` table on the default connection.
- A migration copied with `activity-migrations` becomes application-owned. Set `activity.migrations.enabled` to the boolean `false` so the package does not also load its vendor copy, and do not edit the published migration after it has been deployed.
- Custom tables, custom connections, and pre-existing Spatie tables always require `activity.migrations.enabled=false` plus an application-owned migration whose `up()` and `down()` methods use frozen literal table and connection names. Never resolve a migration target from mutable runtime configuration.

Run the read-only Doctor before altering an adopted table so it can inventory the existing compatibility gaps. For a brand-new custom schema, create and migrate the application-owned schema first because Doctor cannot report healthy until the configured table exists. Run Doctor after migration and before cutover in both cases:

```bash
php artisan nvl:activity:doctor --strict --format=json
```

The `Nvl\Activity\Models\ActivityLog` model is canonical and non-configurable. The provider always binds it to `activitylog.activity_model`; do not define the removed `activity.model` key or override Spatie's activity model binding.

### Custom storage or existing-table adoption

Disable package migrations first:

```php
// config/activity.php
'migrations' => [
    'enabled' => false,
],
'storage' => [
    'connection' => 'audit',
    'table' => 'audit_activity_log',
],
```

Then create an application migration with immutable targets:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string CONNECTION = 'audit';

    private const string TABLE = 'audit_activity_log';

    public function up(): void
    {
        Schema::connection(self::CONNECTION)->create(self::TABLE, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('event')->nullable();
            $table->string('causer_type')->nullable();
            $table->string('causer_id')->nullable();
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['causer_type', 'causer_id']);
            $table->index(['created_at', 'id']);
            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection(self::CONNECTION)->dropIfExists(self::TABLE);
    }
};
```

For an adopted table, replace `create()` with reversible alteration steps against the same frozen literal targets. An existing table with the same name is not automatically compatible. Keep adoption changes reversible and compare row counts, identifiers, representative structured properties, and rendered timelines before cutover.

## Record structured activity

```php
use Nvl\Activity\Enums\ActivityImportance;
use Nvl\Activity\Enums\ActivityEvent;
use Nvl\Activity\Enums\ActivityVisibility;
use Nvl\Activity\Facades\ActivityLog;

$article->forceFill(['status' => 'published'])->save();

$activity = ActivityLog::record(
    subject: $article,
    event: ActivityEvent::StatusChanged,
    context: ['channel' => 'website'],
    actor: auth()->user(),
    visibility: ActivityVisibility::Timeline,
    importance: ActivityImportance::Important,
);
```

`ActivityEvent` exposes the package-wide vocabulary for meanings that stay consistent across domains: `Created`, `Updated`, `Deleted`, `Restored`, `Assigned`, `DetailsUpdated`, `StatusChanged`, `StatusTransition`, `StatusOverride`, `Viewed`, `Activated`, `Deactivated`, `Enabled`, `Disabled`, `Archived`, `Unarchived`, `Triggered`, `Retried`, `Sent`, and `Resent`. Use a domain-owned string-backed enum for business-specific events that do not belong in that shared vocabulary.

The recorder stores only the enum's stable value, such as `status_changed`. The optional `description` defaults to that same machine key; do not persist translated labels or final sentences there. English and Bulgarian labels and headlines are resolved from the package catalogs when the activity is read.

For `Updated`, `DetailsUpdated`, and status-change events, the recorder derives `attributes` and `old` from the saved subject's Eloquent changes. Pass explicit arrays only for domain-specific or multi-model flows where the subject cannot provide the correct diff, and set `resolveChanges: false` when deliberately supplying the complete payload yourself.

Adding or adopting `ActivityEvent` requires no migration and no additional column. It uses the existing `event` and `description` fields. A blank event key records nothing and returns `null`. Avoid secrets, credentials, full request payloads, and unredacted personal data.

`source`, `visibility`, and `importance` accept their backed enums or exact canonical values. Unknown non-blank values are rejected with `ActivityRecordingException`, response code `invalid_activity_metadata`, and suggested HTTP status `422`; they are never stored and made visible accidentally. Blank overrides use canonical defaults. Historical rows with absent or blank visibility remain readable for compatibility, but any non-blank visibility other than exact lowercase `timeline` is excluded from signal timelines.

`ActivityRecorder` is the canonical writer. `ActivityLog` provides a facade over the same service. The v1 API has no compatibility writers or application-specific activity factories.

## Automatic model changes

Use `HasModelActivity` for narrow Eloquent create, update, and delete capture. A registered `ActivityMapping` is required and owns the Spatie `LogOptions` and log name. Unmapped models remain silent so importing the trait never creates broad or empty records accidentally.

```php
use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Traits\HasModelActivity;

final class Article extends Model
{
    use HasModelActivity;
}
```

Keep models lean: the trait captures only the mapped fields, while mappings and timeline services own labels, value formatting, and semantic presentation.

## Register semantic mappings

Implement all nine `ActivityMapping` methods for each automatically captured model. This complete example can be adapted directly:

```php
<?php

declare(strict_types=1);

namespace App\Activity;

use App\Models\Article;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Nvl\Activity\Contracts\ActivityMapping;
use Spatie\Activitylog\Support\LogOptions;

final class ArticleActivityMapping implements ActivityMapping
{
    public function modelClass(): string
    {
        return Article::class;
    }

    public function entityLabel(): string
    {
        return 'Article';
    }

    public function subjectLabel(Model $subject): string
    {
        $title = $subject->getAttribute('title');

        return is_string($title) && trim($title) !== '' ? $title : 'Article';
    }

    public function logName(): string
    {
        return 'articles';
    }

    public function logOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'status'])
            ->logOnlyDirty();
    }

    public function fieldLabel(string $key): string
    {
        return match ($key) {
            'title' => 'Title',
            'status' => 'Status',
            default => Str::headline($key),
        };
    }

    public function fieldValue(string $key, mixed $value): string
    {
        if ($key === 'status' && is_string($value)) {
            return match ($value) {
                'draft' => 'Draft',
                'published' => 'Published',
                default => Str::headline((string) $value),
            };
        }

        return is_scalar($value) || $value === null
            ? (string) $value
            : '[structured value]';
    }

    public function eventDisplayValue(string $event, array $properties): ?string
    {
        if ($event !== 'article.published') {
            return null;
        }

        $context = $properties['context'] ?? null;
        $channel = is_array($context) ? ($context['channel'] ?? null) : null;

        return is_string($channel) && trim($channel) !== ''
            ? Str::headline($channel)
            : null;
    }

    public function eventTemplates(): array
    {
        return [
            'article.published' => ':actor published this :subject on :value.',
        ];
    }
}
```

Register the mapping from an application provider:

```php
use App\Activity\ArticleActivityMapping;
use Nvl\Activity\Services\MappingRegistry;

public function boot(MappingRegistry $activityMappings): void
{
    $activityMappings->register(new ArticleActivityMapping());
}
```

Mappings provide model class, entity and subject labels, log name, Spatie options, field labels, formatted values, event display values, and headline templates. Localize consumer-owned labels and templates according to the active application locale when the timeline is multilingual.

## Merge host-owned timeline sources

Extra sources implement `MergeableActivityData`, translate themselves to `ActivityItem`, and are selected by the host model through `MergesActivityTimeline`. Raw `ActivityLog` rows remain distinct from display DTOs.

This example translates application-owned article notes and composes them with the base audit feed:

```php
<?php

declare(strict_types=1);

namespace App\Data\Activity;

use App\Models\Article;
use App\Models\ArticleNote;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nvl\Activity\Contracts\MergeableActivityData;
use Nvl\Activity\Data\Display\ActivityItem;
use Nvl\Activity\Data\Display\ActivityItemProperties;
use Nvl\Activity\Enums\EntrySource;
use Spatie\LaravelData\Data;

final class ArticleNoteActivityData extends Data implements MergeableActivityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $body,
        public readonly ?string $createdAt,
    ) {}

    public static function collectToActivityFor(Model $subject, ?int $limit = null): Collection
    {
        if (! $subject instanceof Article || ($limit !== null && $limit <= 0)) {
            return collect();
        }

        $query = ArticleNote::query()
            ->where('article_id', $subject->getKey())
            ->latest('created_at')
            ->latest('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->map(static function (ArticleNote $note): ActivityItem {
            $body = $note->getAttribute('body');

            return (new self(
                id: (string) $note->getKey(),
                body: is_string($body) ? $body : '',
                createdAt: $note->created_at?->toIso8601String(),
            ))->toActivityItem();
        });
    }

    public function toActivityItem(): ActivityItem
    {
        return new ActivityItem(
            id: 'article-note:'.$this->id,
            log: 'article_notes',
            event: 'article.note_added',
            source: EntrySource::Comment,
            description: 'Article note added',
            createdAt: $this->createdAt,
            headline: 'A note was added.',
            properties: ActivityItemProperties::fromPayload([
                'resource_type' => ArticleNote::class,
                'resource_id' => $this->id,
                'context' => ['body' => $this->body],
            ]),
        );
    }
}
```

The host owns source selection and optional supersession of a less useful base event:

```php
use App\Data\Activity\ArticleNoteActivityData;
use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Contracts\MergesActivity;
use Nvl\Activity\Data\Display\ActivityItem;
use Nvl\Activity\Enums\EntrySource;
use Nvl\Activity\Traits\HasModelActivity;
use Nvl\Activity\Traits\MergesActivityTimeline;

final class Article extends Model implements MergesActivity
{
    use HasModelActivity;
    use MergesActivityTimeline;

    /** @return array<int, iterable<int|string, ActivityItem>> */
    protected function mergedActivitySources(?int $limit = null): array
    {
        return [
            ArticleNoteActivityData::collectToActivityFor($this, $limit),
        ];
    }

    /** @return array<string, array<int, string>> */
    protected function mergedActivitySupersededBaseEvents(): array
    {
        return [
            EntrySource::Comment->value => ['note_recorded'],
        ];
    }
}
```

Omit `mergedActivitySupersededBaseEvents()` when no richer source replaces a base event. Source translators own collection and translation; the host owns composition; controllers only return the completed DTOs.

### Timeline limits

`null` always means a complete subject timeline. A finite limit means the newest requested number of rows **after** visibility and signal filtering, not merely the first raw database rows. Base activity reads use deterministic `(created_at, id)` keyset batches of 100 and continue until the visible limit is satisfied or storage is exhausted. The merged host then applies supersession, newest-first ordering, and the final finite limit. Extra source collectors must follow the same `null = complete` and finite-limit contract.

Use `ListActivitiesAction`, `ActivityReadService`, `ActivityTransformService`, or `ModelActivityTimelineService` for read paths. Eager-loading and bounded pagination avoid subject and causer N+1 queries.

## Routes and authorization

Management routes are disabled by default:

```php
'routes' => [
    'enabled' => false,
    'prefix' => 'api/v1',
    'middleware' => ['api'],
    'management_middleware' => ['auth', 'throttle:60,1'],
    'timeline_subjects' => [Article::class],
],
'authorization' => [
    'abilities' => [
        'view' => 'activity.view',
        'timeline' => 'activity.timeline',
        'purge' => 'activity.purge',
    ],
],
```

Define the configured abilities in an application provider. Doctor verifies both the configuration strings and real Gate definitions:

```php
use App\Models\Article;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

Gate::define('activity.view', static fn (User $user): bool => $user->can('audit activity'));
Gate::define(
    'activity.timeline',
    static fn (User $user, Model $subject): bool => $subject instanceof Article
        && $user->can('view activity', $subject),
);
Gate::define('activity.purge', static fn (User $user): bool => $user->can('purge activity'));
```

When enabled, routes use `/api/v1/activities` and stable `nvl.activity.activities.*` names. Authorization fails closed unless all three named abilities are configured and defined. Subject timelines additionally require an explicit model or morph-alias allowlist containing only models that implement `MergesActivity`. The package assumes no concrete user model, middleware alias beyond configured values, or identifier type.

Causer suggestions are optional. Configure an Eloquent model and allowlisted label/search attributes, or allow the package to inspect the configured Eloquent auth provider. If no compatible model exists, suggestions return an empty collection.

### API request contracts

Every path below is relative to the configured route prefix, which is `/api/v1` by default. Canonical request fields are snake-case; the documented camelCase aliases are accepted only when the canonical field is absent.

| Method and path | Request contract |
| --- | --- |
| `GET /activities` | Optional `search` and `event` strings up to 100 characters; `causer_id` / `causerId` up to 100; `subject_type` / `subjectType` up to 255; `subject_id` / `subjectId` up to 100; valid inclusive dates in `created_at_from` / `createdAtFrom` and `created_at_to` / `createdAtTo`, with the upper bound on or after the lower bound; `per_page` / `perPage` or its `limit` alias from 1 to 100 (default 20); and `page` from 1. A date-only upper bound includes the complete day. |
| `GET /activities/timeline` | Required `subject_type` / `subjectType` string up to 255 characters and `subject_id` / `subjectId` string up to 100; optional `limit` from 1 to 100 (default 100). The subject type must resolve through `activity.routes.timeline_subjects`. |
| `GET /activities/causers/suggestions` | Optional `search` or `q`, at most 50 characters; a one-character search intentionally returns no suggestions. Optional `limit` is from 1 to 50 (default 10). |
| `POST /activities/purge` | Request body requires integer `days` from `activity.retention.allowed_purge_options`; defaults are 90, 365, or 730. Queues a general purge. |
| `POST /activities/purge-system` | Same required `days` contract; queues a system-origin-only purge. |

The index `limit` parameter is an alias for page size, not an unpaginated result cap. Timeline and causer limits are independent bounded result counts.

## Configuration reference

All boolean switches must be actual booleans so cached configuration preserves their meaning. Consumer associative maps merge recursively with package defaults, while every list-valued setting replaces its default list atomically, including an explicit empty list.

| Key | Default | Contract |
| --- | --- | --- |
| `activity.routes.enabled` | `false` | Enables the package management API only when strictly `true`. |
| `activity.routes.prefix` | `api/v1` | Prefix for package API routes. |
| `activity.routes.middleware` | `['api']` | Base route middleware. |
| `activity.routes.management_middleware` | `['auth', 'throttle:60,1']` | Required management protection; an empty list fails Doctor. |
| `activity.routes.timeline_subjects` | `[]` | Explicit model or morph-alias allowlist for merged timelines. |
| `activity.authorization.abilities.*` | `null` | Real Gate names for `view`, `timeline`, and `purge`; missing or undefined abilities fail closed. |
| `activity.migrations.enabled` | `true` | Loads the immutable vendor migration only for `activity_log` on the default connection. Must be boolean `false` for published, custom, or adopted migrations. |
| `activity.storage.connection` | `null` | Runtime model/query connection. A custom value requires application-owned migrations. |
| `activity.storage.table` | `activity_log` | Runtime model/query table. A custom value requires application-owned migrations. |
| `activity.causer_suggestions.model` | `null` | Explicit Eloquent causer model, or the configured Eloquent `users` auth-provider model. |
| `activity.causer_suggestions.label_attribute` | `name` | Allowlisted primary display attribute. |
| `activity.causer_suggestions.sublabel_attribute` | `email` | Optional secondary display attribute. |
| `activity.causer_suggestions.type_attribute` | `type` | Optional actor-type attribute. |
| `activity.causer_suggestions.search_attributes` | `['name', 'email']` | Allowlisted searchable columns; incompatible columns are ignored safely. |
| `activity.causer_suggestions.scan_limit` | `5000` | Maximum candidate rows inspected before final suggestion limiting. |
| `activity.retention.default_days` | `365` | Default general purge cutoff. |
| `activity.retention.system_logs_days` | `90` | Default system-origin purge cutoff and scheduled value. |
| `activity.retention.allowed_purge_options` | `[90, 365, 730]` | Allowed API purge-day values. |
| `activity.retention.queue` | `maintenance` | Queue used by purge jobs. |
| `activity.retention.external_visibility_timeout_seconds` | `null` | Required operator-declared backend visibility timeout for SQS or custom queue drivers without `retry_after`; it must exceed the purge job timeout. |
| `activity.retention.lock_seconds` | `3600` | Distributed purge-lock lifetime; runtime enforces at least 960 seconds. |
| `activity.retention.schedule.enabled` | `false` | Opt-in scheduled system retention. |
| `activity.retention.schedule.time` | `02:00` | Daily 24-hour schedule time. |
| `activity.capture.ignored_attributes` | timestamps, soft-delete timestamp, remember token | Exact technical fields omitted from inferred update diffs. Replacing the list replaces the defaults. |

There is intentionally no `activity.model` setting. Run Doctor after publishing or changing configuration and after `config:cache`.

### API envelopes

Read endpoints return canonical DTO data under `data`. Index responses use `Nvl\Data\Data\PaginatedCollection`; timeline rows use `ActivityItem`; causer suggestions use `ActivityCauserSuggestion`.

Purge endpoints return a typed `ActivityPurgeQueuedResult`, a stable response code, and a translated message:

```json
{
  "data": {
    "queued": true,
    "days": 90,
    "systemOnly": false
  },
  "code": "purge_queued",
  "message": "The activity log purge has been queued."
}
```

Codes are machine-readable and never translated. Messages follow the active locale. Package routes force JSON negotiation before validation and authorization, even when a client omits the `Accept` header. Consumer middleware may still deliberately short-circuit with any valid Symfony response. Validation failures use Laravel's standard `422` envelope with canonical snake-case field keys under `errors`. Missing allowlisted timeline subjects return a safe `404` with `timeline_subject_not_found` and do not expose model names or identifiers.

## Retention and operations

```bash
php artisan nvl:activity:doctor --strict --format=json
php artisan nvl:activity:purge --dry-run --days=365
php artisan nvl:activity:purge --days=365
php artisan nvl:activity:purge-system --dry-run
```

Doctor is read-only. It checks strict configuration values, connection availability, the configured table, columns, morph identifiers, JSON properties, indexes, the canonical Activity model, Spatie version, defined Gate abilities, subject allowlists, queue safety, and scheduling.

Purge supports date, event, log, subject, causer, and system-origin scopes. `audit_only` visibility does not make a user event system-originated. Dry run counts rows. Mutation dispatches locked, chunked queue work and reports failures.

Automatic system retention is disabled by default. Configure the maintenance queue, run a worker, and explicitly enable it only after previewing the result:

```php
'retention' => [
    'schedule' => [
        'enabled' => true,
        'time' => '02:00',
    ],
],
```

`PurgeActivityLogsJob` runs after commit, deletes in chunks of 1,000, and uses one distributed lock. Lock contention releases the job for 60 seconds instead of silently succeeding. Its time-based retry window covers one complete configured lock lifetime plus bounded execution retries, so legitimate contention cannot exhaust a small attempt count; five unhandled execution exceptions fail the job. Each attempt has a public 900-second timeout contract with failure-on-timeout and exception backoff delays of 60, 300, 900, and 1,800 seconds. Configure database, Redis, or Beanstalkd `retry_after` to a value **greater than 900 seconds** so another worker cannot receive the same job while a valid attempt is still running. SQS and custom drivers without `retry_after` require `activity.retention.external_visibility_timeout_seconds` to declare the backend value configured outside Laravel; Doctor fails until that declared value also exceeds 900. Failover connections are safe only when every target passes the same rule. Supervisors must also allow the worker enough shutdown time for the 900-second job timeout.

The default cache must provide Laravel atomic locks. In multi-worker or multi-node deployments, every worker and scheduler must use the same canonical shared lock backend, such as Redis, database, Memcached, or DynamoDB. File locks are suitable only when every process runs on one host and shares the same filesystem. `array` and `null` stores are not production-safe. Cache failover is also unsafe for mutual exclusion because different nodes can acquire locks in different backend domains; strict Doctor rejects all three drivers.

Strict Doctor readiness rejects the `sync` queue even when management routes and automatic scheduling are disabled, because the console purge commands also dispatch this job and lock contention must be requeued durably.

## TypeScript

DTO and enum sources register with `nvl/data` and generate under `Nvl.Activity.*`:

```bash
php artisan nvl:data:types:generate
php artisan nvl:data:types:check
```

Return display DTOs rather than raw Spatie models or properties.

Public value domains use backed enums: shared activity event, capture origin, visibility, importance, timeline entry source, headline segment type, doctor severity, and API response code. `ActivityEvent` provides localized labels and package-owned headline templates while its persisted values remain stable. `ActivityPurgeQueuedResult`, `ActivityItem`, and related display DTOs are the public data contracts; controllers should not return raw Eloquent or Spatie objects.

## Localization and string ownership

The package owns English and Bulgarian catalogs under the `activity` translation namespace. They cover:

- timeline events, templates, actors, sources, summaries, and diffs;
- operational enum labels;
- API success and safe error messages;
- request validation attributes and rules;
- doctor and purge command output.

Publish `activity-translations` to override copy in `lang/vendor/activity/{locale}`. Keep event keys, enum values, response codes, validation field keys, and stored structured properties locale-neutral. Translate only at display, API, or console boundaries. Every new English key must have a Bulgarian counterpart with identical placeholders.

Do not store translated headlines, labels, or exception messages in `activity_log`. Store canonical enum values and structured context, then let mappings and the package catalog render the active locale. `ActivityEvent::Sent` and `ActivityEvent::Resent` describe business actions on the subject; mail transport delivery, opening, and retry lifecycle remains owned by `nvl/mail-notifications`.

## Database adoption

Existing Spatie installations may differ in primary key type, morph columns, indexes, table name, or package version. Set the boolean `activity.migrations.enabled=false`, run Doctor, and resolve differences in an application-owned reversible migration with frozen literal targets. Do not assume a table-name match is schema compatibility.

Preserve identifiers where possible and compare row counts, checksums, representative properties, and rendered timelines before cutover.

## Failure and transaction behavior

`ActivityException` extends the shared transport-neutral `BusinessException` contract. Scoped configuration, recording, purge-criteria, and timeline exceptions expose a stable response code, suggested status, safe public context, and separate diagnostic context. JSON requests receive the safe canonical error envelope; configuration details and identifiers remain diagnostic-only.

The recorder does not open its own transaction. When activity and the business model use the same database connection, recording participates in the caller's transaction. A separate `activity.storage.connection` cannot be atomic with a transaction on the business connection: rolling back the business mutation may leave an activity row. Consumers using separate storage must choose and implement an explicit consistency policy, such as after-commit best-effort recording or an application-owned transactional outbox. Application write workflows should commit their business mutation before recording activity when an activity failure must not roll back business state. Jobs or events that depend on durable activity must be dispatched after commit. Timeline providers should fail in isolation where partial timelines are acceptable and fail closed where audit completeness is required; make that application policy explicit.

## Verification

Run the suite root gate; it supplies the module-aware Pest bootstrap and all family-wide checks:

```bash
composer quality
composer validate --strict packages/nvl/activity/composer.json
php artisan nvl:activity:doctor --strict --format=json
php artisan nvl:data:types:check
```

For a fast isolated package test from the monorepo root, run:

```bash
vendor/bin/pest --test-directory=packages/nvl/activity/tests --configuration=packages/nvl/activity/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/activity/tests
```

The suite gate covers Pint, PHPStan at maximum strictness, Pest, module boundaries, and one clean-consumer installation of the tagged `nvl/laravel-suite` archive. Activity coverage proves package discovery, cached configuration and routes, strict Doctor readiness, canonical and application-owned custom-connection migration lifecycles, complete mapping registration, exact create/update/delete capture, structured and hidden events, complete and finite merged timelines, authenticated requests to all five API endpoints, serialized purge-job scopes on the `maintenance` queue, and execution by a real database queue worker. Keep this production smoke green together with dependency analysis, suite distribution validation, and frozen contract checks.

Current tests exercise canonical model binding; immutable migration rollback; custom/adopted schema rejection; mapped create, update, and delete capture; structured writers and batches; anonymous, scalar, integer, UUID, and soft-deleted actors; invalid metadata rejection; complete and finite post-filter timelines across keyset batches; bilingual translations and validation; stable API/error envelopes; real named Gate abilities; subject allowlisting; JSON negotiation; dry-run eligibility; system-origin retention; lock contention; retry/backoff/timeout settings; and after-commit dispatch.

When upgrading a consumer that published the bundled skill, republish `activity-skills` with `--force` or merge the updated package skill into the application's customized copy:

```bash
php artisan vendor:publish --tag=activity-skills --force
```

Review local modifications before using `--force`.

See [UPGRADING.md](UPGRADING.md), [SECURITY.md](SECURITY.md), [CONTRIBUTING.md](CONTRIBUTING.md), and [CHANGELOG.md](CHANGELOG.md).

## License

Released under the [MIT License](LICENSE).
