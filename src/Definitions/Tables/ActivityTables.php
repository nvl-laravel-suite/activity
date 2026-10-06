<?php

declare(strict_types=1);

namespace Nvl\Activity\Definitions\Tables;

use Nvl\Support\Config\PackageStorage;

/**
 * Defines the canonical table names owned by the Activity package.
 */
final class ActivityTables
{
    public const string ActivityLog = 'nvl_activity_log';

    /** Return one configured logical or historical package table. */
    public static function get(string $key): string
    {
        return PackageStorage::resolveTable('activity', $key);
    }

    private function __construct() {}
}
