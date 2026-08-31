<?php

/**
 * ProductType Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\ProductType\Requests\ProductTypeUpdateRequestBase;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * ProductTypeUpdateRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class ProductTypeUpdateRequest extends ProductTypeUpdateRequestBase
{
    public function rules(): array
    {
        $rules = $this->schemaRules();

        // organization_id and brand_id are resolved server-side — never accept from client.
        unset($rules['organization_id'], $rules['brand_id']);

        // code is immutable after creation — reject explicitly so the client gets a clear error.
        $rules['code'] = ['prohibited'];

        // All fields optional on update
        $rules['name'] = ['sometimes', 'string', 'max:100'];
        $rules['product_form'] = ['sometimes', 'string', Rule::in(['physical', 'digital'])];
        $rules['has_recipe'] = ['sometimes', 'boolean'];
        $rules['is_inventory_tracked'] = ['sometimes', 'boolean'];
        $rules['is_active'] = ['sometimes', 'boolean'];

        // 'sometimes' prevents validated() from including these as null when
        // a locale is absent from the request — avoids Astrotomic inserting a
        // translation row with name=null (NOT NULL constraint violation).
        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules[$locale] = ['sometimes', 'nullable', 'array'];
            $rules["{$locale}.name"] = ['sometimes', 'nullable', 'string', 'max:100'];
            $rules["{$locale}.description"] = ['sometimes', 'nullable', 'string'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $hasLocaleKey = $this->hasAny(['ja', 'en', 'vi']);
            if (! $hasLocaleKey) {
                return;
            }
            $ja = trim((string) $this->input('ja.name', ''));
            $en = trim((string) $this->input('en.name', ''));
            $vi = trim((string) $this->input('vi.name', ''));
            if ($ja === '' && $en === '' && $vi === '') {
                $v->errors()->add('ja.name', 'The name field is required in at least one language (ja, en, or vi).');
            }
        });
    }
}
