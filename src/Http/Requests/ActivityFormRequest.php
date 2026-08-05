<?php

declare(strict_types=1);

namespace Nvl\Activity\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Provides package-owned translated validation copy for Activity API requests.
 */
abstract class ActivityFormRequest extends FormRequest
{
    /**
     * Validation rules with package-owned localized messages.
     *
     * @var list<string>
     */
    private const TRANSLATED_RULES = [
        'after_or_equal',
        'date',
        'in',
        'integer',
        'max',
        'min',
        'required',
        'string',
    ];

    /**
     * Return translated validation messages for every supported request rule.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [];

        foreach ($this->rules() as $attribute => $rules) {
            foreach (is_array($rules) ? $rules : [$rules] as $rule) {
                if (! is_string($rule)) {
                    continue;
                }

                $ruleName = Str::before($rule, ':');

                if (! in_array($ruleName, self::TRANSLATED_RULES, true)) {
                    continue;
                }

                $messages["{$attribute}.{$ruleName}"] = (string) trans(
                    "activity::activity/general.validation.rules.{$ruleName}",
                );
            }
        }

        return $messages;
    }

    /**
     * Return translated field labels for every request attribute.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (array_keys($this->rules()) as $attribute) {
            $translationKey = "activity::activity/general.validation.attributes.{$attribute}";
            $translated = trans($translationKey);

            if ($translated !== $translationKey) {
                $attributes[$attribute] = (string) $translated;
            }
        }

        return $attributes;
    }

    /**
     * Return the request-specific validation contract.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    abstract public function rules(): array;
}
