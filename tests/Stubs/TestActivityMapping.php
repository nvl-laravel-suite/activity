<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Contracts\ActivityMapping;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Configurable activity mapping fixture.
 */
final class TestActivityMapping implements ActivityMapping
{
    /**
     * @param  class-string  $modelClassName
     * @param  array<string, string>  $templates
     */
    public function __construct(
        private readonly string $modelClassName,
        private readonly array $templates = [
            'consumer_event' => ':actor recorded :value for this :subject.',
        ],
    ) {}

    public function modelClass(): string
    {
        return $this->modelClassName;
    }

    public function entityLabel(): string
    {
        return 'Mapped entity';
    }

    public function subjectLabel(Model $subject): string
    {
        $name = $subject->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : 'Mapped subject';
    }

    public function logName(): string
    {
        return 'mapped';
    }

    public function logOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name'])
            ->logOnlyDirty();
    }

    public function fieldLabel(string $key): string
    {
        return 'Mapped label';
    }

    public function fieldValue(string $key, mixed $value): string
    {
        return 'Mapped value';
    }

    public function eventDisplayValue(string $event, array $properties): ?string
    {
        $context = is_array($properties['context'] ?? null) ? $properties['context'] : [];
        $value = $context['value'] ?? null;

        return $event === 'consumer_event' && is_string($value) ? $value : null;
    }

    public function eventTemplates(): array
    {
        return $this->templates;
    }
}
