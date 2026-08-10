<?php

declare(strict_types=1);

namespace Nvl\Activity\Support;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Nvl\Activity\Exceptions\ActivityPurgeCriteriaException;

/**
 * Immutable filter set for activity purge maintenance flows.
 */
final readonly class ActivityPurgeCriteria
{
    /**
     * @param  list<string>  $events
     * @param  list<string>  $logNames
     * @param  list<string>  $subjectTypes
     * @param  list<string>  $subjectIds
     * @param  list<string>  $causerTypes
     * @param  list<string>  $causerIds
     */
    public function __construct(
        public ?int $days = null,
        public ?string $before = null,
        public ?string $after = null,
        public bool $systemOnly = false,
        public bool $includeImportant = false,
        public array $events = [],
        public array $logNames = [],
        public array $subjectTypes = [],
        public array $subjectIds = [],
        public array $causerTypes = [],
        public array $causerIds = [],
    ) {}

    /**
     * Build days-based purge criteria.
     */
    public static function fromDays(
        int $days,
        bool $systemOnly = false,
        bool $includeImportant = false,
    ): self {
        if ($days < 1) {
            throw ActivityPurgeCriteriaException::positiveDaysRequired();
        }

        return new self(
            days: $days,
            systemOnly: $systemOnly,
            includeImportant: $includeImportant,
        );
    }

    /**
     * Build purge criteria from artisan command options.
     *
     * @param  array<string, mixed>  $options
     */
    public static function fromConsoleOptions(array $options, bool $forceSystemOnly = false, ?int $defaultDays = null): self
    {
        $days = self::normalizeDays($options['days'] ?? null);
        $before = self::normalizeDateOption($options['before'] ?? null, 'before');
        $after = self::normalizeDateOption($options['after'] ?? null, 'after');

        if ($days === null && $before === null) {
            $days = $defaultDays;
        }

        if ($days !== null && $before !== null) {
            throw ActivityPurgeCriteriaException::mutuallyExclusiveCutoffs();
        }

        if ($days === null && $before === null) {
            throw ActivityPurgeCriteriaException::missingCutoff();
        }

        $criteria = new self(
            days: $days,
            before: $before,
            after: $after,
            systemOnly: $forceSystemOnly || (bool) ($options['system-only'] ?? false),
            includeImportant: (bool) ($options['include-important'] ?? false),
            events: self::normalizeArrayOption($options['event'] ?? []),
            logNames: self::normalizeArrayOption($options['log-name'] ?? []),
            subjectTypes: self::normalizeArrayOption($options['subject-type'] ?? []),
            subjectIds: self::normalizeArrayOption($options['subject-id'] ?? []),
            causerTypes: self::normalizeArrayOption($options['causer-type'] ?? []),
            causerIds: self::normalizeArrayOption($options['causer-id'] ?? []),
        );

        if ($criteria->afterCutoff()?->gte($criteria->cutoff())) {
            throw ActivityPurgeCriteriaException::invalidRange();
        }

        return $criteria;
    }

    /**
     * Resolve the exclusive upper cutoff for purge eligibility.
     */
    public function cutoff(): CarbonImmutable
    {
        if ($this->before !== null) {
            return CarbonImmutable::parse($this->before);
        }

        if ($this->days !== null) {
            return now()->toImmutable()->subDays($this->days);
        }

        throw ActivityPurgeCriteriaException::unresolvedCutoff();
    }

    /**
     * Resolve the inclusive lower cutoff when present.
     */
    public function afterCutoff(): ?CarbonImmutable
    {
        if ($this->after === null) {
            return null;
        }

        return CarbonImmutable::parse($this->after);
    }

    /**
     * Resolve a concise human-readable summary of the active filters.
     *
     * @return list<string>
     */
    public function summaryParts(): array
    {
        $parts = [
            (string) trans('activity::activity/general.console.criteria.before', [
                'value' => $this->cutoff()->format('Y-m-d H:i:s'),
            ]),
            (string) trans('activity::activity/general.console.criteria.system_only', [
                'value' => trans(
                    'activity::activity/general.console.boolean.'.($this->systemOnly ? 'yes' : 'no'),
                ),
            ]),
            (string) trans('activity::activity/general.console.criteria.include_important', [
                'value' => trans(
                    'activity::activity/general.console.boolean.'.($this->includeImportant ? 'yes' : 'no'),
                ),
            ]),
        ];

        $after = $this->afterCutoff();
        if ($after !== null) {
            $parts[] = (string) trans('activity::activity/general.console.criteria.after', [
                'value' => $after->format('Y-m-d H:i:s'),
            ]);
        }

        if ($this->events !== []) {
            $parts[] = (string) trans('activity::activity/general.console.criteria.events', [
                'value' => implode(', ', $this->events),
            ]);
        }

        if ($this->logNames !== []) {
            $parts[] = (string) trans('activity::activity/general.console.criteria.log_names', [
                'value' => implode(', ', $this->logNames),
            ]);
        }

        if ($this->subjectTypes !== []) {
            $parts[] = (string) trans('activity::activity/general.console.criteria.subject_types', [
                'value' => implode(', ', $this->subjectTypes),
            ]);
        }

        if ($this->subjectIds !== []) {
            $parts[] = (string) trans('activity::activity/general.console.criteria.subject_ids', [
                'value' => implode(', ', $this->subjectIds),
            ]);
        }

        if ($this->causerTypes !== []) {
            $parts[] = (string) trans('activity::activity/general.console.criteria.causer_types', [
                'value' => implode(', ', $this->causerTypes),
            ]);
        }

        if ($this->causerIds !== []) {
            $parts[] = (string) trans('activity::activity/general.console.criteria.causer_ids', [
                'value' => implode(', ', $this->causerIds),
            ]);
        }

        return $parts;
    }

    /**
     * Normalize a command date option to ISO8601.
     */
    private static function normalizeDateOption(mixed $value, string $option): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalizedValue = trim($value);
        if ($normalizedValue === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($normalizedValue)->toIso8601String();
        } catch (InvalidFormatException $exception) {
            throw ActivityPurgeCriteriaException::invalidDate($option, $exception);
        }
    }

    /**
     * Normalize a positive days option.
     */
    private static function normalizeDays(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            throw ActivityPurgeCriteriaException::positiveDaysRequired();
        }

        $days = filter_var($value, FILTER_VALIDATE_INT);

        if (! is_int($days) || $days < 1) {
            throw ActivityPurgeCriteriaException::positiveDaysRequired();
        }

        return $days;
    }

    /**
     * Normalize repeated command options to a unique string list.
     *
     * @return list<string>
     */
    private static function normalizeArrayOption(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $normalizedValues = [];

        foreach ($value as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $normalizedItem = trim((string) $item);

            if ($normalizedItem === '') {
                continue;
            }

            $normalizedValues[] = $normalizedItem;
        }

        return array_values(array_unique($normalizedValues));
    }
}
