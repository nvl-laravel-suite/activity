<?php

declare(strict_types=1);

/**
 * Alias a Spatie Activitylog v4 symbol to its v5 namespace.
 *
 * @param  string  $modernSymbol
 * @param  string  $versionFourSymbol
 * @param  callable(string): bool  $symbolExists
 */
$registerActivitylogCompatibility = static function (
    string $modernSymbol,
    string $versionFourSymbol,
    callable $symbolExists,
): void {
    if ($symbolExists($modernSymbol) || ! $symbolExists($versionFourSymbol)) {
        return;
    }

    class_alias($versionFourSymbol, $modernSymbol);
};

$registerActivitylogCompatibility(
    'Spatie\\Activitylog\\Models\\Concerns\\LogsActivity',
    'Spatie\\Activitylog\\Traits\\LogsActivity',
    trait_exists(...),
);

$registerActivitylogCompatibility(
    'Spatie\\Activitylog\\Support\\ActivityLogger',
    'Spatie\\Activitylog\\ActivityLogger',
    class_exists(...),
);

$registerActivitylogCompatibility(
    'Spatie\\Activitylog\\Support\\LogOptions',
    'Spatie\\Activitylog\\LogOptions',
    class_exists(...),
);

unset($registerActivitylogCompatibility);
