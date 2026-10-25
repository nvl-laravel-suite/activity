<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Contracts\ActivityTimelineSource;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityReader;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Activity\Tests\Stubs\TestTimelineSourceRecord;
use Nvl\Support\Enums\SystemActor;

beforeEach(function (): void {
    Schema::create('activity_external_timeline', function (Blueprint $table): void {
        $table->id();
        $table->string('subject_id');
        $table->string('event');
        $table->string('created_at')->nullable();
    });
});

function activityTimelineSource(array $supersedes = []): ActivityTimelineSource
{
    return new class($supersedes) implements ActivityTimelineSource
    {
        public function __construct(private array $events) {}

        public function query(Model|ActivitySubjectReference $subject): Builder
        {
            return TestTimelineSourceRecord::query()->where('subject_id', $subject instanceof Model ? $subject->getKey() : $subject->id);
        }

        public function supersedes(): array
        {
            return $this->events;
        }
    };
}

function activityTimelineBase(string $event = 'created', string $time = '2026-08-01 12:00:00'): ActivityLog
{
    return ActivityLog::query()->create(['event' => $event, 'description' => $event, 'subject_type' => 'external', 'subject_id' => 'one', 'created_at' => $time]);
}

test('merges and sorts native activity sources by newest timestamp first', function (): void {
    $base = activityTimelineBase();
    $custom = TestTimelineSourceRecord::query()->create(['subject_id' => 'one', 'event' => 'commented', 'created_at' => '2026-08-01 13:00:00']);
    $rows = app(ActivityReader::class)->timeline(new ActivitySubjectReference('external', 'one'), SystemActor::Background, 10, [activityTimelineSource()]);
    expect($rows->all())->toHaveCount(2)->and($rows[0]->is($custom))->toBeTrue()->and($rows[1]->is($base))->toBeTrue();
});

test('merged timelines compare timezone offsets as absolute instants', function (): void {
    activityTimelineBase(time: '2026-08-01 12:00:00');
    TestTimelineSourceRecord::query()->create(['subject_id' => 'one', 'event' => 'commented', 'created_at' => '2026-08-01T14:00:00+03:00']);
    $rows = app(ActivityReader::class)->timeline(new ActivitySubjectReference('external', 'one'), SystemActor::Background, 10, [activityTimelineSource()]);
    expect($rows->first())->toBeInstanceOf(ActivityLog::class);
});

test('merged timeline ordering is deterministic when timestamps match', function (): void {
    activityTimelineBase();
    activityTimelineBase('reviewed');
    $reader = app(ActivityReader::class);
    $reference = new ActivitySubjectReference('external', 'one');
    expect($reader->timeline($reference, SystemActor::Background)->pluck('id')->all())->toBe($reader->timeline($reference, SystemActor::Background)->pluck('id')->all());
});

test('timestamp peers preserve distinct events', function (): void {
    activityTimelineBase();
    activityTimelineBase('reviewed');
    expect(app(ActivityReader::class)->timeline(new ActivitySubjectReference('external', 'one'), SystemActor::Background))->toHaveCount(2);
});

test('merged timelines reject unbounded limits', function (): void {
    expect(fn () => app(ActivityReader::class)->timeline(new ActivitySubjectReference('external', 'one'), SystemActor::Background, 1001))->toThrow(InvalidArgumentException::class);
});

test('merged activity timeline applies a final newest first limit', function (): void {
    activityTimelineBase();
    activityTimelineBase('reviewed', '2026-08-01 13:00:00');
    $rows = app(ActivityReader::class)->timeline(new ActivitySubjectReference('external', 'one'), SystemActor::Background, 1);
    expect($rows)->toHaveCount(1)->and($rows->first()->event)->toBe('reviewed');
});

test('merged timeline limits backfill base rows removed by supersession', function (): void {
    activityTimelineBase('comment_recorded', '2026-08-01 15:00:00');
    activityTimelineBase('reviewed', '2026-08-01 14:00:00');
    TestTimelineSourceRecord::query()->create(['subject_id' => 'one', 'event' => 'commented', 'created_at' => '2026-08-01 13:00:00']);
    TestTimelineSourceRecord::query()->create(['subject_id' => 'foreign', 'event' => 'foreign', 'created_at' => '2026-08-01 16:00:00']);
    $rows = app(ActivityReader::class)->timeline(new ActivitySubjectReference('external', 'one'), SystemActor::Background, 2, [activityTimelineSource(['comment_recorded'])]);
    expect($rows->pluck('event')->all())->toBe(['reviewed', 'commented']);
});

test('integer source tie ordering has consistent bounded prefixes when composed with base facts', function (): void {
    $base = activityTimelineBase();
    foreach ([9, 10] as $id) {
        TestTimelineSourceRecord::query()->create(['id' => $id, 'subject_id' => 'one', 'event' => 'commented', 'created_at' => '2026-08-01 12:00:00']);
    }
    $reader = app(ActivityReader::class);
    $subject = new ActivitySubjectReference('external', 'one');
    $one = $reader->timeline($subject, SystemActor::Background, 1, [activityTimelineSource()]);
    $two = $reader->timeline($subject, SystemActor::Background, 2, [activityTimelineSource()]);
    $all = $reader->timeline($subject, SystemActor::Background, 3, [activityTimelineSource()]);
    expect($one->pluck('id')->all())->toBe([10])
        ->and($two->pluck('id')->all())->toBe([10, 9])
        ->and($all->pluck('id')->all())->toBe([10, 9, $base->id])
        ->and($one->pluck('id')->all())->toBe($two->take(1)->pluck('id')->all())
        ->and($two->pluck('id')->all())->toBe($all->take(2)->pluck('id')->all());
});
