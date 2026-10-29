<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Contracts\ActivityAuthorization;
use Nvl\Activity\Definitions\Tables\ActivityTables;
use Nvl\Activity\Exceptions\ActivityConfigurationException;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityDoctor;
use Nvl\Activity\Services\ActivityPolicyRegistry;
use Nvl\Activity\Services\ActivityReader;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Support\ActivityCaptureOptions;
use Nvl\Activity\Support\ActivityEventPolicy;
use Nvl\Activity\Support\ActivityPurgeCriteria;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Activity\Tests\Stubs\TestActivityUser;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

test('the canonical activity model has no third party model or logger base', function (): void {
    expect(get_parent_class(ActivityLog::class))->toBe(Model::class);
});

test('native capture options declare bounded fields and lifecycle events', function (): void {
    $options = new ActivityCaptureOptions(['name']);
    expect($options->fields)->toBe(['name'])
        ->and($options->events)->toBe(['created', 'updated', 'deleted', 'restored'])
        ->and($options->onlyDirty)->toBeTrue();
});

test('the canonical activity model rejects an empty configured table', function (): void {
    config()->set('nvl-activity.tables.log', ' ');
    (new ActivityLog)->getTable();
})->throws(ActivityConfigurationException::class, 'Activity table name cannot be empty.');

test('event policies reject unregistered facts and enforce redaction before persistence', function (): void {
    app()->instance(ActivityPolicyRegistry::class, new ActivityPolicyRegistry);
    $policies = app(ActivityPolicyRegistry::class);
    $policies->register(new ActivityEventPolicy('native.reviewed', changeFields: ['name', 'private_note'], redactedFields: ['private_note'], metadataFields: ['reason']));
    $recorder = app(ActivityRecorder::class);
    expect(fn () => $recorder->record(['event' => 'unknown'], SystemActor::Background))->toThrow(InvalidArgumentException::class);
    $row = $recorder->record(['event' => 'native.reviewed', 'attributes' => ['name' => 'Example', 'private_note' => 'never-store'], 'context' => ['reason' => 'review']], SystemActor::Background);
    expect($row->attribute_changes?->get('attributes'))->toBe(['name' => 'Example', 'private_note' => '[REDACTED]'])
        ->and($row->getRawOriginal('attribute_changes'))->not->toContain('never-store');
    expect(fn () => $recorder->record(['event' => 'native.reviewed', 'context' => ['unregistered' => 'value']], SystemActor::Background))->toThrow(InvalidArgumentException::class);
    expect(fn () => $policies->register(new ActivityEventPolicy('late')))->toThrow(LogicException::class);
});

test('facts reject model quiet and bulk mutation outside authorized retention', function (): void {
    $row = ActivityLog::factory()->create();
    expect(fn () => $row->forceFill(['description' => 'changed'])->saveQuietly())->toThrow(LogicException::class);
    expect(fn () => ActivityLog::query()->whereKey($row->id)->update(['description' => 'changed']))->toThrow(LogicException::class);
    expect(fn () => ActivityLog::query()->toBase()->updateFrom(['description' => 'changed']))->toThrow(LogicException::class);
    expect(fn () => $row->deleteQuietly())->toThrow(LogicException::class);
    expect(fn () => ActivityLog::query()->whereKey($row->id)->delete())->toThrow(LogicException::class);
    expect(Schema::hasColumn(ActivityTables::ActivityLog, 'batch_uuid'))->toBeTrue();
});

test('policy retention preserves young forever and unregistered facts', function (): void {
    $policies = new ActivityPolicyRegistry;
    $policies->register(new ActivityEventPolicy('audit.short', retentionDays: 7));
    $policies->register(new ActivityEventPolicy('audit.forever', retentionDays: null));
    app()->instance(ActivityPolicyRegistry::class, $policies);
    foreach ([['audit.short', 10], ['audit.short', 3], ['audit.forever', 100], ['audit.unknown', 100]] as [$event, $age]) {
        ActivityLog::factory()->create(['event' => $event, 'created_at' => now()->subDays($age)]);
    }
    $criteria = ActivityPurgeCriteria::fromDays(1);
    expect(PurgeActivityLogsJob::countPurgeableForCriteria($criteria))->toBe(1);
    (new PurgeActivityLogsJob(1, criteria: $criteria))->handle(app(OwnershipBoundary::class));
    expect(ActivityLog::query()->count())->toBe(3);
    expect(fn () => ActivityLog::query()->delete())->toThrow(LogicException::class);
});

test('cursor reads preserve tied microsecond timestamps without duplicates or omissions', function (): void {
    foreach (['100001', '100002', '100002'] as $fraction) {
        ActivityLog::factory()->create(['created_at' => '2026-10-01 12:00:00.'.$fraction, 'event' => 'created']);
    }
    $reader = app(ActivityReader::class);
    $cursor = null;
    $ids = [];
    do {
        $page = $reader->cursor(['per_page' => 1], SystemActor::Background, $cursor);
        $ids = [...$ids, ...$page['items']->modelKeys()];
        $cursor = $page['next_cursor'];
    } while ($cursor !== null);
    expect($ids)->toBe(ActivityLog::query()->newestFirst()->pluck('id')->all())
        ->and(array_unique($ids))->toHaveCount(3);
    expect(fn () => $reader->cursor([], SystemActor::Background, 'invalid'))->toThrow(InvalidArgumentException::class);
});

test('restricted audiences constrain SQL counts cursor pages and individual reads', function (): void {
    $model = new TestActivityUser;
    Schema::create($model->getTable(), function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    $actor = $model->newQuery()->create(['name' => 'Reader']);
    $other = $model->newQuery()->create(['name' => 'Other']);
    $policies = new ActivityPolicyRegistry;
    $policies->register(new ActivityEventPolicy('audit.personal', audience: 'subject_or_actor'));
    $policies->register(new ActivityEventPolicy('audit.admin', audience: 'administrative'));
    app()->instance(ActivityPolicyRegistry::class, $policies);
    app()->instance(ActivityAuthorization::class, new class implements ActivityAuthorization
    {
        public function authorize(Authenticatable|SystemActor $actor, string $ability, Model|ActivitySubjectReference|null $subject = null): void
        {
            if ($ability === 'read_all') {
                throw new AuthorizationException;
            }
        }
    });
    $own = ActivityLog::factory()->create(['event' => 'audit.personal', 'subject_type' => $actor->getMorphClass(), 'subject_id' => $actor->getKey()]);
    $caused = ActivityLog::factory()->create(['event' => 'audit.personal', 'causer_type' => $actor->getMorphClass(), 'causer_id' => $actor->getKey()]);
    $hidden = ActivityLog::factory()->create(['event' => 'audit.personal', 'subject_type' => $other->getMorphClass(), 'subject_id' => $other->getKey()]);
    ActivityLog::factory()->create(['event' => 'audit.admin', 'causer_type' => $actor->getMorphClass(), 'causer_id' => $actor->getKey()]);
    $reader = app(ActivityReader::class);
    expect($reader->paginate([], $actor)->total())->toBe(2)
        ->and($reader->cursor(['per_page' => 2], $actor)['items']->modelKeys())->toBe([$caused->id, $own->id])
        ->and($reader->find($hidden->id, $actor))->toBeNull()
        ->and($reader->find($own->id, $actor)?->id)->toBe($own->id);
});

test('doctor reports an empty native event catalog before writes fail', function (): void {
    app()->instance(ActivityPolicyRegistry::class, new ActivityPolicyRegistry);
    $check = collect(app(ActivityDoctor::class)->inspect())->firstWhere('key', 'events.policies');
    expect($check['passed'])->toBeFalse();
});

test('event policies validate explicit attribution and admitted subject types', function (): void {
    $policy = new ActivityEventPolicy('account.reviewed', subjectTypes: ['account'], actor: 'required', actorTypes: ['staff']);
    expect(fn () => $policy->prepare('account', null, true, [], [], []))->toThrow(InvalidArgumentException::class);
    expect(fn () => $policy->prepare('account', 'customer', false, [], [], []))->toThrow(InvalidArgumentException::class);
    expect(fn () => $policy->prepare('other', 'staff', false, [], [], []))->toThrow(InvalidArgumentException::class);
    expect($policy->prepare('account', 'staff', false, [], [], []))->toBe([[], [], []]);
});
