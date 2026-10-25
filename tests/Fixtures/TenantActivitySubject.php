<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Traits\HasModelActivity;

/** Represents a host-owned tenant resource using real automatic Activity capture. */
final class TenantActivitySubject extends Model
{
    use HasModelActivity;

    public static int $retrievedCount = 0;

    protected $table = 'tenant_activity_subjects';

    protected $guarded = [];

    /** Count real hydrations so boundary tests detect observer side effects before admission. */
    protected static function booted(): void
    {
        self::retrieved(static function (): void {
            self::$retrievedCount++;
        });
    }

    /** @return array<int, iterable<int|string, ActivityItem>> */
    protected function mergedActivitySources(?int $limit = null): array
    {
        return [];
    }
}
