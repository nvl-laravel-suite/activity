<?php

declare(strict_types=1);

namespace Nvl\Activity\Support;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Validates and canonicalizes untrusted historical identifiers for an Eloquent model key.
 */
final class ModelKeyIdentifierValidator
{
    private const string FORMAT_INTEGER = 'integer';

    private const string FORMAT_STRING = 'string';

    private const string FORMAT_ULID = 'ulid';

    private const string FORMAT_UUID = 'uuid';

    /**
     * Database integer aliases mapped to their storage width.
     *
     * @var array<string, string>
     */
    private const array INTEGER_TYPE_ALIASES = [
        'bigint' => 'bigint',
        'bigserial' => 'bigint',
        'int' => 'integer',
        'int2' => 'smallint',
        'int4' => 'integer',
        'int8' => 'bigint',
        'integer' => 'integer',
        'mediumint' => 'mediumint',
        'serial' => 'integer',
        'smallint' => 'smallint',
        'smallserial' => 'smallint',
        'tinyint' => 'tinyint',
    ];

    /**
     * Signed integer bounds represented as decimal strings for overflow-safe comparison.
     *
     * @var array<string, array{minimum: string, maximum: string}>
     */
    private const array SIGNED_INTEGER_RANGES = [
        'tinyint' => ['minimum' => '-128', 'maximum' => '127'],
        'smallint' => ['minimum' => '-32768', 'maximum' => '32767'],
        'mediumint' => ['minimum' => '-8388608', 'maximum' => '8388607'],
        'integer' => ['minimum' => '-2147483648', 'maximum' => '2147483647'],
        'bigint' => ['minimum' => '-9223372036854775808', 'maximum' => '9223372036854775807'],
    ];

    /**
     * Unsigned integer maxima represented without relying on platform integer casts.
     *
     * @var array<string, string>
     */
    private const array UNSIGNED_INTEGER_MAXIMUMS = [
        'tinyint' => '255',
        'smallint' => '65535',
        'mediumint' => '16777215',
        'integer' => '4294967295',
        'bigint' => '18446744073709551615',
    ];

    /**
     * Determine whether one identifier can be safely queried against the model key.
     */
    public function isValid(Model $model, mixed $identifier): bool
    {
        return $this->normalizeIdentifier($model, $identifier) !== null;
    }

    /**
     * Normalize one identifier to the model key's canonical query representation.
     */
    public function normalizeIdentifier(Model $model, mixed $identifier): int|string|null
    {
        return $this->normalizedIdentifiers($model, [$identifier])[0] ?? null;
    }

    /**
     * Retain and canonicalize identifiers that can be safely queried against the model key.
     *
     * @param  list<mixed>  $identifiers
     * @return list<string|int>
     */
    public function validIdentifiers(Model $model, array $identifiers): array
    {
        return array_values($this->normalizedIdentifiers($model, $identifiers));
    }

    /**
     * Canonicalize valid identifiers while preserving their original list indexes.
     *
     * @param  list<mixed>  $identifiers
     * @return array<int, string|int>
     */
    public function normalizedIdentifiers(Model $model, array $identifiers): array
    {
        $constraint = $this->resolveIdentifierConstraint($model);
        if ($constraint === null) {
            return [];
        }

        $normalizedIdentifiers = [];

        foreach ($identifiers as $index => $identifier) {
            $normalizedIdentifier = $this->normalizeForConstraint($identifier, $constraint);
            if ($normalizedIdentifier === null) {
                continue;
            }

            $normalizedIdentifiers[$index] = $normalizedIdentifier;
        }

        return $normalizedIdentifiers;
    }

    /**
     * Resolve the storage constraint used by the model primary key.
     *
     * @return array{format: string, minimum?: string, maximum?: string, cast_to_int?: bool}|null
     */
    private function resolveIdentifierConstraint(Model $model): ?array
    {
        $traits = class_uses_recursive($model);
        if (in_array(HasUuids::class, $traits, true)) {
            return ['format' => self::FORMAT_UUID];
        }

        if (in_array(HasUlids::class, $traits, true)) {
            return ['format' => self::FORMAT_ULID];
        }

        $schema = $model->getConnection()->getSchemaBuilder();
        $table = $model->getTable();
        $keyName = $model->getKeyName();

        if ($schema->hasTable($table)) {
            if (! $schema->hasColumn($table, $keyName)) {
                return null;
            }

            $columnType = Str::lower($schema->getColumnType($table, $keyName));
            $columnDefinition = Str::lower($schema->getColumnType($table, $keyName, true));

            if (in_array($columnType, ['guid', 'uuid'], true)) {
                return ['format' => self::FORMAT_UUID];
            }

            if (array_key_exists($columnType, self::INTEGER_TYPE_ALIASES)) {
                return $this->integerConstraint(
                    $model,
                    self::INTEGER_TYPE_ALIASES[$columnType],
                    $columnDefinition,
                );
            }

            return $model->getKeyType() === 'string'
                ? ['format' => self::FORMAT_STRING]
                : null;
        }

        if (in_array($model->getKeyType(), ['int', 'integer'], true)) {
            return [
                'format' => self::FORMAT_INTEGER,
                'minimum' => (string) PHP_INT_MIN,
                'maximum' => (string) PHP_INT_MAX,
                'cast_to_int' => true,
            ];
        }

        return $model->getKeyType() === 'string'
            ? ['format' => self::FORMAT_STRING]
            : null;
    }

    /**
     * Resolve database-width and Eloquent-cast-aware bounds for an integer key.
     *
     * @return array{format: string, minimum: string, maximum: string, cast_to_int: bool}
     */
    private function integerConstraint(Model $model, string $integerType, string $columnDefinition): array
    {
        $driver = $model->getConnection()->getDriverName();
        $isUnsigned = str_contains($columnDefinition, 'unsigned')
            || ($driver === 'sqlsrv' && $integerType === 'tinyint');

        if ($driver === 'sqlite') {
            $minimum = (string) PHP_INT_MIN;
            $maximum = (string) PHP_INT_MAX;
        } elseif ($isUnsigned) {
            $minimum = '0';
            $maximum = self::UNSIGNED_INTEGER_MAXIMUMS[$integerType];
        } else {
            $minimum = self::SIGNED_INTEGER_RANGES[$integerType]['minimum'];
            $maximum = self::SIGNED_INTEGER_RANGES[$integerType]['maximum'];
        }

        $castToInt = in_array($model->getKeyType(), ['int', 'integer'], true);
        if ($castToInt) {
            $minimum = $this->largerInteger($minimum, (string) PHP_INT_MIN);
            $maximum = $this->smallerInteger($maximum, (string) PHP_INT_MAX);
        }

        return [
            'format' => self::FORMAT_INTEGER,
            'minimum' => $minimum,
            'maximum' => $maximum,
            'cast_to_int' => $castToInt,
        ];
    }

    /**
     * Normalize one untrusted value against a resolved model-key constraint.
     *
     * @param  array{format: string, minimum?: string, maximum?: string, cast_to_int?: bool}  $constraint
     */
    private function normalizeForConstraint(mixed $identifier, array $constraint): int|string|null
    {
        if (! is_string($identifier) && ! is_int($identifier)) {
            return null;
        }

        return match ($constraint['format']) {
            self::FORMAT_INTEGER => $this->normalizeInteger($identifier, $constraint),
            self::FORMAT_ULID => is_string($identifier) && Str::isUlid(trim($identifier))
                ? Str::upper(trim($identifier))
                : null,
            self::FORMAT_UUID => is_string($identifier) && Str::isUuid(trim($identifier))
                ? Str::lower(trim($identifier))
                : null,
            self::FORMAT_STRING => is_string($identifier) && trim($identifier) === ''
                ? null
                : (string) $identifier,
            default => null,
        };
    }

    /**
     * Canonicalize and range-check one decimal integer without overflowing PHP.
     *
     * @param  array{format: string, minimum?: string, maximum?: string, cast_to_int?: bool}  $constraint
     */
    private function normalizeInteger(string|int $identifier, array $constraint): int|string|null
    {
        $canonical = $this->canonicalDecimal((string) $identifier);
        $minimum = $constraint['minimum'] ?? (string) PHP_INT_MIN;
        $maximum = $constraint['maximum'] ?? (string) PHP_INT_MAX;

        if ($canonical === null
            || $this->compareIntegers($canonical, $minimum) < 0
            || $this->compareIntegers($canonical, $maximum) > 0) {
            return null;
        }

        return ($constraint['cast_to_int'] ?? false)
            ? (int) $canonical
            : $canonical;
    }

    /**
     * Convert a signed decimal string to its unique no-whitespace representation.
     */
    private function canonicalDecimal(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^[+-]?\d+$/D', $value) !== 1) {
            return null;
        }

        $negative = str_starts_with($value, '-');
        $digits = ltrim($value, '+-0');
        if ($digits === '') {
            return '0';
        }

        return $negative ? "-{$digits}" : $digits;
    }

    /**
     * Compare two canonical signed decimal strings without native integer casts.
     */
    private function compareIntegers(string $left, string $right): int
    {
        $leftNegative = str_starts_with($left, '-');
        $rightNegative = str_starts_with($right, '-');

        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }

        $leftAbsolute = ltrim($left, '-');
        $rightAbsolute = ltrim($right, '-');
        $comparison = strlen($leftAbsolute) <=> strlen($rightAbsolute);

        if ($comparison === 0) {
            $comparison = $leftAbsolute <=> $rightAbsolute;
        }

        return $leftNegative ? -$comparison : $comparison;
    }

    /**
     * Return the numerically larger canonical integer string.
     */
    private function largerInteger(string $left, string $right): string
    {
        return $this->compareIntegers($left, $right) >= 0 ? $left : $right;
    }

    /**
     * Return the numerically smaller canonical integer string.
     */
    private function smallerInteger(string $left, string $right): string
    {
        return $this->compareIntegers($left, $right) <= 0 ? $left : $right;
    }
}
