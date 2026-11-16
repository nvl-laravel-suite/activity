<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Nvl\Comments\Providers\CommentsServiceProvider;
use Nvl\MailNotifications\Providers\MailNotificationsServiceProvider;

/**
 * Boots Activity with Comments and Mail Notifications to exercise the timeline source recipes.
 */
abstract class TimelineRecipeTestCase extends TestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            CommentsServiceProvider::class,
            MailNotificationsServiceProvider::class,
        ];
    }

    /** Comments and Mail Notifications add tables, so never reuse a schema another test case migrated without them. */
    protected function beforeRefreshingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
    }

    /** Create the host-owned tables: the timeline subject, the actor and a mail log with its own category column. */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        Schema::create('activity_timeline_subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('test_activity_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('host_mail_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('notifiable_type');
            $table->string('notifiable_id');
            $table->string('status');
            $table->string('category');
            $table->string('recipient');
            $table->json('provider_payload')->nullable();
            $table->timestamp('created_at', 6)->nullable();
        });
    }
}
