<?php

declare(strict_types=1);

namespace Nvl\Activity\Support;

use Nvl\Activity\Data\Display\HeadlineSegment;

/**
 * Couples the flat headline text with its semantic segments from one render pass.
 */
final readonly class ResolvedHeadline
{
    /**
     * @param  array<int, HeadlineSegment>  $segments
     */
    public function __construct(
        public string $headline,
        public array $segments = [],
    ) {}
}
