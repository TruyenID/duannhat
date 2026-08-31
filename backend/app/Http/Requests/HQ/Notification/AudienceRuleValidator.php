<?php

namespace App\Http\Requests\HQ\Notification;

/**
 * Shared rule-JSON shape validator. Called from StoreAudienceRequest,
 * UpdateAudienceRequest, PreviewAudienceRequest, and the Phase D
 * BroadcastRequest. Returns a `["field" => "message"]` map keyed on the
 * sub-path under `rule.` — the form request prepends the prefix.
 *
 * Shape reference: see `schemas/Backend/Notification/NotificationAudience.yaml`
 * header comment.
 */
final class AudienceRuleValidator
{
    private const ALLOWED_TYPES = ['role', 'user', 'shop', 'brand', 'device'];

    private const ALLOWED_COMBINATORS = ['or', 'and'];

    /**
     * @param  array<string, mixed>  $rule
     * @return array<string, string>
     */
    public static function errors(array $rule): array
    {
        $errors = [];

        $combinator = $rule['combinator'] ?? 'or';
        if (! in_array($combinator, self::ALLOWED_COMBINATORS, true)) {
            $errors['combinator'] = 'combinator must be one of: '.implode(', ', self::ALLOWED_COMBINATORS);
        }

        $rules = $rule['rules'] ?? null;
        if (! is_array($rules) || $rules === []) {
            $errors['rules'] = 'rules must be a non-empty array.';

            return $errors;
        }

        foreach ($rules as $idx => $sub) {
            if (! is_array($sub)) {
                $errors["rules.{$idx}"] = 'each rule must be an object.';

                continue;
            }
            $type = $sub['type'] ?? null;
            if (! in_array($type, self::ALLOWED_TYPES, true)) {
                $errors["rules.{$idx}.type"] = 'type must be one of: '.implode(', ', self::ALLOWED_TYPES);
            }
        }

        if (isset($rule['exclude']) && ! is_array($rule['exclude'])) {
            $errors['exclude'] = 'exclude must be an array.';
        } elseif (is_array($rule['exclude'] ?? null)) {
            foreach ($rule['exclude'] as $idx => $sub) {
                if (! is_array($sub) || ! in_array($sub['type'] ?? null, self::ALLOWED_TYPES, true)) {
                    $errors["exclude.{$idx}.type"] = 'type must be one of: '.implode(', ', self::ALLOWED_TYPES);
                }
            }
        }

        return $errors;
    }
}
