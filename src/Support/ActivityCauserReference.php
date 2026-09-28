<?php

declare(strict_types=1);

namespace Nvl\Activity\Support;

/** Identifies the native polymorphic causer of an activity without loading a model. */
final readonly class ActivityCauserReference
{
    public string $type;

    public string|int $id;

    /** Create a validated native causer morph identity. */
    public function __construct(string $type, string|int $id)
    {
        $reference = new ActivitySubjectReference($type, $id);
        $this->type = $reference->type;
        $this->id = $reference->id;
    }
}
