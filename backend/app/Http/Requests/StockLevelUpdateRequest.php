<?php

/**
 * StockLevel Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\StockLevel\Requests\StockLevelUpdateRequestBase;

/**
 * StockLevelUpdateRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class StockLevelUpdateRequest extends StockLevelUpdateRequestBase
{
    public function rules(): array
    {
        $rules = [
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'max_stock' => ['nullable', 'numeric', 'min:0'],
            'alert_enabled' => ['sometimes', 'boolean'],
        ];

        // Cross-field check: min must not exceed max when both are present
        // in the same payload. Laravel's `lte:max_stock` rule treats a
        // missing or null `max_stock` as failure, which would break the
        // common case of editing min_stock alone — so guard the rule.
        if ($this->filled('max_stock') && $this->filled('min_stock')) {
            $rules['min_stock'][] = 'lte:max_stock';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'min_stock.lte' => 'Min stock must be less than or equal to max stock.',
        ];
    }
}
