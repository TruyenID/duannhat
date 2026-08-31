<?php

/**
 * ProductType Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\ProductType\Requests\ProductTypeStoreRequestBase;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * ProductTypeStoreRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class ProductTypeStoreRequest extends ProductTypeStoreRequestBase
{
    public function rules(): array
    {
        $rules = $this->schemaRules();

        // organization_id and brand_id are resolved server-side:
        // - organization_id from the authenticated user (HasOrganizationContext)
        // - brand_id from the {brandSlug} route param (ResolveBrandFromSlug
        //   middleware writes it to $request->attributes)
        // The client must NOT send these — keeping them in the rules forces
        // every caller to duplicate state that's already in the URL/session.
        unset($rules['organization_id'], $rules['brand_id']);

        // code is optional — auto-generated if empty; unique per brand
        $brandId = $this->attributes->get('brand_id');
        $rules['code'] = [
            'nullable', 'string', 'max:50',
            Rule::unique('product_types', 'code')->where('brand_id', $brandId),
        ];

        // name is now derived from translations (ja→en→vi); top-level mirror is
        // optional — translations carry the source of truth.
        $rules['name'] = ['nullable', 'string', 'max:100'];

        // 'sometimes' prevents validated() from including these as null when
        // a locale is absent from the request — avoids Astrotomic inserting a
        // translation row with name=null (NOT NULL constraint violation).
        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules[$locale] = ['sometimes', 'nullable', 'array'];
            $rules["{$locale}.name"] = ['sometimes', 'nullable', 'string', 'max:100'];
            $rules["{$locale}.description"] = ['sometimes', 'nullable', 'string'];
        }

        // defaults
        $rules['product_form'] = ['nullable', 'string', Rule::in(['physical', 'digital'])];
        $rules['has_recipe'] = ['nullable', 'boolean'];
        $rules['is_inventory_tracked'] = ['nullable', 'boolean'];
        $rules['is_active'] = ['nullable', 'boolean'];

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $ja = trim((string) $this->input('ja.name', ''));
            $en = trim((string) $this->input('en.name', ''));
            $vi = trim((string) $this->input('vi.name', ''));
            if ($ja === '' && $en === '' && $vi === '') {
                $v->errors()->add('ja.name', 'The name field is required in at least one language (ja, en, or vi).');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'product_form' => 'physical',
            'has_recipe' => true,
            'is_inventory_tracked' => true,
            'is_active' => true,
        ]);
    }
}
