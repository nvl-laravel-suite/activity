<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Nvl\Activity\Contracts\ActivityTimelineSource;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityPolicyRegistry;
use Nvl\Activity\Services\ActivityReader;
use Nvl\Activity\Support\ActivityEventPolicy;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Activity\Tests\Fixtures\Timeline\CommentsTimelineSource;
use Nvl\Activity\Tests\Fixtures\Timeline\GenericMailLogTimelineSource;
use Nvl\Activity\Tests\Fixtures\Timeline\MailNotificationsTimelineSource;
use Nvl\Activity\Tests\Stubs\TestActivityTimelineSubject;
use Nvl\Activity\Tests\Stubs\TestActivityUser;
use Nvl\Activity\Tests\Stubs\TestHostMailLog;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Services\MailModelRegistry;
use Nvl\Support\Enums\PublicActor;
use Nvl\Support\Enums\SystemActor;

const RECIPE_TIME = '2026-08-01 12:00:00';

beforeEach(function (): void {
    app(ActivityPolicyRegistry::class)->register(new ActivityEventPolicy('comment.created', changeFields: null, metadataFields: null, retentionDays: 1));
    config()->set('nvl-mail-notifications.management.authorization.callback', static fn (mixed $ability, TestActivityUser $actor): bool => $actor->name === 'reader');
    Gate::define('viewMailLog', static fn (TestActivityUser $actor): bool => $actor->name === 'reader');

    $this->reader = TestActivityUser::query()->create(['id' => 1, 'name' => 'reader']);
    $this->outsider = TestActivityUser::query()->create(['id' => 2, 'name' => 'outsider']);
    $this->subject = TestActivityTimelineSubject::query()->create(['id' => 1, 'name' => 'Order 1']);
});

/** @param array<string, mixed> $attributes */
function recipeComment(Model $target, array $attributes = []): Comment
{
    return Comment::factory()->forOwner($target)->create([
        'body' => 'Visible note', 'status' => 'approved', 'visibility' => 'public', 'created_at' => RECIPE_TIME, ...$attributes,
    ]);
}

function recipeBaseFact(Model $subject, string $event = 'comment.created'): ActivityLog
{
    return ActivityLog::query()->create([
        'event' => $event, 'description' => $event, 'subject_type' => $subject->getMorphClass(),
        'subject_id' => (string) $subject->getKey(), 'created_at' => RECIPE_TIME,
    ]);
}

function recipeMail(Model $notifiable, string $category = 'order.receipt'): MailNotification
{
    $id = (string) Str::uuid();
    DB::table((new MailNotification)->getTable())->insert([
        'id' => $id, 'correlation_id' => (string) Str::uuid(), 'mailer' => 'array', 'provider' => 'provider-secret',
        'provider_message_id' => 'provider-message-secret', 'status' => 'delivered', 'message_category' => $category,
        'subject' => 'Your receipt', 'to_recipients' => json_encode(['recipient-secret@example.test']),
        'primary_recipient_email' => 'recipient-secret@example.test', 'metadata' => json_encode(['payload' => 'provider-payload-secret']),
        'notifiable_type' => $notifiable->getMorphClass(), 'notifiable_id' => (string) $notifiable->getKey(),
        'created_at' => RECIPE_TIME, 'updated_at' => RECIPE_TIME,
    ]);

    return app(MailModelRegistry::class)->notificationClass()::query()->findOrFail($id);
}

function recipeHostMail(Model $notifiable, string $category = 'invoice'): TestHostMailLog
{
    return TestHostMailLog::query()->create([
        'notifiable_type' => $notifiable->getMorphClass(), 'notifiable_id' => (string) $notifiable->getKey(),
        'status' => 'sent', 'category' => $category, 'recipient' => 'recipient-secret@example.test',
        'provider_payload' => json_encode(['payload' => 'provider-payload-secret']), 'created_at' => RECIPE_TIME,
    ]);
}

/** @param list<ActivityTimelineSource<Model>> $sources */
function recipeTimeline(Model|ActivitySubjectReference $subject, array $sources): Collection
{
    return app(ActivityReader::class)->timeline($subject, SystemActor::Background, 50, $sources);
}

test('the generic source uses the configured category column', function (): void {
    $log = recipeHostMail($this->subject);
    recipeHostMail($this->reader, 'foreign');

    $rows = recipeTimeline($this->subject, [new GenericMailLogTimelineSource($this->reader, TestHostMailLog::class)]);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->is($log))->toBeTrue()
        ->and($rows->first()->getAttributes())->toBe(['id' => $log->id, 'created_at' => RECIPE_TIME, 'status' => 'sent', 'category' => 'invoice']);
});

test('the generic source rejects an unsafe or missing category column', function (string $column): void {
    expect(fn () => new GenericMailLogTimelineSource($this->reader, TestHostMailLog::class, $column))
        ->toThrow(InvalidArgumentException::class, 'The mail category column must be a plain column of the mail table.');
})->with(['message_category', 'category; drop table host_mail_logs', 'provider_payload->payload', 'recipient as category', '']);

test('no hidden comments, recipients or provider data leak', function (): void {
    $visible = recipeComment($this->subject);
    recipeComment($this->subject, ['body' => 'Private note', 'visibility' => 'private']);
    recipeComment($this->subject, ['body' => 'Pending note', 'status' => 'pending']);
    recipeComment($this->subject, ['body' => 'Deleted note', 'deleted_at' => RECIPE_TIME]);
    recipeComment($this->reader, ['body' => 'Other target note']);
    $mail = recipeMail($this->subject);
    $hostMail = recipeHostMail($this->subject);

    $sources = [
        new CommentsTimelineSource($this->reader),
        new MailNotificationsTimelineSource($this->reader),
        new GenericMailLogTimelineSource($this->reader, TestHostMailLog::class),
    ];
    $rows = recipeTimeline($this->subject, $sources);
    $public = recipeTimeline(new ActivitySubjectReference($this->subject->getMorphClass(), 1), [new CommentsTimelineSource(PublicActor::Anonymous, publicOnly: true)]);
    $payload = json_encode($rows->map(fn (Model $row): array => $row->getAttributes())->all(), JSON_THROW_ON_ERROR);

    expect($rows->filter(fn (Model $row): bool => $row instanceof Comment)->map(fn (Model $row): string|int => $row->getKey())->values()->all())->toBe([$visible->id])
        ->and($rows->filter(fn (Model $row): bool => $row instanceof MailNotification)->map(fn (Model $row): string|int => $row->getKey())->values()->all())->toBe([$mail->id])
        ->and($rows->filter(fn (Model $row): bool => $row instanceof TestHostMailLog)->map(fn (Model $row): string|int => $row->getKey())->values()->all())->toBe([$hostMail->id])
        ->and($public->map(fn (Model $row): string|int => $row->getKey())->values()->all())->toBe([$visible->id])
        ->and($rows->first(fn (Model $row): bool => $row instanceof MailNotification)->getAttributes())
        ->toBe(['id' => $mail->id, 'created_at' => RECIPE_TIME, 'status' => 'delivered', 'message_category' => 'order.receipt']);
    foreach (['Private note', 'Pending note', 'Deleted note', 'Other target note', 'secret'] as $hidden) {
        expect($payload)->not->toContain($hidden);
    }
});

test('a host comment scope with orWhere cannot leak another target, and flagged comments stay hidden', function (): void {
    app()->instance(CommentQueryScope::class, new class implements CommentQueryScope
    {
        public function scopeComments(Builder $query, Authenticatable|SystemActor|PublicActor $actor, Model $target, CommentAudience $audience, CommentAbility $ability): void
        {
            $query->where('body', 'Visible note')->orWhere('body', 'Other target note');
        }
    });
    $visible = recipeComment($this->subject);
    recipeComment($this->subject, ['body' => 'Visible note', 'status' => 'flagged']);
    recipeComment($this->reader, ['body' => 'Other target note']);

    $rows = recipeTimeline($this->subject, [new CommentsTimelineSource($this->reader)]);

    expect($rows->map(fn (Model $row): string|int => $row->getKey())->values()->all())->toBe([$visible->id]);
});

test('an unauthorized subject is denied by every source', function (string $source): void {
    $source = match ($source) {
        'comments' => new CommentsTimelineSource($this->outsider, CommentAudience::Management),
        'mail notifications' => new MailNotificationsTimelineSource($this->outsider),
        'generic mail log' => new GenericMailLogTimelineSource($this->outsider, TestHostMailLog::class),
    };

    expect(fn () => recipeTimeline($this->subject, [$source]))->toThrow(AuthorizationException::class);
})->with(['comments', 'mail notifications', 'generic mail log']);

test('timestamp ties merge deterministically across sources', function (): void {
    recipeBaseFact($this->subject, 'reviewed');
    recipeComment($this->subject);
    recipeMail($this->subject);
    recipeHostMail($this->subject);
    $sources = static fn (TestActivityUser $actor): array => [
        new CommentsTimelineSource($actor), new MailNotificationsTimelineSource($actor), new GenericMailLogTimelineSource($actor, TestHostMailLog::class),
    ];

    $first = recipeTimeline($this->subject, $sources($this->reader));
    $second = recipeTimeline($this->subject, array_reverse($sources($this->reader)));
    $types = $first->map(fn (Model $row): string => $row->getMorphClass())->all();
    $expected = $types;
    rsort($expected);

    expect($first)->toHaveCount(4)
        ->and($second->map(fn (Model $row): string => $row->getMorphClass().':'.$row->getKey())->all())
        ->toBe($first->map(fn (Model $row): string => $row->getMorphClass().':'.$row->getKey())->all())
        ->and($types)->toBe($expected);
});

test('comments supersede comment.created only when visible rows exist', function (): void {
    $base = recipeBaseFact($this->subject);
    recipeComment($this->subject, ['body' => 'Private note', 'visibility' => 'private']);
    $hidden = recipeTimeline($this->subject, [new CommentsTimelineSource($this->reader)]);

    $visible = recipeComment($this->subject);
    $shown = recipeTimeline($this->subject, [new CommentsTimelineSource($this->reader)]);

    expect($hidden->map(fn (Model $row): string|int => $row->getKey())->values()->all())->toBe([$base->id])
        ->and($shown->map(fn (Model $row): string|int => $row->getKey())->values()->all())->toBe([$visible->id]);
});

test('mail supersedes nothing', function (): void {
    $base = recipeBaseFact($this->subject);
    $mail = recipeMail($this->subject);
    $hostMail = recipeHostMail($this->subject);
    $sources = [new MailNotificationsTimelineSource($this->reader), new GenericMailLogTimelineSource($this->reader, TestHostMailLog::class)];

    expect($sources[0]->supersedes())->toBe([])
        ->and($sources[1]->supersedes())->toBe([])
        ->and(recipeTimeline($this->subject, $sources)->map(fn (Model $row): string|int => $row->getKey())->values()->all())->toEqualCanonicalizing([$base->id, $mail->id, $hostMail->id]);
});
