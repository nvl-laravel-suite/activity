<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityReader;
use Nvl\Activity\Tests\Stubs\AbstractTestActivitySubject;
use Nvl\Activity\Tests\Stubs\TestActivityCauser;
use Nvl\Activity\Tests\Stubs\TestSoftDeletedActivityCauser;
use Nvl\Activity\Tests\Stubs\TestUuidActivitySubject;
use Nvl\Support\Enums\SystemActor;

test('causer suggestions do not assume a host user model', function (): void {
    config()->set('nvl-activity.causer_suggestions.model');
    config()->set('auth.providers.users.model');

    $suggestions = app(ActivityReader::class)->causers(SystemActor::Background);

    expect($suggestions)->toHaveCount(0);
});

test('causer suggestions safely reject unavailable configured models', function (): void {
    config()->set('nvl-activity.causer_suggestions.model', AbstractTestActivitySubject::class);

    expect(app(ActivityReader::class)->causers(SystemActor::Background))->toHaveCount(0);

    config()->set('nvl-activity.causer_suggestions.model', TestActivityCauser::class);

    expect(app(ActivityReader::class)->causers(SystemActor::Background))->toHaveCount(0);
});

test('native causer suggestions expose fixed safe identity attributes and preserve non uuid keys', function (): void {
    Schema::create('activity_test_causers', function (Blueprint $table): void {
        $table->increments('causer_key');
        $table->string('name');
        $table->string('password');
    });
    config(['nvl-activity.causer_suggestions.model' => TestActivityCauser::class]);
    $causer = TestActivityCauser::query()->create(['name' => 'Ada', 'password' => 'private']);
    ActivityLog::query()->create(['description' => 'reviewed', 'causer_type' => $causer->getMorphClass(), 'causer_id' => $causer->getKey(), 'event' => 'created']);
    $suggestion = app(ActivityReader::class)->causers(SystemActor::Background)->first();
    expect($suggestion->getKey())->toBe($causer->getKey())->and($suggestion->getAttributes())->toBe(['causer_key' => $causer->getKey(), 'name' => 'Ada']);
});

test('causer suggestions query integer keyed models without cross type joins', function (): void {
    Schema::create('activity_test_causers', function (Blueprint $table): void {
        $table->smallIncrements('causer_key');
        $table->string('name');
        $table->string('email')->nullable();
        $table->string('kind')->nullable();
    });

    try {
        config()->set('nvl-activity.causer_suggestions.model', TestActivityCauser::class);
        config()->set('nvl-activity.causer_suggestions.label_attribute', 'name');
        config()->set('nvl-activity.causer_suggestions.sublabel_attribute', 'email');
        config()->set('nvl-activity.causer_suggestions.type_attribute', 'kind');
        config()->set('nvl-activity.causer_suggestions.search_attributes', ['name', 'email']);

        $causer = TestActivityCauser::query()->create([
            'name' => 'Grace Hopper',
            'email' => 'grace@example.test',
            'kind' => 'operator',
        ]);

        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Integer causer activity',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => (string) $causer->getKey(),
            'event' => 'created',
        ]);
        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Malformed historical integer causer activity',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => 'not-an-integer',
            'event' => 'created',
        ]);
        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Out-of-range historical integer causer activity',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => '-32769',
            'event' => 'created',
        ]);
        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Noncanonical historical integer causer activity',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => '+'.(string) $causer->getKey(),
            'event' => 'created',
        ]);

        $suggestions = app(ActivityReader::class)->causers(SystemActor::Background, 'Grace');
        $suggestion = $suggestions->first();
        $suggestionsByKey = app(ActivityReader::class)->causers(SystemActor::Background, (string) $causer->getKey());

        expect($suggestions)->toHaveCount(1)
            ->and($suggestion)->toBeInstanceOf(TestActivityCauser::class)
            ->and($suggestion->getKey())->toBe($causer->getKey())
            ->and($suggestion->name)->toBe('Grace Hopper')
            ->and($suggestionsByKey)->toHaveCount(1)
            ->and($suggestionsByKey->first()->getKey())->toBe($causer->getKey());
    } finally {
        Schema::dropIfExists('activity_test_causers');
    }
});

test('causer suggestions isolate malformed historical uuid identifiers and normal text searches', function (): void {
    Schema::create('activity_uuid_subjects', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name');
    });

    try {
        config()->set('nvl-activity.causer_suggestions.model', TestUuidActivitySubject::class);
        config()->set('nvl-activity.causer_suggestions.label_attribute', 'name');
        config()->set('nvl-activity.causer_suggestions.sublabel_attribute');
        config()->set('nvl-activity.causer_suggestions.type_attribute');
        config()->set('nvl-activity.causer_suggestions.search_attributes', ['name']);

        $causer = TestUuidActivitySubject::query()->create(['name' => 'Grace Hopper']);

        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'UUID causer activity',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => strtoupper((string) $causer->getKey()),
            'event' => 'created',
        ]);
        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Malformed historical UUID causer activity',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => 'not-a-uuid',
            'event' => 'created',
        ]);

        $suggestions = app(ActivityReader::class)->causers(SystemActor::Background, 'Grace');

        expect($suggestions)->toHaveCount(1)
            ->and($suggestions->first()->getKey())->toBe($causer->getKey())
            ->and($suggestions->first()->name)->toBe('Grace Hopper');
    } finally {
        Schema::dropIfExists('activity_uuid_subjects');
    }
});

test('historical causer suggestions include soft deleted actors', function (): void {
    Schema::create('activity_soft_deleted_causers', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->softDeletes();
    });

    try {
        config()->set('nvl-activity.causer_suggestions.model', TestSoftDeletedActivityCauser::class);
        config()->set('nvl-activity.causer_suggestions.label_attribute', 'name');
        config()->set('nvl-activity.causer_suggestions.sublabel_attribute', 'email');
        config()->set('nvl-activity.causer_suggestions.search_attributes', ['name', 'email']);

        $causer = TestSoftDeletedActivityCauser::query()->create([
            'name' => 'Archived Operator',
            'email' => 'archived@example.test',
        ]);

        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Historical causer activity',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => (string) $causer->getKey(),
            'event' => 'created',
        ]);

        $causer->delete();

        $suggestions = app(ActivityReader::class)->causers(SystemActor::Background, 'Archived');

        expect($suggestions)->toHaveCount(1)
            ->and($suggestions->first()->getKey())->toBe($causer->getKey())
            ->and($suggestions->first()->name)->toBe('Archived Operator');
    } finally {
        Schema::dropIfExists('activity_soft_deleted_causers');
    }
});

test('unsearchable integer causer configuration returns no false positive suggestions', function (): void {
    Schema::create('activity_test_causers', function (Blueprint $table): void {
        $table->increments('causer_key');
        $table->string('name');
    });

    try {
        config()->set('nvl-activity.causer_suggestions.model', TestActivityCauser::class);
        config()->set('nvl-activity.causer_suggestions.search_attributes', ['missing_attribute']);

        $causer = TestActivityCauser::query()->create(['name' => 'Hidden']);
        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Unsearchable causer activity',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => (string) $causer->getKey(),
            'event' => 'created',
        ]);

        expect(app(ActivityReader::class)->causers(SystemActor::Background, 'not-a-key'))
            ->toHaveCount(0);
    } finally {
        Schema::dropIfExists('activity_test_causers');
    }
});

test('causer suggestions never apply fuzzy matching to integer columns', function (): void {
    Schema::create('activity_test_causers', function (Blueprint $table): void {
        $table->increments('causer_key');
        $table->string('name');
    });

    try {
        config()->set('nvl-activity.causer_suggestions.model', TestActivityCauser::class);
        config()->set('nvl-activity.causer_suggestions.search_attributes', ['causer_key']);

        $causer = TestActivityCauser::query()->create(['name' => 'Integer key']);
        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Integer causer activity',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => (string) $causer->getKey(),
            'event' => 'created',
        ]);

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $suggestions = app(ActivityReader::class)->causers(SystemActor::Background, 'not-a-key');

        expect($suggestions)->toHaveCount(0)
            ->and(collect($queries)->contains(
                static fn (string $query): bool => str_contains($query, 'causer_key')
                    && str_contains($query, 'like'),
            ))->toBeFalse();
    } finally {
        Schema::dropIfExists('activity_test_causers');
    }
});
