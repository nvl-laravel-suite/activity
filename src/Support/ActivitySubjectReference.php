<?php

declare(strict_types=1);

namespace Nvl\Activity\Support;

use InvalidArgumentException;

/** Identifies one activity subject without requiring its Eloquent model. */
final readonly class ActivitySubjectReference
{
    public string $type;

    public string|int $id;

    /** Create one normalized, storage-compatible subject reference. */
    public function __construct(string $type, string|int $id)
    {
        if (str_contains($type, "\0") || (is_string($id) && str_contains($id, "\0"))) {
            throw new InvalidArgumentException('Activity subject references may not contain NUL bytes.');
        }

        $type = trim($type);
        $id = is_string($id) ? trim($id) : $id;

        if ($type === '' || mb_strlen($type) > 255) {
            throw new InvalidArgumentException('Activity subject types must contain between 1 and 255 characters.');
        }

        if ((is_string($id) && ($id === '' || mb_strlen($id) > 100))) {
            throw new InvalidArgumentException('Activity subject identifiers must contain between 1 and 100 characters.');
        }

        $this->type = $type;
        $this->id = $id;
    }
}
