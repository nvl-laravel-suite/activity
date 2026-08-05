<?php

declare(strict_types=1);

namespace Nvl\Activity\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;
use Nvl\Activity\Contracts\MergesActivity;
use Nvl\Activity\Exceptions\ActivityTimelineException;
use Nvl\Activity\Support\ModelKeyIdentifierValidator;

/**
 * Resolves host models that can expose a merged activity timeline.
 *
 * The Activity API accepts a subject type and identifier from HTTP query
 * parameters. This resolver owns the model-class validation boundary so the
 * controller can stay focused on authorization, request validation, and
 * response composition.
 */
final class ActivitySubjectTimelineResolver
{
    /**
     * Create the resolver with database-portable model key validation.
     */
    public function __construct(
        private readonly ModelKeyIdentifierValidator $modelKeyIdentifierValidator,
    ) {}

    /**
     * Resolve a timeline host by subject type and primary key.
     *
     * @param  string  $subjectType  Stored morph type or model class name
     * @param  string  $subjectId  Subject primary key value
     * @return Model&MergesActivity Resolved host model
     *
     * @throws ActivityTimelineException
     * @throws ValidationException
     */
    public function resolve(string $subjectType, string $subjectId): Model&MergesActivity
    {
        $modelClass = $this->resolveModelClass($subjectType);
        $model = new $modelClass;

        $normalizedSubjectId = $this->modelKeyIdentifierValidator->normalizeIdentifier($model, $subjectId);
        if ($normalizedSubjectId === null) {
            throw ActivityTimelineException::subjectNotFound($modelClass, $subjectId);
        }

        $subject = $model->newQuery()->whereKey($normalizedSubjectId)->first();

        if ($subject === null) {
            throw ActivityTimelineException::subjectNotFound($modelClass, $subjectId);
        }

        if (! $subject instanceof MergesActivity) {
            $this->throwUnsupportedSubjectType();
        }

        return $subject;
    }

    /**
     * Resolve a validated timeline host model class from a morph type.
     *
     * @param  string  $subjectType  Stored morph type or model class name
     * @return class-string<Model&MergesActivity> Model class that owns a merged timeline
     *
     * @throws ValidationException
     */
    private function resolveModelClass(string $subjectType): string
    {
        $configuredSubjects = config('activity.routes.timeline_subjects', []);
        $allowedSubjects = is_array($configuredSubjects)
            ? array_values(array_filter(
                array_map(
                    static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value,
                    $configuredSubjects,
                ),
                static fn (mixed $value): bool => is_string($value) && $value !== '',
            ))
            : [];
        $resolvedType = Relation::getMorphedModel($subjectType) ?? $subjectType;
        $allowedResolvedTypes = array_map(
            static fn (string $allowedType): string => Relation::getMorphedModel($allowedType) ?? $allowedType,
            $allowedSubjects,
        );

        if (! in_array($subjectType, $allowedSubjects, true)
            && ! in_array($resolvedType, $allowedResolvedTypes, true)) {
            $this->throwUnsupportedSubjectType();
        }

        if (! class_exists($resolvedType)) {
            $this->throwUnsupportedSubjectType();
        }

        if (! is_subclass_of($resolvedType, Model::class) || ! is_subclass_of($resolvedType, MergesActivity::class)) {
            $this->throwUnsupportedSubjectType();
        }

        /** @var class-string<Model&MergesActivity> $resolvedType */
        return $resolvedType;
    }

    /**
     * Reject subject types that cannot expose the merged timeline contract.
     *
     *
     * @throws ValidationException
     */
    private function throwUnsupportedSubjectType(): never
    {
        throw ValidationException::withMessages([
            'subject_type' => [
                (string) trans(
                    'activity::activity/general.validation.unsupported_timeline_subject',
                ),
            ],
        ]);
    }
}
