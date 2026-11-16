<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures\Timeline;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Nvl\Activity\Contracts\ActivityTimelineSource;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentModelRegistry;
use Nvl\Comments\Support\CommentIdentity;
use Nvl\Support\Enums\PublicActor;

/**
 * Copyable recipe: the comments a given actor and audience may list on the timeline subject.
 *
 * @implements ActivityTimelineSource<Comment>
 */
final readonly class CommentsTimelineSource implements ActivityTimelineSource
{
    /** Set $publicOnly for a public page: it also requires approved, public comments. */
    public function __construct(
        private Authenticatable|PublicActor $actor,
        private CommentAudience $audience = CommentAudience::Public,
        private bool $publicOnly = false,
    ) {}

    /** @return Builder<Comment> */
    public function query(Model|ActivitySubjectReference $subject): Builder
    {
        $target = $this->reload($subject);
        $key = $target->getKey();
        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidArgumentException('Comment targets require a scalar key.');
        }
        if (! app(CommentAuthorization::class)->allows(CommentAbility::List, $this->actor, target: $target, audience: $this->audience)) {
            throw new AuthorizationException('This actor cannot list comments on the timeline subject.');
        }

        $comments = app(CommentModelRegistry::class)->commentClass()::query()
            ->where('commentable_identity_hash', CommentIdentity::pair($target->getMorphClass(), (string) $key));
        if ($this->audience !== CommentAudience::Management) {
            $comments->where('status_hash', '!=', CommentIdentity::value('comment-status', CommentStatus::Flagged));
        }
        // Group the host scope so an orWhere inside it cannot widen the query past this target.
        $comments->where(function (Builder $scope) use ($target): void {
            app(CommentQueryScope::class)->scopeComments($scope, $this->actor, $target, $this->audience, CommentAbility::List);
        });
        if ($this->publicOnly) {
            $comments->publiclyVisible();
        }

        return $comments->withoutTrashed()->select(['id', 'created_at', 'body']);
    }

    /** @return list<string> */
    public function supersedes(): array
    {
        return ['comment.created'];
    }

    /** Load the live target instead of trusting the caller's instance or reference. */
    private function reload(Model|ActivitySubjectReference $subject): Model
    {
        $class = $subject instanceof Model ? $subject::class : (Relation::getMorphedModel($subject->type) ?? $subject->type);
        $key = $subject instanceof Model ? $subject->getKey() : $subject->id;
        if (! is_subclass_of($class, Model::class) || (! is_int($key) && ! is_string($key))) {
            throw new InvalidArgumentException('Comment timelines require a model subject with a scalar key.');
        }

        return $class::query()->findOrFail($key);
    }
}
