<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Actions\Activity\ListActivityCauserSuggestionsAction;
use Nvl\Activity\Data\Display\ActivityCauserSuggestion;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Tests\Stubs\AbstractTestActivitySubject;
use Nvl\Activity\Tests\Stubs\TestActivityCauser;
use Nvl\Activity\Tests\Stubs\TestSoftDeletedActivityCauser;
use Nvl\Activity\Tests\Stubs\TestUuidActivitySubject;

test('causer suggestions do not assume a host user model', function (): void {
    config()->set('activity.causer_suggestions.model');
    config()->set('auth.providers.users.model');

    $suggestions = (new ListActivityCauserSuggestionsAction)->execute();

    expect($suggestions)->toHaveCount(0);
});

test('causer suggestions safely reject unavailable configured models', function (): void {
    config()->set('activity.causer_suggestions.model', AbstractTestActivitySubject::class);

    expect((new ListActivityCauserSuggestionsAction)->execute())->toHaveCount(0);

    config()->set('activity.causer_suggestions.model', TestActivityCauser::class);

    expect((new ListActivityCauserSuggestionsAction)->execute())->toHaveCount(0);
});

test('causer suggestion data supports configured attributes and non uuid keys', function (): void {
    config()->set('activity.causer_suggestions.label_attribute', 'display_name');
    config()->set('activity.causer_suggestions.sublabel_attribute', 'contact');
    config()->set('activity.causer_suggestions.type_attribute', 'kind');

    $causer = new TestActivityCauser;
    $causer->forceFill([
        'causer_key' => 42,
        'display_name' => 'Ada Lovelace',
        'contact' => 'ada@example.test',
        'kind' => 'operator',
    ]);

    $suggestion = ActivityCauserSuggestion::fromModel($causer);

    expect($suggestion->id)->toBe('42')
        ->and($suggestion->label)->toBe('Ada Lovelace')
        ->and($suggestion->sublabel)->toBe('ada@example.test')
        ->and($suggestion->type)->toBe('operator');
});

test('causer suggestions query integer keyed models without cross type joins', function (): void {
    Schema::create('activity_test_causers', function (Blueprint $table): void {
        $table->smallIncrements('causer_key');
        $table->string('display_name');
        $table->string('contact')->nullable();
        $table->string('kind')->nullable();
    });

    try {
        config()->set('activity.causer_suggestions.model', TestActivityCauser::class);
        config()->set('activity.causer_suggestions.label_attribute', 'display_name');
        config()->set('activity.causer_suggestions.sublabel_attribute', 'contact');
        config()->set('activity.causer_suggestions.type_attribute', 'kind');
        config()->set('activity.causer_suggestions.search_attributes', ['display_name', 'contact']);

        $causer = TestActivityCauser::query()->create([
            'display_name' => 'Grace Hopper',
            'contact' => 'grace@example.test',
            'kind' => 'operator',
        ]);

        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Integer causer activity',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => (string) $causer->getKey(),
        ]);
        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Malformed historical integer causer activity',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => 'not-an-integer',
        ]);
        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Out-of-range historical integer causer activity',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => '-32769',
        ]);
        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Noncanonical historical integer causer activity',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => '+'.(string) $causer->getKey(),
        ]);

        $suggestions = (new ListActivityCauserSuggestionsAction)->execute('Grace');
        $suggestion = $suggestions->first();
        $suggestionsByKey = (new ListActivityCauserSuggestionsAction)->execute((string) $causer->getKey());

        expect($suggestions)->toHaveCount(1)
            ->and($suggestion)->toBeInstanceOf(ActivityCauserSuggestion::class)
            ->and($suggestion->id)->toBe((string) $causer->getKey())
            ->and($suggestion->label)->toBe('Grace Hopper')
            ->and($suggestionsByKey)->toHaveCount(1)
            ->and($suggestionsByKey->first()->id)->toBe((string) $causer->getKey());
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
        config()->set('activity.causer_suggestions.model', TestUuidActivitySubject::class);
        config()->set('activity.causer_suggestions.label_attribute', 'name');
        config()->set('activity.causer_suggestions.sublabel_attribute');
        config()->set('activity.causer_suggestions.type_attribute');
        config()->set('activity.causer_suggestions.search_attributes', ['name']);

        $causer = TestUuidActivitySubject::query()->create(['name' => 'Grace Hopper']);

        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'UUID causer activity',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => strtoupper((string) $causer->getKey()),
        ]);
        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Malformed historical UUID causer activity',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => 'not-a-uuid',
        ]);

        $suggestions = (new ListActivityCauserSuggestionsAction)->execute('Grace');

        expect($suggestions)->toHaveCount(1)
            ->and($suggestions->first()->id)->toBe((string) $causer->getKey())
            ->and($suggestions->first()->label)->toBe('Grace Hopper');
    } finally {
        Schema::dropIfExists('activity_uuid_subjects');
    }
});

test('historical causer suggestions include soft deleted actors', function (): void {
    Schema::create('activity_soft_deleted_causers', function (Blueprint $table): void {
        $table->id();
        $table->string('display_name');
        $table->string('contact')->nullable();
        $table->softDeletes();
    });

    try {
        config()->set('activity.causer_suggestions.model', TestSoftDeletedActivityCauser::class);
        config()->set('activity.causer_suggestions.label_attribute', 'display_name');
        config()->set('activity.causer_suggestions.sublabel_attribute', 'contact');
        config()->set('activity.causer_suggestions.search_attributes', ['display_name', 'contact']);

        $causer = TestSoftDeletedActivityCauser::query()->create([
            'display_name' => 'Archived Operator',
            'contact' => 'archived@example.test',
        ]);

        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Historical causer activity',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => (string) $causer->getKey(),
        ]);

        $causer->delete();

        $suggestions = (new ListActivityCauserSuggestionsAction)->execute('Archived');

        expect($suggestions)->toHaveCount(1)
            ->and($suggestions->first()->id)->toBe((string) $causer->getKey())
            ->and($suggestions->first()->label)->toBe('Archived Operator');
    } finally {
        Schema::dropIfExists('activity_soft_deleted_causers');
    }
});

test('unsearchable integer causer configuration returns no false positive suggestions', function (): void {
    Schema::create('activity_test_causers', function (Blueprint $table): void {
        $table->increments('causer_key');
        $table->string('display_name');
    });

    try {
        config()->set('activity.causer_suggestions.model', TestActivityCauser::class);
        config()->set('activity.causer_suggestions.search_attributes', ['missing_attribute']);

        $causer = TestActivityCauser::query()->create(['display_name' => 'Hidden']);
        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Unsearchable causer activity',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => (string) $causer->getKey(),
        ]);

        expect((new ListActivityCauserSuggestionsAction)->execute('not-a-key'))
            ->toHaveCount(0);
    } finally {
        Schema::dropIfExists('activity_test_causers');
    }
});

test('causer suggestions never apply fuzzy matching to integer columns', function (): void {
    Schema::create('activity_test_causers', function (Blueprint $table): void {
        $table->increments('causer_key');
        $table->string('display_name');
    });

    try {
        config()->set('activity.causer_suggestions.model', TestActivityCauser::class);
        config()->set('activity.causer_suggestions.search_attributes', ['causer_key']);

        $causer = TestActivityCauser::query()->create(['display_name' => 'Integer key']);
        ActivityLog::query()->create([
            'log_name' => 'test',
            'description' => 'Integer causer activity',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => (string) $causer->getKey(),
        ]);

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $suggestions = (new ListActivityCauserSuggestionsAction)->execute('not-a-key');

        expect($suggestions)->toHaveCount(0)
            ->and(collect($queries)->contains(
                static fn (string $query): bool => str_contains($query, 'causer_key')
                    && str_contains($query, 'like'),
            ))->toBeFalse();
    } finally {
        Schema::dropIfExists('activity_test_causers');
    }
});
