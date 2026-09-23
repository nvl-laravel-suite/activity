<?php

declare(strict_types=1);

namespace Nvl\Activity\Data;

use Illuminate\Support\Str;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/** Shared translated validation and alias handling for Activity inputs. */
#[Hidden]
abstract class ActivityInputData extends Data
{
    use DataTransform;

    private const array TRANSLATED_RULES = ['after_or_equal', 'date', 'in', 'integer', 'max', 'min', 'required', 'string'];

    /** @return array<string, string> */
    public static function messages(): array
    {
        $messages = [];

        foreach (static::rules() as $attribute => $rules) {
            foreach (is_array($rules) ? $rules : [$rules] as $rule) {
                if (! is_string($rule)) {
                    continue;
                }

                $ruleName = Str::before($rule, ':');

                if (in_array($ruleName, self::TRANSLATED_RULES, true)) {
                    $messages["{$attribute}.{$ruleName}"] = (string) trans("activity::activity/general.validation.rules.{$ruleName}");
                }
            }
        }

        return $messages;
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        $attributes = [];

        foreach (array_keys(static::rules()) as $attribute) {
            if (! is_string($attribute)) {
                continue;
            }

            $key = "activity::activity/general.validation.attributes.{$attribute}";
            $translated = trans($key);

            if ($translated !== $key) {
                $attributes[$attribute] = (string) $translated;
            }
        }

        return $attributes;
    }
}
