<?php

/**
 * Menu Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\Menu\Requests\MenuStoreRequestBase;
use Illuminate\Validation\Rule;

/**
 * MenuStoreRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class MenuStoreRequest extends MenuStoreRequestBase
{
    public function rules(): array
    {
        $rules = $this->schemaRules();

        unset($rules['organization_id'], $rules['brand_id'], $rules['created_by_id'], $rules['approved_by_id'], $rules['approved_at'], $rules['rejected_by_id'], $rules['rejected_at'], $rules['rejection_reason'], $rules['last_synced_at'], $rules['master_menu_id'], $rules['priority']);

        $brand = $this->attributes->get('brand');

        $rules['name'] = ['required', 'string', 'max:255', 'not_regex:/^\s*$/u'];
        $rules['description'] = ['nullable', 'string'];
        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules["{$locale}.name"] = ["required_with:{$locale}", 'string', 'max:255', 'not_regex:/^\s*$/u'];
        }
        foreach (array_keys($this->all()) as $key) {
            if (is_string($key) && preg_match('/\A[a-z]{2}(?:-[A-Z]{2})?\z/', $key) === 1 && ! in_array($key, ['ja', 'en', 'vi'], true)) {
                $rules[$key] = ['prohibited'];
            }
        }
        $rules['branch_id'] = [
            'nullable', 'string', 'uuid',
            Rule::exists('branches', 'id')->where(
                fn ($query) => $query
                    ->where('console_brand_id', $brand?->console_brand_id)
                    ->where('console_organization_id', $brand?->console_organization_id)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ),
        ];
        $rules['valid_from'] = ['nullable', 'date'];
        $rules['valid_to'] = ['nullable', 'date', 'after_or_equal:valid_from'];
        $rules['status'] = ['nullable', 'string'];
        // #463 — optional; defaults to Both in prepareForValidation so existing
        // create flows that don't send it keep showing on both order flows.
        $rules['service_type'] = ['nullable', 'string', Rule::in(['Takeaway', 'DineIn', 'Both'])];
        $rules['is_master'] = ['nullable', 'boolean'];
        $rules['cart_timeout_minutes'] = ['nullable', 'integer', 'min:1', 'max:1440'];
        $rules['product_ids'] = ['nullable', 'array'];
        $rules['product_ids.*'] = [
            'required', 'uuid',
            Rule::exists('products', 'id')->where(
                fn ($query) => $query
                    ->where('brand_id', $this->attributes->get('brand_id'))
                    ->whereNull('deleted_at'),
            ),
        ];

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'status' => 'Draft',
            'is_master' => false,
            'service_type' => 'Both',
        ]);
    }
}
