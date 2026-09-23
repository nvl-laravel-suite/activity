<?php

declare(strict_types=1);

namespace Nvl\Activity\Data;

use Illuminate\Validation\Validator;
use InvalidArgumentException;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Validated global activity index filters. */
#[MapInputName(SnakeCaseMapper::class)]
#[TypeScript]
final class ActivityLogsQueryData extends ActivityInputData
{
    /** @param array<array-key, mixed>|string|null $events */
    public function __construct(
        public readonly ?string $search = null,
        public readonly ?string $event = null,
        #[LiteralTypeScriptType('string | string[] | null')]
        public readonly string|array|null $events = null,
        public readonly ?string $causerId = null,
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectId = null,
        public readonly ?string $createdAtFrom = null,
        public readonly ?string $createdAtTo = null,
        public readonly ?int $perPage = null,
        public readonly ?int $limit = null,
        public readonly ?int $page = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', 'string', 'max:100'],
            'events' => ['nullable'],
            'causer_id' => ['nullable', 'string', 'max:100'],
            'subject_type' => ['nullable', 'string', 'max:255'],
            'subject_id' => ['nullable', 'string', 'max:100'],
            'created_at_from' => ['nullable', 'date'],
            'created_at_to' => ['nullable', 'date', 'after_or_equal:created_at_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public static function withValidator(Validator $validator): void
    {
        $validator->after(static function (Validator $validator): void {
            $input = $validator->getData();

            try {
                ActivityIndexFilter::fromInput([
                    'event' => $input['event'] ?? null,
                    'events' => $input['events'] ?? null,
                ]);
            } catch (InvalidArgumentException) {
                $validator->errors()->add('events', (string) trans('activity::activity/general.validation.invalid_events'));
            }
        });
    }

    public function filters(): ActivityIndexFilter
    {
        return ActivityIndexFilter::fromInput([
            'search' => $this->search,
            'event' => $this->event,
            'events' => $this->events,
            'causer_id' => $this->causerId,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'created_at_from' => $this->createdAtFrom,
            'created_at_to' => $this->createdAtTo,
            'per_page' => $this->perPage,
        ]);
    }
}
