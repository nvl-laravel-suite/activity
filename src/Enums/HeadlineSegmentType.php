<?php

declare(strict_types=1);

namespace Nvl\Activity\Enums;

/**
 * Defines the semantic role of one activity headline segment.
 */
enum HeadlineSegmentType: string
{
    case Text = 'text';
    case Actor = 'actor';
    case Field = 'field';
    case Value = 'value';
    case Status = 'status';
}
