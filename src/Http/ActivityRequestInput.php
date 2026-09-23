<?php

declare(strict_types=1);

namespace Nvl\Activity\Http;

use Illuminate\Http\Request;

/** Adapts supported HTTP aliases to canonical Activity Data input keys. */
final class ActivityRequestInput
{
    /**
     * @param  array<string, list<string>>  $aliases
     * @return array<string, mixed>
     */
    public static function aliased(Request $request, array $aliases): array
    {
        $input = [];

        foreach ($request->all() as $key => $value) {
            if (is_string($key)) {
                $input[$key] = $value;
            }
        }

        foreach ($aliases as $target => $candidates) {
            if (array_key_exists($target, $input)) {
                continue;
            }

            foreach ($candidates as $candidate) {
                if (array_key_exists($candidate, $input)) {
                    $input[$target] = $input[$candidate];

                    break;
                }
            }
        }

        return $input;
    }
}
