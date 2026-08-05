<?php

declare(strict_types=1);

namespace Nvl\Activity\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Response;
use Nvl\Activity\Enums\ActivityResponseCode;
use Throwable;

/**
 * Reports invalid activity purge criteria without treating user input as an application fault.
 */
final class ActivityPurgeCriteriaException extends ActivityException implements ShouldntReport
{
    /**
     * Create a failure for a non-positive retention day count.
     */
    public static function positiveDaysRequired(): self
    {
        return self::fromTranslation('positive_days', ['field' => 'days']);
    }

    /**
     * Create a failure for mutually exclusive relative and absolute cutoffs.
     */
    public static function mutuallyExclusiveCutoffs(): self
    {
        return self::fromTranslation('mutually_exclusive_cutoffs');
    }

    /**
     * Create a failure when no upper purge cutoff was supplied.
     */
    public static function missingCutoff(): self
    {
        return self::fromTranslation('missing_cutoff');
    }

    /**
     * Create a failure for an inverted purge time range.
     */
    public static function invalidRange(): self
    {
        return self::fromTranslation('invalid_range');
    }

    /**
     * Create a failure for criteria that cannot resolve an upper cutoff.
     */
    public static function unresolvedCutoff(): self
    {
        return self::fromTranslation('unresolved_cutoff');
    }

    /**
     * Create a failure for an invalid date option.
     */
    public static function invalidDate(string $option, Throwable $previous): self
    {
        return self::fromTranslation(
            key: 'invalid_date',
            publicContext: ['option' => $option],
            replacements: ['option' => $option],
            previous: $previous,
        );
    }

    /**
     * Create a translated invalid-criteria failure.
     *
     * @param  array<string, mixed>  $publicContext
     * @param  array<string, scalar>  $replacements
     */
    private static function fromTranslation(
        string $key,
        array $publicContext = [],
        array $replacements = [],
        ?Throwable $previous = null,
    ): self {
        return new self(
            message: (string) trans("activity::activity/general.errors.purge.{$key}", $replacements),
            responseCode: ActivityResponseCode::InvalidPurgeCriteria,
            suggestedStatus: Response::HTTP_UNPROCESSABLE_ENTITY,
            publicContext: $publicContext,
            previous: $previous,
        );
    }
}
