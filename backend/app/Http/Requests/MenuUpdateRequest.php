<?php

/**
 * Menu Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\Menu\Requests\MenuUpdateRequestBase;
use Illuminate\Validation\Rule;

/**
 * MenuUpdateRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class MenuUpdateRequest extends MenuUpdateRequestBase
{
    public function rules(): array
    {
        $rules = parent::rules();
        $brand = $this->attributes->get('brand');
        $rules['name'] = ['sometimes', 'string', 'max:255', 'not_regex:/^\s*$/u'];
        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules["{$locale}.name"] = ["required_with:{$locale}", 'string', 'max:255', 'not_regex:/^\s*$/u'];
        }
        foreach (array_keys($this->all()) as $key) {
            if (is_string($key) && preg_match('/\A[a-z]{2}(?:-[A-Z]{2})?\z/', $key) === 1 && ! in_array($key, ['ja', 'en', 'vi'], true)) {
                $rules[$key] = ['prohibited'];
            }
        }
        $rules['branch_id'] = [
            'sometimes', 'nullable', 'string', 'uuid',
            Rule::exists('branches', 'id')->where(
                fn ($query) => $query
                    ->where('console_brand_id', $brand?->console_brand_id)
                    ->where('console_organization_id', $brand?->console_organization_id)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ),
        ];
        $rules['menu_transition_grace_minutes'] = ['sometimes', 'nullable', 'integer', 'min:0', 'max:120'];
        // Min 1 — 0 is ambiguous (not "no timeout", not "instant expire"). Max 1440 (24h) — matches HQ/shop settings endpoints.
        $rules['cart_timeout_minutes'] = ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'];
        // Optimistic concurrency token. Older clients may omit it, while the
        // HQ editor always sends the timestamp returned by the read endpoint.
        $rules['updated_at'] = ['sometimes', 'required', 'date'];

        return $rules;
    }
}
